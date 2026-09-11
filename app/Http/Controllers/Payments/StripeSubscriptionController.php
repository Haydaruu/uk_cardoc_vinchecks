<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class StripeSubscriptionController extends Controller
{
    public function createIntent(Request $request)
    {
        $request->validate([
            'plan' => [
                'required',
                'string',
            ],
        ]);

        $user = $request->user();

        $planSlug = $request->string('plan')->toString();

        $plan = config("credit_plans.{$planSlug}");

        if (!$plan || $plan['type'] !== 'subscription') {
            return response()->json([
                'message' =>
                    'Invalid subscription plan.',
            ], 422);
        }

        /*
         * Direct URL protection.
         *
         * Pricing page already sends
         * existing members through
         * Change Plan, but backend
         * must still enforce this.
         */
        if ($user->activeSubscription()) {
            return response()->json([
                'message' =>
                    'You already have an active subscription.',
            ], 409);
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        /*
         * Subscription needs
         * a Stripe Customer.
         */
        if (!$user->stripe_customer_id) {
            try {
                $customer = $stripe
                    ->customers
                    ->create([
                        'email' => $user->email,
                        'name' => $user->name,
                        'metadata' => [
                            'user_id' =>
                                (string) $user->id,
                        ],
                    ]);
            } catch (ApiErrorException $e) {
                report($e);

                return response()->json([
                    'message' =>
                        'Unable to create Stripe customer.',
                ], 502);
            }

            $user->update(['stripe_customer_id' => $customer->id,]);
        }

        try {
            $subscription = $stripe
                ->subscriptions
                ->create([
                    'customer' =>
                        $user->stripe_customer_id,

                    'items' => [
                        [
                            'price' => $plan['price_id'],
                            'quantity' => 1,
                        ],
                    ],

                    'payment_behavior' => 'default_incomplete',

                    'payment_settings' => [
                        'payment_method_types' => ['card',],
                        'save_default_payment_method' => 'on_subscription',
                    ],

                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'plan' => $planSlug,
                        'credits' => (string) $plan['credits'],
                    ],

                    'expand' => [
                        'latest_invoice.confirmation_secret',
                    ],
                ]);
        } catch (ApiErrorException $e) {
            report($e);

            return response()->json([
                'message' =>
                    'Unable to prepare subscription payment.',
            ], 502);
        }

        $clientSecret = $subscription->latest_invoice?->confirmation_secret?->client_secret;

        if (!$clientSecret) {
            return response()->json([
                'message' =>
                    'Stripe did not return a payment client secret.',
            ], 502);
        }

        return response()->json([
            'clientSecret' => $clientSecret,
            'subscriptionId' => $subscription->id,
        ]);
    }
    public function checkout(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'plan' => ['nullable', 'string'],
        ]);

        $planSlug = $request->string('plan', 'premium-monthly')->toString();

        $plan = config("credit_plans.{$planSlug}");

        if (!$plan || $plan['type'] !== 'subscription') {
            abort(422, 'Invalid subscription plan. ');
        }

        $existingSubscription = $user->subscriptions()
            ->whereIn('status', ['active', 'pending'])
            ->latest()
            ->first();

        if ($existingSubscription) {
            return back()->with(
                'error',
                ' You already have an active subscription. '
            );
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        if (!$user->stripe_customer_id) {
            $customer = $stripe->customers->create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => (string) $user->id,
                ],
            ]);

            $user->update([
                'stripe_customer_id' => $customer->id,
            ]);
        }

        $session = $stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $user->stripe_customer_id,
            'line_items' => [
                [
                    'price' => $plan['price_id'],
                    'quantity' => 1,
                ],
            ],

            'subscription_data' => [
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'plan' => $planSlug,
                    'credits' => (string) $plan['credits'],
                ],
            ],

            'metadata' => [
                'user_id' => (string) $user->id,
                'plan' => $planSlug,
                'credits' => (string) $plan['credits'],
            ],

            'success_url' =>
                route('settings.subscription')
                . '?checkout=success',

            'cancel_url' =>
                route('settings.subscription')
                . '?checkout=cancelled',
        ]);

        return Inertia::location($session->url);
    }

    public function success(Request $request)
    {
        $request->validate([
            'subscription_id' => [
                'required',
                'string',
            ],
        ]);

        $user = $request->user();

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            $stripeSubscription =
                $stripe->subscriptions->retrieve(
                    $request
                        ->string('subscription_id')
                        ->toString(),
                    [
                        'expand' => [
                            'latest_invoice',
                            'default_payment_method',
                        ],
                    ]
                );
        } catch (ApiErrorException $e) {
            report($e);

            return redirect('/')
                ->with(
                    'modal',
                    'payment_failed'
                );
        }


        $subscriptionUserId =
            $stripeSubscription
                ->metadata
                ->user_id ?? null;

        if (
            (string) $subscriptionUserId
            !== (string) $user->id
        ) {
            abort(403);
        }

        $planSlug =
            $stripeSubscription
                ->metadata
                ->plan ?? null;

        $plan = $planSlug
            ? config(
                "credit_plans.{$planSlug}"
            )
            : null;

        if (!$plan || $plan['type'] !== 'subscription') {
            return redirect('/')->with('modal', 'payment_failed');
        }


        if (!in_array($stripeSubscription->status, ['active', 'trialing',], true)) {
            return redirect('/')->with('modal', 'payment_failed');
        }

        $invoice =
            $stripeSubscription
                ->latest_invoice;

        if (!$invoice) {
            return redirect('/')->with('modal', 'payment_failed');
        }

        $paidAt = $invoice->status_transitions?->paid_at ?? $invoice->created ?? time();

        $paymentMethod = $stripeSubscription->default_payment_method;

        $card = $paymentMethod?->card;

        return Inertia::render(
            'user/checkout/checkout-success',
            [
                'purchaseType' => 'subscription',
                'order' => [
                    'number' => 'UKC-SUB-' . strtoupper(substr($invoice->id, 3, 8)),
                    'date' => date('F j, Y', $paidAt),
                    'item' => $plan['label'],
                    'amount' => number_format($invoice->amount_paid / 100, 2),
                    'currency' => strtoupper($invoice->currency),
                    'cardBrand' => $card?->brand,
                    'cardLast4' => $card?->last4,
                ],

                'creditsAvailable' => $user->fresh()->credits,
            ]
        );
    }

    public function cancel(Request $request)
    {
        $user = $request->user();
        $subscription = $user->activeSubscription();

        if (!$subscription || !$subscription->stripe_subscription_id) {
            return back()->with(
                'error',
                'No active subscription found.'
            );
        }
        $stripe = new StripeClient(config('services.stripe.secret'));

        $stripe->subscriptions->update(
            $subscription->stripe_subscription_id,
            [
                'cancel_at_period_end' => true
            ]
        );

        return back()->with(
            'success',
            'Your subscription will be cancelled at the end of the current billing period.'
        );
    }

    public function changePlan(Request $request)
    {
        $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $user = $request->user();

        $subscription = $user->activeSubscription();

        if (!$subscription || !$subscription->stripe_subscription_id) {
            return back()->with(
                'error',
                'No active subscription found.'
            );
        }

        $planSlug = $request->string('plan')->toString();
        $plan = config("credit_plans.{$planSlug}");

        if (!$plan || $plan['type'] !== 'subscription') {
            abort(422, 'Invalid subscription plan.');
        }

        if ($subscription->plan_name === $planSlug) {
            return back()->with(
                'info',
                'You are already subscribed to this plan.'
            );
        }

        $stripe = new StripeClient(config('services.stripe.secret'));

        $stripeSubscription = $stripe->subscriptions->retrieve($subscription->stripe_subscription_id);

        $item = $stripeSubscription->items->data[0] ?? null;

        if (!$item) {
            abort(422, 'Subscription item not found.');
        }

        $updateSubscription = $stripe->subscriptions->update(
            $subscription->stripe_subscription_id,
            [
                'items' => [
                    [
                        'id' => $item->id,
                        'price' => $plan['price_id'],
                    ],
                ],

                'proration_behavior' => 'none',

                'metadata' => [
                    'user_id' => (string) $user->id,
                    'plan' => $planSlug,
                    'credits' => (string) $plan['credits'],
                ],
            ]
        );

        $stripePrice = $stripe->prices->retrieve($plan['price_id']);

        $effectiveAt = $item?->current_period_end ? date('Y-m-d H:i:s', $item->current_period_end) : $subscription->current_period_end;

        $subscription->update([
            'pending_plan_name' => $planSlug,
            'pending_stripe_price_id' => $plan['price_id'],
            'pending_price' => $stripePrice->unit_amount / 100,
            'pending_monthly_credits' => $plan['credits'],
            'pending_plan_effective_at' => $effectiveAt,
        ]);

        return back()->with(
            'success',
            'Your subscription plan has been updated successfully.'
        );
    }
}
