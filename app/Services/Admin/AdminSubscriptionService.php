<?php

namespace App\Services\Admin;

use App\Models\Subscription;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\PayPalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class AdminSubscriptionService
{
    public function __construct(
        protected AdminAuditService $auditService,
        protected PayPalService $payPalService,
        protected ?StripeClient $stripeClient = null,
    ) {
        $this->stripeClient = $this->stripeClient ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Cancel an active customer subscription at period end via its payment provider.
     *
     * Ensures:
     * - Network calls are executed OUTSIDE database transactions and without holding DB locks.
     * - Retry-safe: if already cancelled locally or at the provider, synchronizes cleanly without duplicate audit logs.
     * - Provider timeouts/failures do not falsely record success.
     *
     * @throws ValidationException
     * @throws RuntimeException
     */
    public function cancelSubscription(
        User $admin,
        Subscription $subscription,
        string $reason,
        ?Request $request = null,
    ): Subscription {
        if (! in_array($admin->role, ['admin', 'super_admin'], true)) {
            abort(403, 'Unauthorized. Only Admin and Super Admin can cancel subscriptions.');
        }

        // Fast-path idempotency replay: if already cancelled locally, return immediately without duplicate audit
        if ($subscription->cancel_at_period_end && $subscription->cancelled_at !== null) {
            return $subscription;
        }

        $isStripe = ! empty($subscription->stripe_subscription_id);
        $isPayPal = ! empty($subscription->paypal_subscription_id);

        if (! $isStripe && ! $isPayPal) {
            throw ValidationException::withMessages([
                'subscription' => ['Subscription is missing provider gateway reference (Stripe or PayPal).'],
            ]);
        }

        // 1. External Gateway Dispatch (NO DB transaction or lock held)
        if ($isStripe) {
            $this->cancelStripeSubscription((string) $subscription->stripe_subscription_id);
        } elseif ($isPayPal) {
            $this->cancelPayPalSubscription((string) $subscription->paypal_subscription_id);
        }

        // 2. Local State Settle & Atomic Audit Log inside DB transaction
        return DB::transaction(function () use ($subscription, $admin, $reason, $isStripe, $request) {
            $lockedSub = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Guard against concurrent execution that completed during the network call
            if ($lockedSub->cancel_at_period_end && $lockedSub->cancelled_at !== null) {
                return $lockedSub;
            }

            $accessUntil = $lockedSub->current_period_end ?? now();

            $lockedSub->update([
                'cancel_at_period_end' => true,
                'cancelled_at' => now(),
                'end_date' => $isStripe ? $lockedSub->end_date : $accessUntil,
                'pending_plan_name' => null,
                'pending_stripe_price_id' => null,
                'pending_paypal_plan_id' => null,
                'pending_price' => null,
                'pending_monthly_credits' => null,
                'pending_plan_effective_at' => null,
            ]);

            $this->auditService->log(
                admin: $admin,
                action: 'subscription.cancelled',
                targetType: 'subscription',
                targetId: $lockedSub->id,
                reason: $reason,
                outcome: 'success',
                metadata: [
                    'provider' => $isStripe ? 'stripe' : 'paypal',
                    'provider_subscription_id' => $isStripe ? $lockedSub->stripe_subscription_id : $lockedSub->paypal_subscription_id,
                    'cancel_at_period_end' => true,
                ],
                request: $request,
            );

            return $lockedSub;
        });
    }

    /**
     * Dispatch cancellation to Stripe.
     *
     * Handles idempotency/reconciliation: if already cancelled at provider, treats as confirmed.
     *
     * @throws ValidationException
     */
    protected function cancelStripeSubscription(string $stripeSubscriptionId): void
    {
        try {
            $updated = $this->stripeClient->subscriptions->update(
                $stripeSubscriptionId,
                ['cancel_at_period_end' => true]
            );

            if (! $updated->cancel_at_period_end && $updated->status !== 'canceled') {
                throw new RuntimeException("Stripe did not confirm cancellation for subscription {$stripeSubscriptionId}");
            }
        } catch (ApiErrorException $e) {
            $message = strtolower($e->getMessage());

            // If Stripe indicates the subscription is already canceled, reconcile as success
            if (str_contains($message, 'already canceled') || str_contains($message, 'canceled subscription')) {
                return;
            }

            report($e);
            throw ValidationException::withMessages([
                'subscription' => ['Stripe cancellation failed: '.$e->getMessage()],
            ]);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'subscription' => ['Stripe cancellation failed or timed out: '.$e->getMessage()],
            ]);
        }
    }

    /**
     * Dispatch cancellation to PayPal.
     *
     * Handles idempotency/reconciliation: checks if PayPal subscription is already cancelled.
     *
     * @throws ValidationException
     */
    protected function cancelPayPalSubscription(string $paypalSubscriptionId): void
    {
        try {
            // First check if PayPal subscription is already CANCELLED
            try {
                $remote = $this->payPalService->getSubscription($paypalSubscriptionId);
                if (($remote['status'] ?? null) === 'CANCELLED') {
                    return;
                }
            } catch (\Throwable $e) {
                // If retrieval fails, proceed to attempt cancellation directly
            }

            $this->payPalService->cancelSubscription($paypalSubscriptionId);
        } catch (\Throwable $e) {
            $message = strtolower($e->getMessage());

            // If PayPal indicates already cancelled, reconcile as success
            if (str_contains($message, 'subscription is already cancelled') || str_contains($message, 'status is cancelled')) {
                return;
            }

            report($e);
            throw ValidationException::withMessages([
                'subscription' => ['PayPal cancellation failed: '.$e->getMessage()],
            ]);
        }
    }
}
