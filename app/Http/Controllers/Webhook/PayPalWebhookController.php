<?php

namespace App\Http\Controllers\Webhook;

use App\Exceptions\PayPalCaptureDeniedException;
use App\Exceptions\PayPalCapturePendingException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPayPalOrderCapture;
use App\Jobs\SendTelegramSaleNotification;
use App\Models\CreditCheckoutAttempt;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\AdminRefundReconciliationService;
use App\Services\CreditCaptureService;
use App\Services\CreditService;
use App\Services\GuestReportPaymentService;
use App\Services\PayPalService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayPalWebhookController extends Controller
{
    public function handle(
        Request $request,
        PayPalService $payPalService,
        CreditService $creditService,
        AdminRefundReconciliationService $reconciliationService,
        GuestReportPaymentService $guestReportPaymentService,
        CreditCaptureService $captureService,
    ): JsonResponse {
        if (! $payPalService->verifyWebhook($request)) {
            Log::warning('Invalid PayPal webhook signature.');

            return response()->json([
                'message' => 'Invalid webhook signature',
            ], 400);
        }

        $event = $request->json()->all();
        $eventId = (string) ($event['id'] ?? '');
        $eventType = (string) ($event['event_type'] ?? '');

        if ($eventId !== '') {
        $dedupResult = $reconciliationService->executeWithDeduplication(
                provider: 'paypal',
                eventId: $eventId,
                eventType: $eventType,
                callback: function () use (
                    $event,
                    $eventType,
                    $payPalService,
                    $creditService,
                    $reconciliationService,
                    $guestReportPaymentService,
                    $captureService,
                ) {
                    $this->processEvent(
                        $event,
                        $eventType,
                        $payPalService,
                        $creditService,
                        $reconciliationService,
                        $guestReportPaymentService,
                        $captureService,
                    );
                },
                payloadSummary: [
                    'event_type' => $eventType,
                    'resource_id' => $event['resource']['id'] ?? null,
                ],
            );

            if (! $dedupResult['processed']) {
                return response()->json(['received' => true]);
            }
        } else {
            $this->processEvent(
                $event,
                $eventType,
                $payPalService,
                $creditService,
                $reconciliationService,
                $guestReportPaymentService,
                $captureService,
            );
        }

        return response()->json([
            'received' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function processEvent(
        array $event,
        string $eventType,
        PayPalService $payPalService,
        CreditService $creditService,
        AdminRefundReconciliationService $reconciliationService,
        GuestReportPaymentService $guestReportPaymentService,
        CreditCaptureService $captureService,
    ): void {
        match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->handleOneTimePayment(
                $event,
                $payPalService,
                $captureService,
                $guestReportPaymentService,
            ),

            // CHECKOUT.ORDER.APPROVED: buyer approved payment at provider.
            // Dispatch durable queued capture job to recover payments if buyer closes browser.
            'CHECKOUT.ORDER.APPROVED' => $this->handleOrderApproved($event),

            // CHECKOUT.PAYMENT-APPROVAL.REVERSED: buyer approval was reversed before capture.
            'CHECKOUT.PAYMENT-APPROVAL.REVERSED' => $this->handleApprovalReversed($event),

            // PAYMENT.CAPTURE.PENDING: capture is pending (e.g. eCheck, risk review). No credits granted.
            'PAYMENT.CAPTURE.PENDING' => $this->handleCapturePending($event),

            // PAYMENT.CAPTURE.DENIED: capture was denied by provider. No credits granted.
            'PAYMENT.CAPTURE.DENIED' => $this->handleCaptureDenied($event),

            'PAYMENT.SALE.COMPLETED' => $this->handleSubscriptionPayment(
                $event,
                $payPalService,
                $creditService
            ),

            'PAYMENT.CAPTURE.REFUNDED' => $reconciliationService->handlePayPalRefundWebhook($event),

            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->handleSubscriptionActivated($event),
            'BILLING.SUBSCRIPTION.CANCELLED' => $this->handleSubscriptionCancelled($event),
            'BILLING.SUBSCRIPTION.SUSPENDED' => $this->handleSubscriptionSuspended($event),
            'BILLING.SUBSCRIPTION.EXPIRED' => $this->handleSubscriptionExpired($event),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->handleSubscriptionPaymentFailed($event),
            default => null,
        };
    }

    /**
     * Handle PAYMENT.CAPTURE.COMPLETED for one-time credit purchases and guest report payments.
     *
     * @param  array<string, mixed>  $event
     */
    private function handleOneTimePayment(
        array $event,
        PayPalService $payPalService,
        CreditCaptureService $captureService,
        GuestReportPaymentService $guestReportPaymentService,
    ): void {
        $captureResource = $event['resource'] ?? null;

        if (! $captureResource) {
            return;
        }

        $captureId = (string) ($captureResource['id'] ?? '');
        $orderId = (string) ($captureResource['supplementary_data']['related_ids']['order_id'] ?? '');

        if ($captureId === '' || $orderId === '') {
            Log::warning('PayPal webhook missing capture/order ID', [
                'event_id' => $event['id'] ?? null,
            ]);

            return;
        }

        // Verify the nested capture status is COMPLETED before any fulfillment.
        // A PENDING capture must NOT grant credits.
        if (($captureResource['status'] ?? null) !== 'COMPLETED') {
            Log::info('PayPal PAYMENT.CAPTURE.COMPLETED webhook: capture status is not COMPLETED, skipping.', [
                'event_id' => $event['id'] ?? null,
                'capture_id' => $captureId,
                'capture_status' => $captureResource['status'] ?? null,
            ]);

            return;
        }

        $order = $payPalService->getOrder($orderId);
        $purchaseUnit = $order['purchase_units'][0] ?? null;

        if (! $purchaseUnit) {
            throw new \RuntimeException('Invalid PayPal order: missing purchase_unit.');
        }

        // ── Guest report purchases ────────────────────────────────────────────
        $customId = (string) ($purchaseUnit['custom_id'] ?? '');

        if (str_starts_with($customId, 'guest-report|')) {
            $orderCaptureId = data_get($order, 'purchase_units.0.payments.captures.0.id');

            if (
                ! is_string($orderCaptureId)
                || ! hash_equals($captureId, $orderCaptureId)
            ) {
                Log::critical('PayPal guest report webhook capture ID mismatch.', [
                    'event_id' => $event['id'] ?? null,
                    'order_id' => $orderId,
                    'event_capture_id' => $captureId,
                    'order_capture_id' => $orderCaptureId,
                ]);

                throw new \RuntimeException('PayPal webhook capture does not match the order capture.');
            }

            $guestReportPaymentService->handlePayPalGuestOrder($order);

            return;
        }

        // ── Authenticated credit purchase — delegate to CreditCaptureService ─
        // The service handles both snapshot-based (Phase 1+ attempt) and
        // config-based (legacy) fulfillment paths.
        $captureService->fulfill(
            order: $order,
            capture: $captureResource,
            captureId: $captureId,
            orderId: $orderId,
        );
    }

    /**
     * Handle CHECKOUT.ORDER.APPROVED: buyer approved payment at provider.
     * Dispatches durable background capture job through unified CreditCaptureService.
     * This recovers payments asynchronously when the buyer approves but closes the browser before frontend capture.
     *
     * @param  array<string, mixed>  $event
     */
    private function handleOrderApproved(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $orderId = (string) ($resource['id'] ?? '');

        Log::info('PayPal CHECKOUT.ORDER.APPROVED received. Scheduling asynchronous capture.', [
            'event_id' => $event['id'] ?? null,
            'order_id' => $orderId,
        ]);

        if ($orderId === '') {
            return;
        }

        // Persist intermediate approved status if attempt is still in active pre-capture state
        CreditCheckoutAttempt::query()
            ->where('provider_order_id', $orderId)
            ->whereNotIn('status', [
                CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
                CreditCheckoutAttempt::STATUS_CAPTURING,
                CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE,
            ])
            ->update(['status' => CreditCheckoutAttempt::STATUS_PROVIDER_APPROVED]);

        // Dispatch background job to perform the capture asynchronously.
        // If the queue dispatch fails (e.g. database/redis failure), an exception is thrown,
        // causing executeWithDeduplication to mark the event FAILED rather than COMPLETED,
        // ensuring PayPal will safely retry the webhook delivery.
        ProcessPayPalOrderCapture::dispatch($orderId, $event['id'] ?? null);
    }

    /**
     * Handle CHECKOUT.PAYMENT-APPROVAL.REVERSED: buyer approval was reversed before capture.
     * Log and update attempt state. Never downgrade a completed attempt.
     *
     * @param  array<string, mixed>  $event
     */
    private function handleApprovalReversed(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $orderId = (string) ($resource['id'] ?? '');

        Log::info('PayPal CHECKOUT.PAYMENT-APPROVAL.REVERSED received.', [
            'event_id' => $event['id'] ?? null,
            'order_id' => $orderId,
        ]);

        if ($orderId === '') {
            return;
        }

        // Update attempt to provider_created if it is still in an active non-captured state.
        // Never downgrade CAPTURE_COMPLETED, CAPTURING, or UNRECONCILED_STALE.
        CreditCheckoutAttempt::query()
            ->where('provider_order_id', $orderId)
            ->whereNotIn('status', [
                CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
                CreditCheckoutAttempt::STATUS_CAPTURING,
                CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE,
            ])
            ->update(['status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED]);
    }

    /**
     * Handle PAYMENT.CAPTURE.PENDING: capture is pending (eCheck, risk review).
     * Strictly DO NOT grant credits. Update attempt status to capture_pending.
     *
     * @param  array<string, mixed>  $event
     */
    private function handleCapturePending(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $captureId = (string) ($resource['id'] ?? '');
        $orderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');

        Log::info('PayPal PAYMENT.CAPTURE.PENDING received. Credits withheld until completion.', [
            'event_id' => $event['id'] ?? null,
            'capture_id' => $captureId,
            'order_id' => $orderId,
        ]);

        if ($orderId === '') {
            return;
        }

        CreditCheckoutAttempt::query()
            ->where('provider_order_id', $orderId)
            ->whereNotIn('status', [
                CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
                CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE,
            ])
            ->update([
                'status' => CreditCheckoutAttempt::STATUS_CAPTURE_PENDING,
                'provider_capture_id' => $captureId !== '' ? $captureId : null,
            ]);
    }

    /**
     * Handle PAYMENT.CAPTURE.DENIED: capture was denied by provider.
     * Strictly DO NOT grant credits. Update attempt status to terminal_failed.
     *
     * @param  array<string, mixed>  $event
     */
    private function handleCaptureDenied(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $captureId = (string) ($resource['id'] ?? '');
        $orderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');

        Log::warning('PayPal PAYMENT.CAPTURE.DENIED received. Payment denied by provider.', [
            'event_id' => $event['id'] ?? null,
            'capture_id' => $captureId,
            'order_id' => $orderId,
        ]);

        if ($orderId === '') {
            return;
        }

        CreditCheckoutAttempt::query()
            ->where('provider_order_id', $orderId)
            ->whereNotIn('status', [
                CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
                CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE,
            ])
            ->update([
                'status' => CreditCheckoutAttempt::STATUS_TERMINAL_FAILED,
                'failure_reason' => 'PayPal payment capture was denied by provider.',
            ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleSubscriptionPayment(array $event, PayPalService $payPalService, CreditService $creditService): void
    {
        $sale = $event['resource'] ?? null;
        if (! $sale) {
            return;
        }

        $saleId = $sale['id'] ?? null;
        $subscriptionId = $sale['billing_agreement_id'] ?? $sale['billing_agreement']['id'] ?? null;

        if (! $saleId || ! $subscriptionId) {
            Log::warning('PayPal subscription payment missing sale/subscription ID', [
                'event_id' => $event['id'] ?? null,
                'sale_id' => $saleId,
            ]);

            return;
        }

        $paypalSubscription = $payPalService->getSubscription($subscriptionId);
        $customId = $paypalSubscription['custom_id'] ?? null;

        [$userId] = array_pad(explode('|', (string) $customId, 2), 2, null);

        if (! $userId) {
            throw new \RuntimeException('Unable to resolve PayPal subscription');
        }

        $user = User::whereKey($userId)->first();

        if (! $user) {
            throw new \RuntimeException('Unable to resolve PayPal subscription.');
        }

        $paypalPlanId = $paypalSubscription['plan_id'] ?? null;

        if (! $paypalPlanId) {
            throw new \RuntimeException('PayPal subscription plan ID was not returned.');
        }

        /** @var array<string, array<string, mixed>> $creditPlans */
        $creditPlans = (array) config('credit_plans', []);

        $planSlug = collect($creditPlans)->search(
            fn (array $plan) => ($plan['type'] ?? null) === 'subscription'
                && ($plan['paypal_plan_id'] ?? null) === $paypalPlanId
        );

        if ($planSlug === false) {
            throw new \RuntimeException('Unable to resolve PayPal subscription plan.');
        }

        $plan = config("credit_plans.{$planSlug}");

        $saleAmount = $sale['amount']['total'] ?? $sale['amount']['value'] ?? null;
        $saleCurrency = strtoupper($sale['amount']['currency'] ?? $sale['amount']['currency_code'] ?? '');

        $expectedAmount = number_format($plan['amount_minor'] / 100, 2, '.', '');
        $expectedCurrency = strtoupper($plan['currency']);

        if ($saleAmount !== $expectedAmount || $saleCurrency !== $expectedCurrency) {
            Log::warning('PayPal subscription payment amount mismatch', [
                'sale_id' => $saleId,
                'subscription_id' => $subscriptionId,
                'plan' => $planSlug,
                'expected_amount' => $expectedAmount,
                'received_amount' => $saleAmount,
                'expected_currency' => $expectedCurrency,
                'received_currency' => $saleCurrency,
            ]);

            throw new \RuntimeException('PayPal subscription payment amount mismatch.');
        }

        $creditService->grantCredits(
            user: $user,
            amount: (int) $plan['credits'],
            type: 'subscription',
            referenceId: $saleId,
            idempotencyKey: $saleId,
            description: "PayPal subscription payment: {$saleId}",
        );

        $paidAt = isset($sale['create_time'])
            ? Carbon::parse($sale['create_time'])
            : now();

        $transaction = Transaction::updateOrCreate(
            [
                'payment_gateway_ref' => $saleId,
            ],
            [
                'user_id' => $user->id,
                'invoice_id' => 'UKC-PPS-'.strtoupper(substr($saleId, 0, 8)),
                'currency' => $saleCurrency,
                'amount' => $saleAmount,
                'type' => 'payment',
                'category' => 'subscription',
                'payment_method' => 'paypal',
                'description' => $plan['label'],
                'status' => 'success',
                'paid_at' => $paidAt,
            ]
        );

        if ($transaction->wasRecentlyCreated && (float) $transaction->amount > 0) {
            $amount = number_format(
                (float) $transaction->amount,
                2,
                '.',
                ','
            );

            SendTelegramSaleNotification::dispatch(
                'Subscription payment',
                'PayPal',
                (string) $transaction->payment_gateway_ref,
                strtoupper((string) $transaction->currency).' '.$amount,
            )->afterCommit();
        }

        $nextBillingTime = isset($paypalSubscription['billing_info']['next_billing_time'])
            ? Carbon::parse($paypalSubscription['billing_info']['next_billing_time'])
            : null;

        $existingSub = Subscription::where('paypal_subscription_id', $subscriptionId)->first();
        $isCancelled = $existingSub && ($existingSub->cancel_at_period_end || $existingSub->cancelled_at !== null);

        Subscription::updateOrCreate(
            [
                'paypal_subscription_id' => $subscriptionId,
            ],
            [
                'user_id' => $user->id,
                'plan_name' => $planSlug,
                'price' => $plan['amount_minor'] / 100,
                'monthly_credits' => $plan['credits'],
                'paypal_plan_id' => $paypalPlanId,

                'payment_method' => 'paypal',
                'start_date' => isset($paypalSubscription['start_time'])
                    ? Carbon::parse($paypalSubscription['start_time'])
                    : now(),
                'end_date' => $isCancelled ? $existingSub->end_date : null,
                'status' => 'active',
                'current_period_end' => $nextBillingTime,
                'cancelled_at' => $isCancelled ? $existingSub->cancelled_at : null,
                'cancel_at_period_end' => $isCancelled ? (bool) $existingSub->cancel_at_period_end : false,

                'pending_plan_name' => null,
                'pending_paypal_plan_id' => null,
                'pending_price' => null,
                'pending_monthly_credits' => null,
                'pending_plan_effective_at' => null,
            ]
        );

        Log::info('PayPal subscription credits granted', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionId,
            'sale_id' => $saleId,
            'plan' => $planSlug,
            'credits' => $plan['credits'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleSubscriptionActivated(array $event): void
    {
        $resource = $event['resource'] ?? [];
        $subscriptionId = $resource['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        Subscription::where('paypal_subscription_id', $subscriptionId)->update([
            'status' => 'active',
            'cancelled_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleSubscriptionCancelled(array $event): void
    {
        $subscriptionId = $event['resource']['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        $subscription = Subscription::where(
            'paypal_subscription_id',
            $subscriptionId
        )->first();

        if (! $subscription) {
            return;
        }

        if (
            $subscription->current_period_end &&
            $subscription->current_period_end->isFuture()
        ) {
            $subscription->update([
                'cancel_at_period_end' => true,
                'cancelled_at' => $subscription->cancelled_at ?: now(),
                'end_date' => $subscription->current_period_end,

                'pending_plan_name' => null,
                'pending_paypal_plan_id' => null,
                'pending_price' => null,
                'pending_monthly_credits' => null,
                'pending_plan_effective_at' => null,
            ]);

            return;
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancel_at_period_end' => false,
            'cancelled_at' => $subscription->cancelled_at ?: now(),
            'end_date' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleSubscriptionSuspended(array $event): void
    {
        $subscriptionId = $event['resource']['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        Subscription::where('paypal_subscription_id', $subscriptionId)->update([
            'status' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleSubscriptionExpired(array $event): void
    {
        $subscriptionId = $event['resource']['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        Subscription::where('paypal_subscription_id', $subscriptionId)->update([
            'status' => 'expired',
            'end_date' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function handleSubscriptionPaymentFailed(array $event): void
    {
        $subscriptionId = $event['resource']['id'] ?? null;

        if (! $subscriptionId) {
            return;
        }

        Subscription::where('paypal_subscription_id', $subscriptionId)->update([
            'status' => 'pending',
        ]);

        Log::warning('PayPal subscription payment failed', [
            'subscription_id' => $subscriptionId,
        ]);
    }
}
