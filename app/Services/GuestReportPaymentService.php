<?php

namespace App\Services;

use App\Jobs\ProcessVehicleCheck;
use App\Jobs\SendTelegramSaleNotification;
use App\Models\GuestReportPurchase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GuestReportPaymentService
{
    /**
     * Create a new class instance.
     */
    public function handleCheckoutSession(object $session, string $eventType): bool
    {
        $metadata = $session->metadata ?? null;

        if (($metadata->purpose ?? null) !== 'guest_report_purchase') {
            throw new RuntimeException('This is not a guest report purchase session.');
        }

        $metadata = $session->metadata ?? null;

        if (($metadata->purpose ?? null) !== 'guest_report_purchase') {
            throw new RuntimeException('This is not a guest report purchase session.');
        }

        if (($session->mode ?? null) !== 'payment') {
            throw new RuntimeException('Invalid guest report checkout mode.');
        }

        $checkoutReference = $metadata->guest_report_reference ?? null;

        $reportId = filter_var(
            $metadata->report_id ?? null,
            FILTER_VALIDATE_INT,
        );

        if (! $reportId || $reportId < 1) {
            throw new RuntimeException('Missing report ID in guest checkout metadata.');
        }

        $sessionId = $session->id ?? null;

        if (! is_string($sessionId) || $sessionId === '') {
            throw new RuntimeException('Missing Stripe Checkout Session ID.');
        }

        $clientReferenceId = $session->client_reference_id ?? null;

        if (! is_string($clientReferenceId) || $clientReferenceId === '') {
            throw new RuntimeException('Missing Stripe Checkout client reference.');
        }

        if (
            is_string($checkoutReference)
            && preg_match(
                '/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/i',
                $checkoutReference,
            ) === 1
        ) {
            // Current checkout: UUID is the primary payment identity.
            if (! hash_equals($checkoutReference, $clientReferenceId)) {
                throw new RuntimeException(
                    'Stripe Checkout Session reference does not match metadata.',
                );
            }

            $purchase = GuestReportPurchase::query()
                ->where('checkout_reference', $checkoutReference)
                ->firstOrFail();
        } elseif ($checkoutReference === null) {
            // Legacy checkout: resolve using the original numeric purchase ID.
            $legacyPurchaseId = filter_var(
                $metadata->guest_report_purchase_id ?? null,
                FILTER_VALIDATE_INT,
            );

            if (
                ! is_int($legacyPurchaseId)
                || $legacyPurchaseId < 1
                || ! hash_equals(
                    (string) $legacyPurchaseId,
                    $clientReferenceId,
                )
            ) {
                throw new RuntimeException(
                    'Invalid legacy Stripe guest checkout reference.',
                );
            }

            $purchase = GuestReportPurchase::query()
                ->findOrFail($legacyPurchaseId);
        } else {
            // A malformed UUID must never fall back to the legacy identity.
            throw new RuntimeException('Invalid guest report checkout reference.');
        }

        // Keep the current UUID as the canonical local identity,
        // including when processing a legacy Stripe session.
        $canonicalCheckoutReference = (string) $purchase->checkout_reference;

        $purchaseId = (int) $purchase->id;

        $paymentIntent = $session->payment_intent ?? null;

        $paymentIntentId = is_string($paymentIntent) ? $paymentIntent : (is_object($paymentIntent) ? ($paymentIntent->id ?? null) : null);

        $successfulEvents = [
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
        ];

        $failedEvents = [
            'checkout.session.async_payment_failed',
        ];

        $paymentChanged = DB::transaction(function () use (
            $session,
            $eventType,
            $purchaseId,
            $reportId,
            $sessionId,
            $canonicalCheckoutReference,
            $paymentIntentId,
            $successfulEvents,
            $failedEvents,
        ): bool {
            $purchase = GuestReportPurchase::query()
                ->lockForUpdate()
                ->findOrFail($purchaseId);

            if (
                ! hash_equals(
                    (string) $purchase->checkout_reference,
                    $canonicalCheckoutReference,
                )
            ) {
                Log::critical('Stripe guest checkout reference mismatch.', [
                    'purchase_id' => $purchase->id,
                ]);

                throw new RuntimeException(
                    'Stripe checkout reference does not match the purchase.',
                );
            }

            // Memastikan event Stripe tidak memperbarui pembelian dari provider lain (misal: PayPal)
            if ($purchase->payment_provider !== 'stripe') {
                Log::warning('Stripe webhook received for non-stripe purchase provider.', [
                    'purchase_id' => $purchase->id,
                    'provider' => $purchase->payment_provider,
                ]);

                throw new RuntimeException('Purchase payment provider does not match Stripe.');
            }

            if ((int) $purchase->report_id !== (int) $reportId) {
                Log::critical('Guest Checkout report metadata mismatch.', [
                    'purchase_id' => $purchase->id,
                    'expected_report_id' => $purchase->report_id,
                    'received_report_id' => $reportId,
                ]);

                throw new RuntimeException('Guest checkout report metadata does not match the purchase.');
            }

            if ($purchase->provider_order_id !== null &&
                $purchase->provider_order_id !== $sessionId
            ) {
                Log::critical('Guest checkout session mismatch.', [
                    'purchase_id' => $purchase->id,
                    'stored_session_id' => $purchase->provider_order_id,
                    'received_session_id' => $sessionId,
                ]);

                throw new RuntimeException('Stripe Checkout Session does not match the purchase.');
            }

            // Mengganti key referensi menjadi provider_order_id dan provider_payment_id
            $references = [
                'provider_order_id' => $sessionId,
            ];

            if (is_string($paymentIntentId) && $paymentIntentId !== '') {
                $references['provider_payment_id'] = $paymentIntentId;
            }

            if (in_array($eventType, $successfulEvents, true)) {
                if (($session->payment_status ?? null) !== 'paid') {
                    $purchase->update($references);

                    return false;
                }

                $amount = $session->amount_total ?? null;
                $currency = $session->currency ?? null;

                if (! is_numeric($amount) || (int) $amount !== (int) $purchase->amount_minor ||
                    ! is_string($currency) || strtolower($currency) !== strtolower($purchase->currency)
                ) {
                    Log::critical('Guest checkout amount or currency mismatch.', [
                        'purchase_id' => $purchase->id,
                        'expected_amount' => $purchase->amount_minor,
                        'received_amount' => $amount,
                        'expected_currency' => $purchase->currency,
                        'received_currency' => $currency,
                    ]);

                    throw new RuntimeException('Stripe amount or currency does not match the purchase.');
                }

                if ($purchase->payment_status === GuestReportPurchase::STATUS_REFUNDED) {
                    Log::warning('Paid event received for a refunded guest purchase.', [
                        'purchase_id' => $purchase->id,
                        'session_id' => $sessionId,
                    ]);

                    return false;
                }

                if ($purchase->payment_status === GuestReportPurchase::STATUS_PAID) {
                    $updates = $references;

                    if ($purchase->fulfillment_status === GuestReportPurchase::FULFILLMENT_LOCKED) {
                        $updates['fulfillment_status'] = GuestReportPurchase::FULFILLMENT_PENDING;
                    }

                    $purchase->update($updates);

                    return false;
                }

                $purchase->update([
                    ...$references,
                    'payment_status' => GuestReportPurchase::STATUS_PAID,
                    'paid_at' => $purchase->paid_at ?? now(),
                    'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
                ]);

                return true;
            }

            if (in_array($eventType, $failedEvents, true)) {
                if ($purchase->payment_status === GuestReportPurchase::STATUS_PENDING) {
                    $purchase->update([
                        ...$references,
                        'payment_status' => GuestReportPurchase::STATUS_FAILED,
                        'failed_at' => now(),
                    ]);
                } else {
                    $purchase->update($references);
                }

                return false;
            }

            if ($eventType === 'checkout.session.expired') {
                if ($purchase->payment_status === GuestReportPurchase::STATUS_PENDING) {
                    $purchase->update([
                        ...$references,
                        'payment_status' => GuestReportPurchase::STATUS_EXPIRED,
                        'expired_at' => now(),
                    ]);
                } else {
                    $purchase->update($references);
                }

                return false;
            }

            throw new RuntimeException('Unsupported guest checkout event type.');
        });

        if (in_array($eventType, $successfulEvents, true)
            && ($session->payment_status ?? null) === 'paid') {
            $purchase = GuestReportPurchase::query()
                ->with('report')
                ->findOrFail($purchaseId);

            if ($purchase->payment_status === GuestReportPurchase::STATUS_PAID
                && in_array(
                    $purchase->fulfillment_status,
                    [
                        GuestReportPurchase::FULFILLMENT_PENDING,
                        GuestReportPurchase::FULFILLMENT_PROCESSING,
                        GuestReportPurchase::FULFILLMENT_FAILED,
                    ],
                    true
                )
            ) {
                $report = $purchase->report;

                if (! $report
                    || $report->user_id !== null
                    || $report->report_type !== 'basic'
                    || ! $report->vin_check_id
                ) {
                    Log::critical('Guest report cannot be fulfilled.', [
                        'purchase_id' => $purchase->id,
                        'report_id' => $purchase->report_id,
                    ]);

                    throw new RuntimeException('Guest report is not eligible for Premium fulfillment.');
                }

                ProcessVehicleCheck::dispatch(
                    (int) $report->vin_check_id,
                    (int) $report->id,
                    (int) $purchase->id,
                );
            }
        }

        // Notify Telegram only when the guest payment is first confirmed.
        if (
            $paymentChanged
            && ($session->payment_status ?? null) === 'paid'
        ) {
            $purchase = GuestReportPurchase::query()
                ->with('report')
                ->findOrFail($purchaseId);

            $this->dispatchGuestReportSaleNotification(
                $purchase,
                'Stripe',
            );
        }

        return $paymentChanged;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function handlePayPalGuestOrder(array $order): bool
    {
        $orderId = $order['id'] ?? null;
        $customId = data_get($order, 'purchase_units.0.custom_id');

        if (
            ! is_string($orderId)
            || $orderId === ''
            || ! is_string($customId)
            || ($order['status'] ?? null) !== 'COMPLETED'
        ) {
            throw new RuntimeException('Invalid completed PayPal guest order.');
        }

        $parts = explode('|', $customId);

        if (
            count($parts) !== 3
            || $parts[0] !== 'guest-report'
            || ! ctype_digit($parts[2])
        ) {
            throw new RuntimeException('Invalid PayPal guest order metadata.');
        }

        $purchaseReference = $parts[1];
        $reportId = (int) $parts[2];

        $isUuidReference = Str::isUuid($purchaseReference);
        $isLegacyNumericReference = ctype_digit($purchaseReference);

        if (! $isUuidReference && ! $isLegacyNumericReference) {
            throw new RuntimeException('Invalid PayPal guest purchase reference.');
        }

        $purchaseUnit = $order['purchase_units'][0] ?? null;
        $capture = $purchaseUnit['payments']['captures'][0] ?? null;

        if (
            ! $capture
            || ($capture['status'] ?? null) !== 'COMPLETED'
            || ! is_string($capture['id'] ?? null)
        ) {
            throw new RuntimeException('PayPal capture is not completed.');
        }

        $captureId = $capture['id'];
        $amount = $capture['amount']['value'] ?? null;
        $currency = strtoupper(
            is_string($capture['amount']['currency_code'] ?? null)
                ? $capture['amount']['currency_code']
                : ''
        );

        $purchaseQuery = GuestReportPurchase::query()
            ->with('report');

        if ($isUuidReference) {
            $purchaseQuery->where(
                'checkout_reference',
                $purchaseReference,
            );
        } else {
            // Backward compatibility for existing PayPal orders.
            $purchaseQuery->whereKey((int) $purchaseReference);
        }

        $purchase = $purchaseQuery->firstOrFail();

        // Continue using the local database ID for fulfillment and locking.
        $purchaseId = (int) $purchase->id;

        if (
            $purchase->payment_provider !== 'paypal'
            || (int) $purchase->report_id !== $reportId
            || $purchase->provider_order_id !== $orderId
        ) {
            throw new RuntimeException('PayPal purchase reference mismatch.');
        }

        $expectedAmount = number_format(
            $purchase->amount_minor / 100,
            2,
            '.',
            '',
        );

        if (
            ! is_string($amount)
            || $amount !== $expectedAmount
            || $currency !== strtoupper($purchase->currency)
        ) {
            throw new RuntimeException('PayPal amount or currency mismatch.');
        }

        $report = $purchase->report;

        if (
            ! $report
            || $report->user_id !== null
            || ! $report->vin_check_id
            || ! in_array($report->report_type, ['basic', 'premium'], true)
        ) {
            throw new RuntimeException('Guest report is not eligible for fulfillment.');
        }

        $result = DB::transaction(function () use (
            $purchaseId,
            $orderId,
            $captureId,
            $report,
        ): array {
            $lockedPurchase = GuestReportPurchase::query()
                ->lockForUpdate()
                ->findOrFail($purchaseId);

            if (
                $lockedPurchase->payment_provider !== 'paypal'
                || $lockedPurchase->provider_order_id !== $orderId
                || (int) $lockedPurchase->report_id !== (int) $report->id
            ) {
                throw new RuntimeException('PayPal purchase changed during capture.');
            }

            if ($lockedPurchase->payment_status === GuestReportPurchase::STATUS_REFUNDED) {
                return [
                    'payment_changed' => false,
                    'dispatch' => false,
                ];
            }

            // Duplicate payment callback: validate the capture reference.
            if ($lockedPurchase->payment_status === GuestReportPurchase::STATUS_PAID) {
                if ($lockedPurchase->provider_payment_id !== $captureId) {
                    throw new RuntimeException('PayPal capture reference mismatch.');
                }

                // A completed Premium report must never be processed again.
                if (
                    $lockedPurchase->fulfillment_status
                    === GuestReportPurchase::FULFILLMENT_COMPLETED
                ) {
                    if ($report->report_type !== 'premium') {
                        throw new RuntimeException('Completed guest report is not Premium.');
                    }

                    return [
                        'payment_changed' => false,
                        'dispatch' => false,
                    ];
                }

                $recoverableStatuses = [
                    GuestReportPurchase::FULFILLMENT_LOCKED,
                    GuestReportPurchase::FULFILLMENT_PENDING,
                    GuestReportPurchase::FULFILLMENT_PROCESSING,
                    GuestReportPurchase::FULFILLMENT_FAILED,
                ];

                if (! in_array(
                    $lockedPurchase->fulfillment_status,
                    $recoverableStatuses,
                    true
                )) {
                    throw new RuntimeException('Guest fulfillment status is not recoverable.');
                }

                // A paid purchase must not remain locked.
                if (
                    $lockedPurchase->fulfillment_status
                    === GuestReportPurchase::FULFILLMENT_LOCKED
                ) {
                    $lockedPurchase->update([
                        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
                    ]);
                }

                return [
                    'payment_changed' => false,
                    'dispatch' => true,
                ];
            }

            if ($lockedPurchase->payment_status !== GuestReportPurchase::STATUS_PENDING) {
                throw new RuntimeException('Guest purchase is not pending.');
            }

            // A new guest purchase must still reference a Basic report.
            if ($report->report_type !== 'basic') {
                throw new RuntimeException('New guest purchase does not reference a Basic report.');
            }

            $lockedPurchase->update([
                'provider_payment_id' => $captureId,
                'payment_status' => GuestReportPurchase::STATUS_PAID,
                'paid_at' => $lockedPurchase->paid_at ?? now(),
                'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
            ]);

            return [
                'payment_changed' => true,
                'dispatch' => true,
            ];
        });

        if ($result['dispatch']) {
            ProcessVehicleCheck::dispatch(
                (int) $report->vin_check_id,
                (int) $report->id,
                (int) $purchase->id,
            );
        }

        // Notify Telegram only when the guest payment is first confirmed.
        if ($result['payment_changed']) {
            $purchase = GuestReportPurchase::query()
                ->with('report')
                ->findOrFail($purchaseId);

            $this->dispatchGuestReportSaleNotification(
                $purchase,
                'PayPal',
            );
        }

        return $result['payment_changed'];
    }

    private function dispatchGuestReportSaleNotification(
        GuestReportPurchase $purchase,
        string $provider,
    ): void {
        try {
            $purchase->loadMissing('report');

            if (! $purchase->report) {
                throw new RuntimeException(
                    'Guest report is missing for Telegram notification.'
                );
            }

            $reference = $purchase->provider_payment_id
                ?: $purchase->provider_order_id;

            if (! is_string($reference) || $reference === '') {
                throw new RuntimeException(
                    'Guest report payment reference is missing.'
                );
            }

            // Ensure the report access token exists before building its URL.
            $guestToken = app(GuestReportCheckoutService::class)
                ->guestAccessToken($purchase);

            $reportUrl = route('admin.reports.index', [
                'search' => (string) $purchase->report_id,
            ]);

            $amount = number_format(
                $purchase->amount_minor / 100,
                2,
                '.',
                ','
            );

            SendTelegramSaleNotification::dispatch(
                'Guest Report',
                $provider,
                $reference,
                strtoupper((string) $purchase->currency).' '.$amount,
                (int) $purchase->id,
                (string) $purchase->name,
                (string) $purchase->phone,
                $reportUrl,
            )->afterCommit();
        } catch (\Throwable $exception) {
            // Do not turn a confirmed payment into a failed webhook response.
            Log::error('Failed to queue guest report Telegram notification.', [
                'purchase_id' => $purchase->id,
                'provider' => $provider,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
