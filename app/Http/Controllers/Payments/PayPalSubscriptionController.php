<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\PayPalService;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

class PayPalSubscriptionController extends Controller
{
    public function create(Request $request, PayPalService $payPalService)
    {
        $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $user = $request->user();
        $planSlug = $request->string('plan')->toString();
        $plan = config("credit_plans.{$planSlug}");

        if(! $plan || $plan['type'] !== 'subscription') {
            return response()->json([
                'message' => 'Invalid subscription plan.',
            ], 422);
        }

        if($user->activeSubscription()) {
            return response()->json([
                'message' => 'You already have an active subscription.',
            ], 409);
        }

        $paypalPlanId = $plan['paypal_plan_id'] ?? null;

        if(! $paypalPlanId) {
            return response()->json([
                'message' => 'PayPal billing plan is not configured.',
            ], 422);
        }

        try {
            $subscription = $payPalService->createSubscription($user, $planSlug);
        } catch(RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to create PayPal subscription.',
            ], 502);
        }

        $subscriptionId = $subscription['id'] ?? null;

        if(! $subscriptionId) {
            return response()->json([
                'message' => 'PayPal did not return a subscription ID.',
            ], 502);
        }

        return response()->json([
            'subscriptionId' => $subscriptionId,
        ]);
    }

    public function confirm(Request $request, PayPalService $payPalService)
    {
        $request->validate([
            'subscription_id' => ['required', 'string'],
        ]);

        $user = $request->user();
        $subscriptionId = $request->string('subscription_id')->toString();

        try {
            $paypalSubscription = $payPalService->getSubscription($subscriptionId);
        } catch(RuntimeException $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to verify PayPal subscription.',
            ], 502);
        }

        $customId = $paypalSubscription['custom_id'] ?? null;

        [$subscriptionUserId, $planSlug] = array_pad(
            explode('|', (string) $customId, 2),
            2,
            null
        );

        if((string) $subscriptionUserId !== (string) $user->id) {
            return response()->json([
                'message' => 'PayPal subscription does not belong to this account.',
            ], 403);
        }

        $plan = $planSlug ? config("credit_plans.{$planSlug}") : null;

        if(! $plan || $plan['type'] !== 'subscription') {
            return response()->json([
                'message' => 'Unable to resolve subscription plan.',
            ], 422);
        }

        $paypalPlanId = $paypalSubscription['plan_id'] ?? null;

        if(! $paypalPlanId || $paypalPlanId !== $plan['paypal_plan_id']) {
            return response()->json([
                'message' => 'PayPal subscription plan validation failed.',
            ], 422);
        }

        $paypalStatus = $paypalSubscription['status'] ?? null;

        if($paypalStatus !== 'ACTIVE') {
            return response()->json([
                'message' => 'PayPal subscription is not active yet.',
            ], 409);
        }

        $startDate = isset($paypalSubscription['start_time'])
            ? Carbon::parse($paypalSubscription['start_time'])
            : now();

        $nextBillingTime = isset($paypalSubscription['billing_info']['next_billing_time'])
            ? Carbon::parse($paypalSubscription['billing_info']['next_billing_time'])
            : null;

        Subscription::updateOrCreate(
            [
                'paypal_subscription_id' => $subscriptionId,
            ],
            [
                'user_id' => $user->id,
                'plan_name' => $planSlug,
                'price' => $plan['amount_minor'] / 100,
                'payment_method' => 'paypal',
                'start_date' => $startDate,
                'end_date' => null,
                'status' => 'active',
                'monthly_credits' => $plan['credits'],
                'paypal_plan_id' => $paypalPlanId,
                'current_period_end' => $nextBillingTime,
                'cancelled_at' => null,
                'cancel_at_period_end' => false,
            ]
        );

        return response()->json([
            'status' => 'success',
            'redirect' => route('checkout.paypal.subscription.success', [
                'subscription_id' => $subscriptionId,
            ]),
        ]);
    }

    public function success(Request $request, PayPalService $payPalService)
    {
        $request->validate([
            'subscription_id' => ['required', 'string'],
        ]);

        $user = $request->user();
        $subscriptionId = $request->string('subscription_id')->toString();

        try {
            $paypalSubscription = $payPalService->getSubscription($subscriptionId);
        } catch(RuntimeException $e) {
            report($e);

            return redirect('/')->with('modal', 'payment_failed');
        }

        $customId = $paypalSubscription['custom_id'] ?? null;

        [$subscriptionUserId, $planSlug] = array_pad(
            explode('|', (string) $customId, 2),
            2,
            null
        );

        if((string) $subscriptionUserId !== (string) $user->id) {
            abort(403);
        }

        $plan = $planSlug ? config("credit_plans.{$planSlug}") : null;

        if(! $plan || $plan['type'] !== 'subscription') {
            return redirect('/');
        }

        if(($paypalSubscription['status'] ?? null) !== 'ACTIVE') {
            return redirect('/')->with('modal', 'payment_failed');
        }

        if(($paypalSubscription['plan_id'] ?? null) !== ($plan['paypal_plan_id'] ?? null)) {
            abort(403);
        }

        $confirmedAt = isset($paypalSubscription['status_update_time'])
            ? Carbon::parse($paypalSubscription['status_update_time'])
            : now();

        return Inertia::render('user/checkout/checkout-success', [
            'purchaseType' => 'subscription',

            'order' => [
                'number' => 'UKC-PPS-'. strtoupper(
                    substr(str_replace('I-', '', $subscriptionId), 0, 8)
                ),

                'date' => $confirmedAt->format('F j, Y'),
                'item' => $plan['label'],
                'amount' => number_format($plan['amount_minor'] / 100, 2, '.', ''),
                'currency' => strtoupper($plan['currency']),
                'cardBrand' => null,
                'cardLast4' => null,
            ],

            'creditsAvailable' => $user->fresh()->credits,
        ]);
    }

    public function changePlan(Request $request, PayPalService $payPalService)
    {
        $request->validated([
            'plan' => ['required', 'string'],
        ]);

        $user = $request->user();
        $subscription = $user->activeSubscription();

        if(! $subscription || $subscription->payment_method !== 'paypal' || ! $subscription->paypal_subscription_id) {
            return back()->with('error', 'No active PayPal susbscription found.');
        }

        $planSlug = $request->string('plan')->toString();
        $plan = config("credit_plans.{$planSlug}");

        if(! $plan || $plan['type'] !== 'subscription') {
            abort(422, 'Invalid usbscription plan.');
        }

        if($subscription->plan_name === $planSlug) {
            return back()->with('info', 'You are already subscribed to this plan.');
        }

        $payPalPlanId = $plan['paypal_plan_id'] ?? null;

        if(! $payPalPlanId) {
            return back()->with('error', 'PayPal billing plan is not configured.');
        }

        $returnUrl = route('settings.subscription.paypal.plan.approved', [
            'subscription_id' => $subscription->paypal_subscription_id,
            'plan' => $planSlug,
        ]);

        $cancelUrl = route('settings.subscription', [
            'paypal_change' => 'cancelled',
        ]);

        try{
            $revision = $payPalService->reviseSubscripiton($subscription
                ->paypal_subscription_id, $payPalPlanId, $returnUrl, $cancelUrl);
        } catch(\Throwable $e) {
            report($e);

            return back()->with('error', 'Unable to change PayPal subscription plan.');
        }

        $approvalUrl = collect($revision['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        if(! $approvalUrl) {
            return back()->with('error', 'PayPal approval URL was not returned.');
        }

        return Inertia::location($approvalUrl);
    }

    public function changePlanApproved(Request $request, PayPalService $payPalService)
    {
        $request->validated([
            'plan' => ['required', 'string'],
        ]);

        $user = $request->user();
    $subscriptionId = $request->string('subscription_id')->toString();
    $planSlug = $request->string('plan')->toString();

    $subscription = $user->subscriptions()
        ->where('paypal_subscription_id', $subscriptionId)
        ->first();

    if(! $subscription) abort(403);

    $plan = config("credit_plans.{$planSlug}");

    if(! $plan || $plan['type'] !== 'subscription') {
        abort(422, 'Invalid subscription plan.');
    }

    try {
        $paypalSubscription = $payPalService->getSubscription($subscriptionId);
    } catch(\Throwable $e) {
        report($e);

        return redirect()
            ->route('settings.subscription')
            ->with('error', 'Unable to verify PayPal plan change.');
    }

    if(
        ($paypalSubscription['plan_id'] ?? null)
        !== ($plan['paypal_plan_id'] ?? null)
    ) {
        return redirect()
            ->route('settings.subscription')
            ->with('error', 'PayPal plan change was not confirmed.');
    }

    $effectiveAt = isset($paypalSubscription['billing_info']['next_billing_time'])
        ? Carbon::parse($paypalSubscription['billing_info']['next_billing_time'])
        : $subscription->current_period_end;

    $subscription->update([
        'pending_plan_name' => $planSlug,
        'pending_paypal_plan_id' => $plan['paypal_plan_id'],
        'pending_price' => $plan['amount_minor'] / 100,
        'pending_monthly_credits' => $plan['credits'],
        'pending_plan_effective_at' => $effectiveAt,
    ]);

    return redirect()
        ->route('settings.subscription')
        ->with('success', 'Your new plan will start on the next billing cycle.');
    }

    public function cancel(Request $request, PayPalService $payPalService)
    {
        $user = $request->user();
        $subscription = $user->activeSubscription();

        if(
            ! $subscription ||
            $subscription->payment_method !== 'paypal' ||
            ! $subscription->paypal_subscription_id
        ) {
            return back()->with('error', 'No active PayPal subscription found.');
        }

        try {
            $payPalService->cancelSubscription(
                $subscription->paypal_subscription_id
            );
        } catch(\Throwable $e) {
            report($e);

            return back()->with('error', 'Unable to cancel PayPal subscription.');
        }

        $accessUntil = $subscription->current_period_end ?? now();

        $subscription->update([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
            'end_date' => $accessUntil,
        ]);

        return back()->with(
            'success',
            'Your subscription has been cancelled. Access remains available until the end of the current billing period.'
        );
    }
}