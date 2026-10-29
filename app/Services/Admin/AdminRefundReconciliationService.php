<?php

namespace App\Services\Admin;

use App\Models\CreditTransaction;
use App\Models\GuestReportPurchase;
use App\Models\ProcessedWebhookEvent;
use App\Models\RefundRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\PayPalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class AdminRefundReconciliationService
{
    public function __construct(
        protected AdminAuditService $auditService,
        protected PayPalService $payPalService,
        protected ?StripeClient $stripeClient = null,
    ) {
        $this->stripeClient = $this->stripeClient ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Wrap any webhook event execution in a retry-safe deduplication guard.
     *
     * @param  array<string, mixed>|null  $payloadSummary
     * @return array{processed: bool, status: string, message?: string}
     */
    public function executeWithDeduplication(
        string $provider,
        string $eventId,
        string $eventType,
        callable $callback,
        ?array $payloadSummary = null,
    ): array {
        // Fast check for existing event record
        $event = ProcessedWebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->first();

        if ($event && $event->isCompleted()) {
            return [
                'processed' => false,
                'status' => 'already_completed',
                'message' => "Webhook event {$eventId} already processed successfully.",
            ];
        }

        // If currently processing in another worker (< 30 seconds old), avoid race condition
        if ($event && $event->isProcessing() && $event->created_at && $event->created_at->diffInSeconds(now()) < 30) {
            return [
                'processed' => false,
                'status' => 'in_flight',
                'message' => "Webhook event {$eventId} is currently in flight.",
            ];
        }

        if (! $event) {
            try {
                $event = ProcessedWebhookEvent::create([
                    'provider' => $provider,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
                    'payload_summary' => $payloadSummary,
                ]);
            } catch (UniqueConstraintViolationException) {
                $event = ProcessedWebhookEvent::query()
                    ->where('provider', $provider)
                    ->where('event_id', $eventId)
                    ->first();

                if ($event && $event->isCompleted()) {
                    return [
                        'processed' => false,
                        'status' => 'already_completed',
                    ];
                }
            }
        } else {
            $event->update([
                'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
                'error_message' => null,
            ]);
        }

        try {
            $callback();

            $event?->update([
                'status' => ProcessedWebhookEvent::STATUS_COMPLETED,
                'processed_at' => now(),
            ]);

            return [
                'processed' => true,
                'status' => 'completed',
            ];
        } catch (\Throwable $e) {
            $event?->update([
                'status' => ProcessedWebhookEvent::STATUS_FAILED,
                'error_message' => substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }

    /**
     * Handle Stripe refund-related webhook events.
     *
     * @param  array<string, mixed>|object  $dataObject
     * @return array<string, mixed>
     */
    public function handleStripeRefundEvent(string $eventType, array|object $dataObject): array
    {
        if (is_object($dataObject) && method_exists($dataObject, 'toArray')) {
            $data = $dataObject->toArray();
        } elseif (is_object($dataObject)) {
            $encoded = json_encode($dataObject);
            $data = is_string($encoded) ? (json_decode($encoded, true) ?: []) : [];
        } else {
            $data = $dataObject;
        }

        if ($eventType === 'charge.refunded') {
            return $this->processStripeChargeRefunded($data);
        }

        if (in_array($eventType, ['refund.created', 'refund.updated'], true)) {
            return $this->processStripeRefundObject($data);
        }

        return ['status' => 'unsupported_event'];
    }

    /**
     * Process Stripe `charge.refunded` event.
     *
     * @param  array<string, mixed>  $charge
     * @return array<string, mixed>
     */
    protected function processStripeChargeRefunded(array $charge): array
    {
        $chargeId = $charge['id'] ?? null;
        $paymentIntentId = $charge['payment_intent'] ?? null;
        $amount = (int) ($charge['amount'] ?? 0);
        $amountRefunded = (int) ($charge['amount_refunded'] ?? 0);
        $currency = strtoupper((string) ($charge['currency'] ?? 'GBP'));

        // Check for partial refund: if amount_refunded < amount, do NOT mark full refund
        if ($amountRefunded < $amount) {
            Log::warning("Stripe partial refund detected on charge {$chargeId}: refunded {$amountRefunded} of {$amount}. v1 requires full refund.");

            return [
                'status' => 'partial_refund_ignored',
                'charge_id' => $chargeId,
                'amount_refunded' => $amountRefunded,
                'amount' => $amount,
            ];
        }

        // Determine refund ID from refunds collection if available
        $refundsData = $charge['refunds']['data'] ?? ($charge['refunds']->data ?? []);
        $latestRefund = ! empty($refundsData) ? (array) $refundsData[0] : null;
        $providerRefundId = $latestRefund['id'] ?? null;

        $matchingRecord = $this->findMatchingRefundRecord(
            gateway: 'stripe',
            providerRefundId: $providerRefundId,
            paymentReferences: array_filter([$paymentIntentId, $chargeId]),
            amountMinor: $amount,
            currency: $currency,
            idempotencyKey: $latestRefund['metadata']['idempotency_key'] ?? null,
        );

        if (! $matchingRecord) {
            Log::info("No deterministic RefundRecord match found for Stripe charge.refunded: {$chargeId} / {$paymentIntentId}");

            return [
                'status' => 'unmatched',
                'charge_id' => $chargeId,
            ];
        }

        return $this->settleRefundSuccess($matchingRecord, $providerRefundId);
    }

    /**
     * Process Stripe `refund.created` or `refund.updated` event.
     *
     * @param  array<string, mixed>  $refund
     * @return array<string, mixed>
     */
    protected function processStripeRefundObject(array $refund): array
    {
        $refundId = $refund['id'] ?? null;
        $chargeId = $refund['charge'] ?? null;
        $paymentIntentId = $refund['payment_intent'] ?? null;
        $amount = (int) ($refund['amount'] ?? 0);
        $currency = strtoupper((string) ($refund['currency'] ?? 'GBP'));
        $status = $refund['status'] ?? 'succeeded';
        $failureReason = $refund['failure_reason'] ?? null;
        $idempotencyKey = $refund['metadata']['idempotency_key'] ?? null;

        $matchingRecord = $this->findMatchingRefundRecord(
            gateway: 'stripe',
            providerRefundId: $refundId,
            paymentReferences: array_filter([$paymentIntentId, $chargeId]),
            amountMinor: $amount,
            currency: $currency,
            idempotencyKey: $idempotencyKey,
        );

        if (! $matchingRecord) {
            Log::info("No deterministic RefundRecord match found for Stripe refund {$refundId}");

            return [
                'status' => 'unmatched',
                'refund_id' => $refundId,
            ];
        }

        if ($status === 'succeeded') {
            return $this->settleRefundSuccess($matchingRecord, $refundId);
        }

        if (in_array($status, ['failed', 'canceled'], true)) {
            return $this->settleRefundDefinitiveFailure($matchingRecord, $failureReason ?: "Stripe status: {$status}");
        }

        // If status is 'pending' or 'requires_action', maintain unknown and keep hold intact
        return [
            'status' => 'ambiguous_maintained',
            'record_id' => $matchingRecord->id,
            'provider_status' => $status,
        ];
    }

    /**
     * Handle PayPal `PAYMENT.CAPTURE.REFUNDED` webhook event.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function handlePayPalRefundWebhook(array $event): array
    {
        $resource = $event['resource'] ?? [];
        $refundId = $resource['id'] ?? null;
        $status = strtoupper((string) ($resource['status'] ?? 'COMPLETED'));
        $amountValue = (float) ($resource['amount']['value'] ?? 0);
        $amountMinor = (int) round($amountValue * 100);
        $currency = strtoupper((string) ($resource['amount']['currency_code'] ?? 'GBP'));

        // Extract capture ID from links (rel === 'up')
        $captureId = null;
        $links = $resource['links'] ?? [];
        foreach ($links as $link) {
            if (($link['rel'] ?? null) === 'up' && isset($link['href'])) {
                if (preg_match('/\/captures\/([A-Za-z0-9_-]+)/', $link['href'], $matches)) {
                    $captureId = $matches[1];
                    break;
                }
            }
        }

        $matchingRecord = $this->findMatchingRefundRecord(
            gateway: 'paypal',
            providerRefundId: $refundId,
            paymentReferences: array_filter([$captureId]),
            amountMinor: $amountMinor,
            currency: $currency,
        );

        if (! $matchingRecord) {
            Log::info("No deterministic RefundRecord match found for PayPal refund {$refundId} (capture: {$captureId})");

            return [
                'status' => 'unmatched',
                'refund_id' => $refundId,
                'capture_id' => $captureId,
            ];
        }

        if ($status === 'COMPLETED') {
            return $this->settleRefundSuccess($matchingRecord, $refundId);
        }

        if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
            $reason = $resource['status_details']['reason'] ?? "PayPal status: {$status}";

            return $this->settleRefundDefinitiveFailure($matchingRecord, $reason);
        }

        // PENDING status: maintain hold and unknown status
        return [
            'status' => 'ambiguous_maintained',
            'record_id' => $matchingRecord->id,
            'provider_status' => $status,
        ];
    }

    /**
     * Find matching RefundRecord with strict deterministic criteria.
     *
     * @param  array<int, string>  $paymentReferences
     */
    public function findMatchingRefundRecord(
        string $gateway,
        ?string $providerRefundId,
        array $paymentReferences,
        int $amountMinor,
        string $currency,
        ?string $idempotencyKey = null,
    ): ?RefundRecord {
        // Priority 1: Match by provider refund ID
        if (! empty($providerRefundId)) {
            $byRef = RefundRecord::query()
                ->where('gateway', $gateway)
                ->where('refund_gateway_ref', $providerRefundId)
                ->first();

            if ($byRef) {
                return $byRef;
            }
        }

        // Priority 2: Match by admin idempotency key if provided in gateway metadata
        if (! empty($idempotencyKey)) {
            $byKey = RefundRecord::query()
                ->where('gateway', $gateway)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($byKey) {
                return $byKey;
            }
        }

        // Priority 3: Match unknown or processing records by payment reference + currency + amount
        $cleanRefs = array_values(array_filter($paymentReferences));
        if (empty($cleanRefs)) {
            return null;
        }

        $candidates = RefundRecord::query()
            ->where('gateway', $gateway)
            ->whereIn('payment_reference', $cleanRefs)
            ->whereIn('status', [RefundRecord::STATUS_UNKNOWN, RefundRecord::STATUS_PROCESSING])
            ->get();

        $filtered = $candidates->filter(function (RefundRecord $candidate) use ($amountMinor, $currency) {
            $matchesCurrency = strtoupper($candidate->currency) === strtoupper($currency);
            $matchesAmount = (int) $candidate->amount_minor === (int) $amountMinor;

            return $matchesCurrency && $matchesAmount;
        });

        // Fail-closed on ambiguity: if more than 1 candidate matches, log critical and do not guess
        if ($filtered->count() > 1) {
            Log::critical("Ambiguous refund matching: multiple active candidates found for gateway {$gateway} on refs: ".implode(',', $cleanRefs));

            return null;
        }

        return $filtered->first();
    }

    /**
     * Settle refund as SUCCESS atomically inside DB transaction.
     *
     * @return array<string, mixed>
     */
    protected function settleRefundSuccess(RefundRecord $record, ?string $providerRefundId): array
    {
        return DB::transaction(function () use ($record, $providerRefundId) {
            $lockedRecord = RefundRecord::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Terminal success: older out-of-order events MUST NOT alter success
            if ($lockedRecord->status === RefundRecord::STATUS_SUCCESS) {
                if (empty($lockedRecord->refund_gateway_ref) && ! empty($providerRefundId)) {
                    $lockedRecord->update(['refund_gateway_ref' => $providerRefundId]);
                }

                return [
                    'status' => 'already_success',
                    'record_id' => $lockedRecord->id,
                ];
            }

            $lockedRecord->update([
                'status' => RefundRecord::STATUS_SUCCESS,
                'refund_gateway_ref' => $providerRefundId ?: $lockedRecord->refund_gateway_ref,
            ]);

            $source = $lockedRecord->refundable;

            if ($source instanceof Transaction) {
                $source->update([
                    'status' => 'refunded',
                    'refunded_at' => $source->refunded_at ?: now(),
                ]);
            } elseif ($source instanceof GuestReportPurchase) {
                $source->update([
                    'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
                    'refunded_at' => $source->refunded_at ?: now(),
                ]);
            }

            // Audit logging
            $admin = $lockedRecord->admin;
            if ($admin) {
                $targetId = $source instanceof Transaction || $source instanceof GuestReportPurchase ? $source->id : null;

                $this->auditService->log(
                    admin: $admin,
                    action: 'transaction.refunded',
                    targetType: $source instanceof Transaction ? 'transaction' : 'guest_report_purchase',
                    targetId: $targetId,
                    reason: $lockedRecord->reason,
                    outcome: 'success',
                    metadata: [
                        'reconciliation' => 'webhook',
                        'refund_record_id' => $lockedRecord->id,
                        'refund_gateway_ref' => $lockedRecord->refund_gateway_ref,
                        'gateway' => $lockedRecord->gateway,
                    ]
                );
            }

            return [
                'status' => 'settled',
                'record_id' => $lockedRecord->id,
            ];
        });
    }

    /**
     * Settle refund as DEFINITIVE FAILURE and release credit hold atomically inside DB transaction.
     *
     * @return array<string, mixed>
     */
    protected function settleRefundDefinitiveFailure(RefundRecord $record, string $reason): array
    {
        return DB::transaction(function () use ($record, $reason) {
            $lockedRecord = RefundRecord::query()
                ->whereKey($record->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Terminal success guard: success cannot be reversed by late failed event
            if ($lockedRecord->status === RefundRecord::STATUS_SUCCESS) {
                Log::warning("Late failure event received for already-successful RefundRecord #{$lockedRecord->id}. Ignored.");

                return [
                    'status' => 'ignored_late_failure',
                    'record_id' => $lockedRecord->id,
                ];
            }

            if ($lockedRecord->status === RefundRecord::STATUS_FAILED) {
                return [
                    'status' => 'already_failed',
                    'record_id' => $lockedRecord->id,
                ];
            }

            $lockedRecord->update([
                'status' => RefundRecord::STATUS_FAILED,
                'error_message' => $reason,
            ]);

            $source = $lockedRecord->refundable;
            $creditsToRestore = (int) ($lockedRecord->metadata['credits_to_deduct'] ?? 0);

            if ($creditsToRestore > 0 && $source instanceof Transaction) {
                $lockedUser = User::query()
                    ->whereKey($source->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newBalance = $lockedUser->credits + $creditsToRestore;

                $lockedUser->update([
                    'credits' => $newBalance,
                ]);

                CreditTransaction::create([
                    'user_id' => $lockedUser->id,
                    'type' => 'refund',
                    'amount' => $creditsToRestore,
                    'balance_after' => $newBalance,
                    'reference_id' => "refund_reversal:{$lockedRecord->id}",
                    'idempotency_key' => "refund_reversal:{$lockedRecord->idempotency_key}",
                    'description' => "Reversal of uncommitted refund hold for Transaction #{$source->id} (RefundRecord #{$lockedRecord->id})",
                ]);
            }

            $admin = $lockedRecord->admin;
            if ($admin) {
                $targetId = $source instanceof Transaction || $source instanceof GuestReportPurchase ? $source->id : null;

                $this->auditService->log(
                    admin: $admin,
                    action: 'transaction.refunded',
                    targetType: $source instanceof Transaction ? 'transaction' : 'guest_report_purchase',
                    targetId: $targetId,
                    reason: $lockedRecord->reason,
                    outcome: 'failed',
                    metadata: [
                        'reconciliation' => 'webhook',
                        'refund_record_id' => $lockedRecord->id,
                        'error' => $reason,
                        'credits_restored' => $creditsToRestore,
                    ]
                );
            }

            return [
                'status' => 'reversed',
                'record_id' => $lockedRecord->id,
            ];
        });
    }

    /**
     * On-demand / CLI reconciliation of a specific RefundRecord with status = 'unknown'.
     */
    public function reconcileUnknownRecord(RefundRecord $record): RefundRecord
    {
        if ($record->status !== RefundRecord::STATUS_UNKNOWN) {
            return $record;
        }

        if ($record->gateway === 'stripe') {
            return $this->reconcileStripeUnknown($record);
        }

        if ($record->gateway === 'paypal') {
            return $this->reconcilePayPalUnknown($record);
        }

        return $record;
    }

    /**
     * Reconcile unknown Stripe refund against Stripe API.
     */
    protected function reconcileStripeUnknown(RefundRecord $record): RefundRecord
    {
        try {
            // Priority: if refund_gateway_ref exists, retrieve it
            if (! empty($record->refund_gateway_ref)) {
                $stripeRefund = $this->stripeClient->refunds->retrieve($record->refund_gateway_ref);
                if ($stripeRefund->status === 'succeeded') {
                    $this->settleRefundSuccess($record, $stripeRefund->id);

                    return $record->fresh();
                }
                if (in_array($stripeRefund->status, ['failed', 'canceled'], true)) {
                    $this->settleRefundDefinitiveFailure($record, "Stripe API status: {$stripeRefund->status}");

                    return $record->fresh();
                }
            }

            // Query refunds by payment_intent or charge
            $paymentRef = $record->payment_reference;
            $params = [];
            if (str_starts_with($paymentRef, 'ch_') || str_starts_with($paymentRef, 'py_')) {
                $params['charge'] = $paymentRef;
            } else {
                $params['payment_intent'] = $paymentRef;
            }

            $refundsList = $this->stripeClient->refunds->all($params);
            foreach ($refundsList->data as $r) {
                if ((int) $r->amount === (int) $record->amount_minor && strtoupper($r->currency) === strtoupper($record->currency)) {
                    if ($r->status === 'succeeded') {
                        $this->settleRefundSuccess($record, $r->id);

                        return $record->fresh();
                    }
                    if (in_array($r->status, ['failed', 'canceled'], true)) {
                        $this->settleRefundDefinitiveFailure($record, "Stripe API status: {$r->status}");

                        return $record->fresh();
                    }
                }
            }
        } catch (ApiErrorException $e) {
            report($e);
            // If Stripe returned 404 or definitive error, do not release hold unless retention window has passed
        } catch (\Throwable $e) {
            report($e);
            // Network failure: keep status unknown and hold intact
        }

        return $record->fresh();
    }

    /**
     * Reconcile unknown PayPal refund against PayPal API.
     */
    protected function reconcilePayPalUnknown(RefundRecord $record): RefundRecord
    {
        try {
            $capture = $this->payPalService->getCapture($record->payment_reference);
            $captureStatus = strtoupper((string) ($capture['status'] ?? ''));

            if ($captureStatus === 'REFUNDED') {
                // Settle as success
                $this->settleRefundSuccess($record, $record->refund_gateway_ref ?: 'paypal_capture_refunded');

                return $record->fresh();
            }
        } catch (\Throwable $e) {
            report($e);
            // Network failure: maintain unknown status and hold
        }

        return $record->fresh();
    }
}
