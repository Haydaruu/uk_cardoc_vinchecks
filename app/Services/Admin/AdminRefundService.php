<?php

namespace App\Services\Admin;

use App\Models\CreditTransaction;
use App\Models\GuestReportPurchase;
use App\Models\RefundRecord;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\PayPalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminRefundService
{
    public function __construct(
        protected AdminAuditService $auditService,
        protected PayPalService $payPalService,
        protected ?StripeClient $stripeClient = null,
    ) {
        $this->stripeClient = $this->stripeClient ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Check if a given transaction or guest report purchase is eligible for a full refund.
     * Strict Fail-Closed implementation.
     *
     * @return array{
     *     eligible: bool,
     *     reason: ?string,
     *     credits_to_deduct: int,
     *     amount_minor: int,
     *     currency: string,
     *     payment_reference: string,
     *     gateway: string,
     * }
     */
    public function checkEligibility(Transaction|GuestReportPurchase $source): array
    {
        if ($source instanceof Transaction) {
            return $this->checkRegisteredTransactionEligibility($source);
        }

        return $this->checkGuestReportEligibility($source);
    }

    /**
     * Execute a full refund with Two-Phase Credit Hold & Settle/Reversal.
     *
     * @throws ValidationException
     * @throws HttpException
     */
    public function executeRefund(
        User $admin,
        Transaction|GuestReportPurchase $source,
        string $reason,
        string $idempotencyKey,
        ?Request $request = null,
    ): RefundRecord {
        if (! in_array($admin->role, ['admin', 'super_admin'], true)) {
            abort(403, 'Unauthorized. Only Admin and Super Admin can execute refunds.');
        }

        // Fast-path idempotency replay check
        $existingRecord = RefundRecord::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingRecord) {
            $isSameType = $existingRecord->refundable_type === get_class($source);
            $isSameId = (int) $existingRecord->refundable_id === (int) $source->id;

            if ($isSameType && $isSameId) {
                return $existingRecord;
            }

            abort(409, 'Idempotency key has already been used with different parameters.');
        }

        // PHASE 1: Local DB Reservation & Concurrency Guard (<5ms)
        $refundRecord = DB::transaction(function () use (
            $admin,
            $source,
            $reason,
            $idempotencyKey,
        ) {
            // Pessimistic lock on the source transaction
            $lockedSource = $source::query()
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Concurrency Guard: Check if an active/completed refund workflow already exists
            $activeWorkflow = RefundRecord::query()
                ->where('refundable_type', get_class($lockedSource))
                ->where('refundable_id', $lockedSource->id)
                ->whereIn('status', [
                    RefundRecord::STATUS_PROCESSING,
                    RefundRecord::STATUS_SUCCESS,
                    RefundRecord::STATUS_UNKNOWN,
                ])
                ->lockForUpdate()
                ->first();

            if ($activeWorkflow) {
                if ($activeWorkflow->idempotency_key === $idempotencyKey) {
                    return $activeWorkflow;
                }

                abort(
                    409,
                    "A refund workflow ({$activeWorkflow->status}) is already active or completed for this transaction."
                );
            }

            // Verify eligibility inside lock
            $eligibility = $this->checkEligibility($lockedSource);

            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages([
                    'refund' => [$eligibility['reason'] ?? 'Transaction is not eligible for refund.'],
                ]);
            }

            // Create RefundRecord in processing state
            $record = RefundRecord::create([
                'refundable_type' => get_class($lockedSource),
                'refundable_id' => $lockedSource->id,
                'admin_id' => $admin->id,
                'gateway' => $eligibility['gateway'],
                'payment_reference' => $eligibility['payment_reference'],
                'amount_minor' => $eligibility['amount_minor'],
                'currency' => $eligibility['currency'],
                'status' => RefundRecord::STATUS_PROCESSING,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'metadata' => [
                    'credits_to_deduct' => $eligibility['credits_to_deduct'],
                ],
            ]);

            // Deduct credits immediately in Phase 1 (Two-Phase Hold)
            if ($eligibility['credits_to_deduct'] > 0 && $lockedSource instanceof Transaction) {
                $lockedUser = User::query()
                    ->whereKey($lockedSource->user_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $newBalance = $lockedUser->credits - $eligibility['credits_to_deduct'];

                $lockedUser->update([
                    'credits' => $newBalance,
                ]);

                CreditTransaction::create([
                    'user_id' => $lockedUser->id,
                    'type' => 'refund',
                    'amount' => -$eligibility['credits_to_deduct'],
                    'balance_after' => $newBalance,
                    'reference_id' => "refund_hold:{$record->id}",
                    'idempotency_key' => "refund_hold:{$idempotencyKey}",
                    'description' => "Refund credit hold for Transaction #{$lockedSource->id} (RefundRecord #{$record->id})",
                ]);
            }

            return $record;
        });

        // If existing record was returned from Phase 1 concurrency guard, return it
        if ($refundRecord->status !== RefundRecord::STATUS_PROCESSING) {
            return $refundRecord;
        }

        // PHASE 2: External Gateway Dispatch (No DB lock held)
        try {
            $gatewayResult = $this->dispatchGatewayRefund(
                gateway: $refundRecord->gateway,
                paymentReference: $refundRecord->payment_reference,
                amountMinor: $refundRecord->amount_minor,
                currency: $refundRecord->currency,
                reason: $reason,
                idempotencyKey: $idempotencyKey,
            );
        } catch (\Throwable $e) {
            return $this->handleGatewayFailure($refundRecord, $source, $admin, $e, $request);
        }

        // PHASE 3A: Settle (Gateway Confirmed Success)
        return DB::transaction(function () use (
            $refundRecord,
            $source,
            $admin,
            $gatewayResult,
            $request,
        ) {
            $lockedRecord = RefundRecord::query()
                ->whereKey($refundRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedSource = $source::query()
                ->whereKey($source->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRecord->update([
                'status' => RefundRecord::STATUS_SUCCESS,
                'refund_gateway_ref' => $gatewayResult['refund_id'],
            ]);

            if ($lockedSource instanceof Transaction) {
                $lockedSource->update([
                    'status' => 'refunded',
                    'refunded_at' => now(),
                ]);
            } elseif ($lockedSource instanceof GuestReportPurchase) {
                $lockedSource->update([
                    'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
                    'refunded_at' => now(),
                ]);
            }

            // Atomic Audit logging
            $this->auditService->log(
                admin: $admin,
                action: 'transaction.refunded',
                targetType: $lockedSource instanceof Transaction ? 'transaction' : 'guest_report_purchase',
                targetId: $lockedSource->id,
                reason: $lockedRecord->reason,
                outcome: 'success',
                metadata: [
                    'refund_record_id' => $lockedRecord->id,
                    'refund_gateway_ref' => $gatewayResult['refund_id'],
                    'amount_minor' => $lockedRecord->amount_minor,
                    'currency' => $lockedRecord->currency,
                    'gateway' => $lockedRecord->gateway,
                    'idempotency_key' => $lockedRecord->idempotency_key,
                ],
                request: $request,
            );

            return $lockedRecord;
        });
    }

    /**
     * Dispatch refund to external provider.
     *
     * @return array{refund_id: string}
     */
    protected function dispatchGatewayRefund(
        string $gateway,
        string $paymentReference,
        int $amountMinor,
        string $currency,
        string $reason,
        string $idempotencyKey,
    ): array {
        if ($gateway === 'stripe') {
            $params = [
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'admin_reason' => substr($reason, 0, 500),
                    'idempotency_key' => $idempotencyKey,
                ],
            ];

            // If reference is charge vs payment_intent
            if (str_starts_with($paymentReference, 'ch_') || str_starts_with($paymentReference, 'py_')) {
                $params['charge'] = $paymentReference;
            } else {
                $params['payment_intent'] = $paymentReference;
            }

            $refund = $this->stripeClient->refunds->create(
                $params,
                ['idempotency_key' => $idempotencyKey]
            );

            return ['refund_id' => (string) $refund->id];
        }

        if ($gateway === 'paypal') {
            $amountDecimal = number_format($amountMinor / 100, 2, '.', '');

            $result = $this->payPalService->refundCapture(
                captureId: $paymentReference,
                amount: $amountDecimal,
                currency: $currency,
                reason: $reason,
                idempotencyKey: $idempotencyKey,
            );

            $refundId = $result['id'] ?? null;

            if (! $refundId) {
                throw new RuntimeException('PayPal refund succeeded without returning refund ID.');
            }

            return ['refund_id' => (string) $refundId];
        }

        throw new RuntimeException("Unsupported payment gateway: {$gateway}");
    }

    /**
     * Handle gateway exception with safe retention policy.
     */
    protected function handleGatewayFailure(
        RefundRecord $refundRecord,
        Transaction|GuestReportPurchase $source,
        User $admin,
        \Throwable $exception,
        ?Request $request = null,
    ): RefundRecord {
        report($exception);

        $isDefinitiveRejection = $this->isDefinitiveGatewayRejection($exception);

        // If NOT definitive (timeout, network glitch, 5xx), KEEP HOLD!
        if (! $isDefinitiveRejection) {
            $refundRecord->update([
                'status' => RefundRecord::STATUS_UNKNOWN,
                'error_message' => 'Gateway timeout or ambiguous response: '.$exception->getMessage(),
            ]);

            $this->auditService->log(
                admin: $admin,
                action: 'transaction.refunded',
                targetType: $source instanceof Transaction ? 'transaction' : 'guest_report_purchase',
                targetId: $source->id,
                reason: $refundRecord->reason,
                outcome: 'unknown',
                metadata: [
                    'refund_record_id' => $refundRecord->id,
                    'error' => $exception->getMessage(),
                    'notice' => 'Credit hold maintained for reconciliation.',
                ],
                request: $request,
            );

            return $refundRecord;
        }

        // PHASE 3B: Reversal (Definitive Gateway Rejection)
        return DB::transaction(function () use (
            $refundRecord,
            $source,
            $admin,
            $exception,
            $request,
        ) {
            $lockedRecord = RefundRecord::query()
                ->whereKey($refundRecord->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedRecord->update([
                'status' => RefundRecord::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);

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

                // Separate immutable reversal ledger row explicitly correlated to RefundRecord
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

            $this->auditService->log(
                admin: $admin,
                action: 'transaction.refunded',
                targetType: $source instanceof Transaction ? 'transaction' : 'guest_report_purchase',
                targetId: $source->id,
                reason: $lockedRecord->reason,
                outcome: 'failed',
                metadata: [
                    'refund_record_id' => $lockedRecord->id,
                    'error' => $exception->getMessage(),
                ],
                request: $request,
            );

            return $lockedRecord;
        });
    }

    /**
     * Determine if an exception represents a definitive gateway rejection vs a transient/ambiguous error.
     */
    protected function isDefinitiveGatewayRejection(\Throwable $e): bool
    {
        if ($e instanceof ApiErrorException) {
            $httpStatus = $e->getHttpStatus() ?: (int) $e->getCode();

            return $httpStatus >= 400 && $httpStatus < 500;
        }

        $code = (int) $e->getCode();
        if ($code >= 400 && $code < 500) {
            return true;
        }

        $message = strtolower($e->getMessage());

        // PayPal or HTTP client client-level error
        if (str_contains($message, 'capture_fully_refunded')
            || str_contains($message, 'invalid_request')
            || str_contains($message, 'unauthorized')
            || str_contains($message, 'transaction_refused')) {
            return true;
        }

        return false;
    }

    /**
     * Eligibility checker for registered customer transactions.
     *
     * @return array{eligible: bool, reason: ?string, credits_to_deduct: int, amount_minor: int, currency: string, payment_reference: string, gateway: string}
     */
    protected function checkRegisteredTransactionEligibility(Transaction $tx): array
    {
        if ($tx->status === 'refunded' || $tx->refunded_at !== null) {
            return [
                'eligible' => false,
                'reason' => 'Transaction has already been refunded.',
                'credits_to_deduct' => 0,
                'amount_minor' => 0,
                'currency' => strtoupper($tx->currency ?: 'GBP'),
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => strtolower($tx->payment_method ?: 'unknown'),
            ];
        }

        if ($tx->status !== 'success') {
            return [
                'eligible' => false,
                'reason' => "Transaction status is {$tx->status}, only successful payments can be refunded.",
                'credits_to_deduct' => 0,
                'amount_minor' => 0,
                'currency' => strtoupper($tx->currency ?: 'GBP'),
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => strtolower($tx->payment_method ?: 'unknown'),
            ];
        }

        if (empty($tx->payment_gateway_ref)) {
            return [
                'eligible' => false,
                'reason' => 'Transaction missing payment gateway reference.',
                'credits_to_deduct' => 0,
                'amount_minor' => 0,
                'currency' => strtoupper($tx->currency ?: 'GBP'),
                'payment_reference' => '',
                'gateway' => strtolower($tx->payment_method ?: 'unknown'),
            ];
        }

        $gateway = strtolower($tx->payment_method ?: '');

        if (! in_array($gateway, ['stripe', 'paypal'], true)) {
            return [
                'eligible' => false,
                'reason' => "Unsupported payment gateway: {$tx->payment_method}",
                'credits_to_deduct' => 0,
                'amount_minor' => 0,
                'currency' => strtoupper($tx->currency ?: 'GBP'),
                'payment_reference' => $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        $currency = strtoupper($tx->currency ?: 'GBP');

        if ($currency !== 'GBP') {
            return [
                'eligible' => false,
                'reason' => "Currency {$currency} is not supported for v1 refund operations.",
                'credits_to_deduct' => 0,
                'amount_minor' => 0,
                'currency' => $currency,
                'payment_reference' => $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        $amountMinor = (int) round(((float) $tx->amount) * 100);

        // Check if an active/completed refund record already exists
        $hasActiveRefund = RefundRecord::query()
            ->where('refundable_type', Transaction::class)
            ->where('refundable_id', $tx->id)
            ->whereIn('status', [
                RefundRecord::STATUS_PROCESSING,
                RefundRecord::STATUS_SUCCESS,
                RefundRecord::STATUS_UNKNOWN,
            ])
            ->exists();

        if ($hasActiveRefund) {
            return [
                'eligible' => false,
                'reason' => 'A refund workflow is already in progress or completed for this transaction.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        $category = $tx->category ?: 'credit_purchase';

        if ($category === 'credit_purchase' || $category === 'payment') {
            return $this->verifyCreditPurchaseLedgerEligibility($tx, $amountMinor, $currency, $gateway);
        }

        if ($category === 'subscription') {
            return $this->verifySubscriptionLedgerEligibility($tx, $amountMinor, $currency, $gateway);
        }

        return [
            'eligible' => false,
            'reason' => "Unsupported transaction category for refund: {$category}",
            'credits_to_deduct' => 0,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payment_reference' => $tx->payment_gateway_ref,
            'gateway' => $gateway,
        ];
    }

    /**
     * Strict fail-closed ledger attribution for credit purchases.
     *
     * @return array{eligible: bool, reason: ?string, credits_to_deduct: int, amount_minor: int, currency: string, payment_reference: string, gateway: string}
     */
    protected function verifyCreditPurchaseLedgerEligibility(
        Transaction $tx,
        int $amountMinor,
        string $currency,
        string $gateway,
    ): array {
        $grantTx = CreditTransaction::query()
            ->where('reference_id', $tx->payment_gateway_ref)
            ->where('type', 'purchase')
            ->first();

        if (! $grantTx) {
            return [
                'eligible' => false,
                'reason' => 'No matching purchase credit ledger entry found for this transaction.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        $creditsGranted = (int) $grantTx->amount;
        $user = $tx->user;

        if (! $user) {
            return [
                'eligible' => false,
                'reason' => 'Associated customer account not found.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        if ((int) $user->credits < $creditsGranted) {
            return [
                'eligible' => false,
                'reason' => "Customer balance ({$user->credits}) is less than credits granted ({$creditsGranted}).",
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        // Strict Untouched Proof: Zero usage after purchase
        $hasUsageAfterPurchase = CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'usage')
            ->where('created_at', '>=', $grantTx->created_at)
            ->exists();

        if ($hasUsageAfterPurchase) {
            return [
                'eligible' => false,
                'reason' => 'Refund ineligible: Credits from this purchase have already been utilized or cannot be proven untouched.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'credits_to_deduct' => $creditsGranted,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payment_reference' => (string) $tx->payment_gateway_ref,
            'gateway' => $gateway,
        ];
    }

    /**
     * Strict fail-closed ledger attribution for subscription payments.
     *
     * @return array{eligible: bool, reason: ?string, credits_to_deduct: int, amount_minor: int, currency: string, payment_reference: string, gateway: string}
     */
    protected function verifySubscriptionLedgerEligibility(
        Transaction $tx,
        int $amountMinor,
        string $currency,
        string $gateway,
    ): array {
        $grantTx = CreditTransaction::query()
            ->where('reference_id', $tx->payment_gateway_ref)
            ->where('type', 'subscription')
            ->first();

        if (! $grantTx) {
            return [
                'eligible' => false,
                'reason' => 'No matching subscription credit ledger entry found for this transaction.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        $creditsGranted = (int) $grantTx->amount;
        $user = $tx->user;

        if (! $user) {
            return [
                'eligible' => false,
                'reason' => 'Associated customer account not found.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        if ((int) $user->credits < $creditsGranted) {
            return [
                'eligible' => false,
                'reason' => "Customer balance ({$user->credits}) is less than subscription credits granted ({$creditsGranted}).",
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        // Strict Untouched Proof: Zero usage after subscription invoice
        $hasUsageAfterInvoice = CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'usage')
            ->where('created_at', '>=', $grantTx->created_at)
            ->exists();

        if ($hasUsageAfterInvoice) {
            return [
                'eligible' => false,
                'reason' => 'Refund ineligible: Subscription benefits have already been utilized.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => (string) $tx->payment_gateway_ref,
                'gateway' => $gateway,
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'credits_to_deduct' => $creditsGranted,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payment_reference' => (string) $tx->payment_gateway_ref,
            'gateway' => $gateway,
        ];
    }

    /**
     * Strict eligibility check for guest report purchases.
     *
     * @return array{eligible: bool, reason: ?string, credits_to_deduct: int, amount_minor: int, currency: string, payment_reference: string, gateway: string}
     */
    protected function checkGuestReportEligibility(GuestReportPurchase $purchase): array
    {
        $currency = strtoupper($purchase->currency ?: 'GBP');
        $gateway = strtolower($purchase->payment_provider ?: 'stripe');
        $paymentRef = (string) ($purchase->provider_payment_id ?: $purchase->provider_order_id);
        $amountMinor = (int) $purchase->amount_minor;

        if ($purchase->payment_status === GuestReportPurchase::STATUS_REFUNDED || $purchase->refunded_at !== null) {
            return [
                'eligible' => false,
                'reason' => 'Guest report purchase has already been refunded.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => $paymentRef,
                'gateway' => $gateway,
            ];
        }

        if ($purchase->payment_status !== GuestReportPurchase::STATUS_PAID) {
            return [
                'eligible' => false,
                'reason' => "Guest report payment status is {$purchase->payment_status}, only paid purchases can be refunded.",
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => $paymentRef,
                'gateway' => $gateway,
            ];
        }

        if ($purchase->fulfilled_at !== null || $purchase->fulfillment_status === GuestReportPurchase::FULFILLMENT_COMPLETED) {
            return [
                'eligible' => false,
                'reason' => 'Refund ineligible: Vehicle report has already been successfully fulfilled and delivered.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => $paymentRef,
                'gateway' => $gateway,
            ];
        }

        if ($purchase->fulfillment_status !== GuestReportPurchase::FULFILLMENT_FAILED) {
            return [
                'eligible' => false,
                'reason' => "Refund ineligible: Fulfillment status is {$purchase->fulfillment_status}. Only permanently failed fulfillments can be refunded.",
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => $paymentRef,
                'gateway' => $gateway,
            ];
        }

        if (empty($purchase->provider_payment_id)) {
            return [
                'eligible' => false,
                'reason' => 'Guest report purchase missing provider payment reference.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => $paymentRef,
                'gateway' => $gateway,
            ];
        }

        $hasActiveRefund = RefundRecord::query()
            ->where('refundable_type', GuestReportPurchase::class)
            ->where('refundable_id', $purchase->id)
            ->whereIn('status', [
                RefundRecord::STATUS_PROCESSING,
                RefundRecord::STATUS_SUCCESS,
                RefundRecord::STATUS_UNKNOWN,
            ])
            ->exists();

        if ($hasActiveRefund) {
            return [
                'eligible' => false,
                'reason' => 'A refund workflow is already in progress or completed for this guest report.',
                'credits_to_deduct' => 0,
                'amount_minor' => $amountMinor,
                'currency' => $currency,
                'payment_reference' => $paymentRef,
                'gateway' => $gateway,
            ];
        }

        return [
            'eligible' => true,
            'reason' => null,
            'credits_to_deduct' => 0,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'payment_reference' => (string) $purchase->provider_payment_id,
            'gateway' => $gateway,
        ];
    }
}
