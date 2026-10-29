<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelSubscriptionRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\AdminSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSubscriptionController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));
        $status = $request->input('status', 'all');
        $provider = $request->input('provider', 'all');

        $query = Subscription::with('user')->latest('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($provider !== 'all') {
            if ($provider === 'stripe') {
                $query->whereNotNull('stripe_subscription_id');
            } elseif ($provider === 'paypal') {
                $query->whereNotNull('paypal_subscription_id');
            }
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('plan_name', 'like', "%{$search}%")
                    ->orWhere('stripe_subscription_id', 'like', "%{$search}%")
                    ->orWhere('paypal_subscription_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $subscriptions = $query->paginate(15)->through(function ($sub) {
            $gateway = $sub->stripe_subscription_id ? 'Stripe' : ($sub->paypal_subscription_id ? 'PayPal' : ($sub->payment_method ?: 'Direct'));

            return [
                'id' => $sub->id,
                'customer_id' => $sub->user_id,
                'customer_name' => $sub->user?->name ?: 'Customer #'.$sub->user_id,
                'customer_email' => $sub->user?->email ?: '-',
                'plan_name' => $sub->plan_name ?: 'Standard Plan',
                'price' => (float) $sub->price,
                'currency' => 'GBP',
                'status' => $sub->status,
                'gateway' => $gateway,
                'provider_subscription_id' => $sub->stripe_subscription_id ?: $sub->paypal_subscription_id ?: '-',
                'monthly_credits' => $sub->monthly_credits ?? 0,
                'start_date' => $sub->start_date?->toDateString(),
                'end_date' => $sub->end_date?->toDateString(),
                'current_period_end' => $sub->current_period_end?->toDateString(),
                'cancel_at_period_end' => (bool) $sub->cancel_at_period_end,
                'cancelled_at' => $sub->cancelled_at?->toIso8601String(),
                'pending_plan_name' => $sub->pending_plan_name,
                'pending_plan_effective_at' => $sub->pending_plan_effective_at?->toIso8601String(),
                'created_at' => $sub->created_at?->toIso8601String(),
            ];
        });

        return Inertia::render('admin/subscriptions', [
            'subscriptions' => $subscriptions,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'provider' => $provider,
            ],
        ]);
    }

    /**
     * Cancel an active customer subscription at period end via payment provider.
     */
    public function cancel(
        CancelSubscriptionRequest $request,
        Subscription $subscription,
        AdminSubscriptionService $subscriptionService,
    ): RedirectResponse|JsonResponse {
        $admin = $request->user();
        assert($admin instanceof User);

        $subscriptionService->cancelSubscription(
            admin: $admin,
            subscription: $subscription,
            reason: (string) $request->validated('reason'),
            request: $request,
        );

        $msg = "Subscription #{$subscription->id} has been scheduled for cancellation at period end.";

        if ($request->wantsJson()) {
            return response()->json([
                'status' => 'success',
                'message' => $msg,
                'subscription' => $subscription->fresh(),
            ], 200);
        }

        return back()->with('success', $msg);
    }
}
