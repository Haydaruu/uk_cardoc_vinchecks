<?php

namespace App\Services;

use App\Exceptions\PayPalCaptureDeniedException;
use App\Exceptions\PayPalCapturePendingException;
use App\Exceptions\PayPalOrderOwnershipException;
use App\Jobs\SendTelegramSaleNotification;
use App\Models\CreditCheckoutAttempt;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * CreditCaptureService
 *
 * Phase 2 capture orchestration for authenticated PayPal credit purchases.
 *
 * Responsibilities:
 * - Enforce nested capture resource (capture.status === COMPLETED)
 * - Route to snapshot-based validation when a CreditCheckoutAttempt exists
 * - Route to config-based legacy validation for orders created before idempotency
 * - Conditional state transition: attempt must NOT already be completed
 * - Single-call idempotency via CreditService + idempotency_key on credit_transactions
 * - Prevent PENDING captures from granting credits
 * - Prevent duplicate webhook/frontend races from double-granting
 * - Self-healing recovery if crash occurs between credit grant and attempt completion
 */
class CreditCaptureService
{
    public function __construct(
        protected PayPalService $payPalService,
        protected CreditService $creditService,
    ) {}

    /**
     * Reconcile a single attempt that requires capture reconciliation.
     *
     * Rules:
     * 1. Acquires distributed atomic lock on the PayPal order ID.
     * 2. Checks provider order status first via getOrder($orderId).
     * 3. If order is COMPLETED: fulfills immediately from existing nested capture resource (no POST capture).
     * 4. If order is APPROVED and within the 6-hour idempotency window: captures safely using idempotency key capture-{$orderId}.
     * 5. If outcome is ambiguous/APPROVED and window > 6 hours: marks unreconciled_stale to prevent blind captures.
     * 6. If order is VOIDED or cancelled at provider: marks terminal_failed.
     *
     * @return array{reconciled: bool, status: string, attempt_id: string}
     */
    public function reconcileAttempt(CreditCheckoutAttempt $attempt): array
    {
        $orderId = $attempt->provider_order_id;
        if (! $orderId) {
            return [
                'reconciled' => false,
                'status' => 'missing_order_id',
                'attempt_id' => $attempt->attempt_uuid,
            ];
        }

        $lockKey = "paypal-order-capture:{$orderId}";
        $lock = Cache::lock($lockKey, 90);
        $lockReleased = false;

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return [
                'reconciled' => false,
                'status' => 'lock_timeout',
                'attempt_id' => $attempt->attempt_uuid,
            ];
        }

        try {
            $attempt->refresh();

            if ($attempt->status === CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED) {
                return [
                    'reconciled' => true,
                    'status' => 'already_completed',
                    'attempt_id' => $attempt->attempt_uuid,
                ];
            }

            // Step 1: Retrieve current order from PayPal
            try {
                $order = $this->payPalService->getOrder($orderId);
            } catch (\Throwable $e) {
                Log::warning('reconcileAttempt: failed to retrieve order from PayPal', [
                    'order_id' => $orderId,
                    'attempt_uuid' => $attempt->attempt_uuid,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt->isIdempotencyWindowExceeded()) {
                    $attempt->update([
                        'status' => CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE,
                        'failure_reason' => 'Idempotency retention window (> 6 hours) exceeded during failed reconciliation lookup.',
                    ]);
                }

                return [
                    'reconciled' => false,
                    'status' => 'provider_lookup_failed',
                    'attempt_id' => $attempt->attempt_uuid,
                ];
            }

            $orderStatus = strtoupper((string) ($order['status'] ?? ''));

            // Step 2: If order is COMPLETED at provider, fulfill from nested capture resource (never send POST capture)
            if ($orderStatus === 'COMPLETED') {
                $purchaseUnit = $order['purchase_units'][0] ?? null;
                $capture = $purchaseUnit['payments']['captures'][0] ?? null;

                if ($capture && strtoupper((string) ($capture['status'] ?? '')) === 'COMPLETED') {
                    $captureId = (string) ($capture['id'] ?? '');
                    $this->fulfill(
                        order: $order,
                        capture: $capture,
                        captureId: $captureId,
                        orderId: $orderId,
                    );

                    return [
                        'reconciled' => true,
                        'status' => 'fulfilled_completed_order',
                        'attempt_id' => $attempt->attempt_uuid,
                    ];
                }
            }

            // Step 3: If order is APPROVED and within 6 hours, capture safely using captureOrderAndFulfill
            if ($orderStatus === 'APPROVED') {
                if ($attempt->isIdempotencyWindowExceeded()) {
                    // Do NOT send blind capture request when idempotency window is exceeded
                    $attempt->update([
                        'status' => CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE,
                        'failure_reason' => 'PayPal order is APPROVED but idempotency retention window (> 6 hours) has elapsed.',
                    ]);

                    return [
                        'reconciled' => false,
                        'status' => 'unreconciled_stale',
                        'attempt_id' => $attempt->attempt_uuid,
                    ];
                }

                // Within window: execute capture.
                // Release the outer reconciliation lock BEFORE delegating to captureOrderAndFulfill,
                // because captureOrderAndFulfill acquires the same paypal-order-capture:{$orderId}
                // lock internally. Holding both would cause a deadlock (5-second lock timeout).
                $lock->release();
                $lockReleased = true;

                try {
                    $result = $this->captureOrderAndFulfill($orderId, null);

                    return [
                        'reconciled' => $result['capture_status'] === 'COMPLETED',
                        'status' => $result['capture_status'],
                        'attempt_id' => $attempt->attempt_uuid,
                    ];
                } catch (\Throwable $e) {
                    Log::warning('reconcileAttempt: captureOrderAndFulfill failed during APPROVED reconciliation.', [
                        'order_id' => $orderId,
                        'attempt_uuid' => $attempt->attempt_uuid,
                        'error' => $e->getMessage(),
                    ]);

                    return [
                        'reconciled' => false,
                        'status' => 'capture_failed',
                        'attempt_id' => $attempt->attempt_uuid,
                    ];
                }
            }

            // Step 4: If order was VOIDED, cancelled or failed at provider
            if (in_array($orderStatus, ['VOIDED', 'EXPIRED', 'SAVED'], true)) {
                $attempt->update([
                    'status' => CreditCheckoutAttempt::STATUS_TERMINAL_FAILED,
                    'failure_reason' => "PayPal order status at provider is {$orderStatus}.",
                ]);

                return [
                    'reconciled' => true,
                    'status' => 'terminal_failed',
                    'attempt_id' => $attempt->attempt_uuid,
                ];
            }

            return [
                'reconciled' => false,
                'status' => 'unhandled_status:'.$orderStatus,
                'attempt_id' => $attempt->attempt_uuid,
            ];
        } finally {
            if (! $lockReleased) {
                $lock->release();
            }
        }
    }

    /**
     * Identify and reconcile all stuck attempts requiring capture reconciliation.
     *
     * @return array{total_checked: int, reconciled: int, stale: int, failed: int}
     */
    public function reconcileStuckAttempts(): array
    {
        $stuckAttempts = CreditCheckoutAttempt::query()
            ->whereIn('status', [
                CreditCheckoutAttempt::STATUS_CAPTURE_RECONCILIATION_REQUIRED,
                CreditCheckoutAttempt::STATUS_CAPTURING,
            ])
            ->whereNotNull('provider_order_id')
            ->orderBy('id', 'asc')
            ->limit(50)
            ->get();

        $stats = [
            'total_checked' => $stuckAttempts->count(),
            'reconciled' => 0,
            'stale' => 0,
            'failed' => 0,
        ];

        foreach ($stuckAttempts as $attempt) {
            $result = $this->reconcileAttempt($attempt);

            if ($result['reconciled']) {
                $stats['reconciled']++;
            } elseif ($result['status'] === 'unreconciled_stale') {
                $stats['stale']++;
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Unified server-side capture orchestration for both frontend and webhook triggers.
     *
     * Used by:
     * - PayPalController::capture() (frontend user action)
     * - PayPalWebhookController::handleOrderApproved() (webhook CHECKOUT.ORDER.APPROVED)
     *
     * Flow:
     * 1. Concurrency lock on order_id to prevent duplicate capture calls across concurrent workers.
     * 2. Retrieve order from PayPal.
     * 3. Validate ownership, plan, and attempt correlation.
     * 4. If order status is not COMPLETED (e.g. APPROVED): execute PayPalService::captureOrder($orderId).
     * 5. Extract and validate nested capture resource (must be COMPLETED).
     * 6. Delegate to fulfill() for snapshot/legacy fulfillment.
     *
     * @param  User|null  $user  When provided (frontend), strict ownership is checked.
     * @return array{transaction: ?Transaction, credits_granted: int, capture_status: string}
     *
     * @throws PayPalOrderOwnershipException
     * @throws PayPalCapturePendingException
     * @throws RuntimeException
     */
    public function captureOrderAndFulfill(string $orderId, ?User $user = null): array
    {
        // ── Distributed atomic lock per PayPal Order ID (TTL: 90s, Wait: 5s) ──
        $lockKey = "paypal-order-capture:{$orderId}";
        $lock = Cache::lock($lockKey, 90);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new RuntimeException('Capture is already being processed by another request. Please wait and retry.');
        }

        try {
            // Step 1: Retrieve the order from PayPal
            $order = $this->payPalService->getOrder($orderId);
            $purchaseUnit = $order['purchase_units'][0] ?? null;

            if (! $purchaseUnit) {
                throw new RuntimeException('Invalid PayPal order: missing purchase_unit.');
            }

            // Step 2: Skip guest report orders (handled elsewhere)
            $customId = (string) ($purchaseUnit['custom_id'] ?? '');
            if (str_starts_with($customId, 'guest-report|')) {
                Log::info('CreditCaptureService: skipping guest report order in credit capture orchestration.', [
                    'order_id' => $orderId,
                ]);

                return [
                    'transaction' => null,
                    'credits_granted' => 0,
                    'capture_status' => 'SKIPPED_GUEST',
                ];
            }

            // Step 3: Parse custom_id and verify owner
            $parts = explode('|', $customId, 3);
            $orderUserId = $parts[0];
            $planSlug = $parts[1] ?? null;
            $attemptUuid = $parts[2] ?? null;

            if (! $orderUserId || ! $planSlug) {
                throw new RuntimeException('PayPal order metadata is invalid: cannot parse custom_id.');
            }

            if ($user !== null) {
                if ((string) $orderUserId !== (string) $user->id) {
                    throw new PayPalOrderOwnershipException('PayPal order ownership validation failed.');
                }
                $resolvedUser = $user;
            } else {
                $resolvedUser = User::whereKey($orderUserId)->first();
                if (! $resolvedUser) {
                    throw new RuntimeException("PayPal order user [{$orderUserId}] not found.");
                }
            }

            // Step 4: Resolve attempt if present
            $attempt = null;
            if ($attemptUuid !== null && $attemptUuid !== '') {
                $attempt = CreditCheckoutAttempt::query()
                    ->where('user_id', $resolvedUser->id)
                    ->where('attempt_uuid', $attemptUuid)
                    ->first();
            }
            if (! $attempt) {
                $attempt = CreditCheckoutAttempt::query()
                    ->where('user_id', $resolvedUser->id)
                    ->where('provider_order_id', $orderId)
                    ->first();
            }

            // If attempt is already COMPLETED, return existing transaction idempotently without POST capture
            if ($attempt && $attempt->status === CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED) {
                Log::info('CreditCaptureService: attempt already captured before captureOrder call, returning existing.', [
                    'attempt_uuid' => $attempt->attempt_uuid,
                    'order_id' => $orderId,
                ]);

                $existing = $this->resolveExistingCapture($attempt, $attempt->provider_capture_id ?? '');

                return array_merge($existing, ['capture_status' => 'COMPLETED']);
            }

            if ($attempt && $attempt->status === CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE) {
                throw new RuntimeException("Attempt is in terminal status '{$attempt->status}' and cannot be captured.");
            }

            // Step 5: Capture if not already COMPLETED at provider
            if (($order['status'] ?? null) === 'COMPLETED') {
                $capturedOrder = $order;
            } else {
                if ($attempt) {
                    CreditCheckoutAttempt::query()
                        ->where('id', $attempt->id)
                        ->whereNotIn('status', [CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED])
                        ->update(['status' => CreditCheckoutAttempt::STATUS_CAPTURING]);
                }

                $capturedOrder = $this->payPalService->captureOrder($orderId);
            }

            // Step 6: Validate nested capture resource
            $capturedPurchaseUnit = $capturedOrder['purchase_units'][0] ?? null;
            $capture = $capturedPurchaseUnit['payments']['captures'][0] ?? null;

            if (! $capture) {
                throw new RuntimeException('PayPal capture resource missing.');
            }

            $captureStatus = strtoupper((string) ($capture['status'] ?? ''));
            $captureId = (string) ($capture['id'] ?? '');

            if ($captureStatus !== 'COMPLETED') {
                if ($captureStatus === 'PENDING') {
                    if ($attempt) {
                        CreditCheckoutAttempt::query()
                            ->where('id', $attempt->id)
                            ->whereNotIn('status', [CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED])
                            ->update([
                                'status' => CreditCheckoutAttempt::STATUS_CAPTURE_PENDING,
                                'provider_capture_id' => $captureId !== '' ? $captureId : null,
                            ]);
                    }

                    $reason = (string) ($capture['status_details']['reason'] ?? 'PENDING');

                    throw new PayPalCapturePendingException(
                        "PayPal payment capture has status 'PENDING' (reason: {$reason}) and cannot be fulfilled immediately.",
                        'PENDING'
                    );
                }

                if (in_array($captureStatus, ['DECLINED', 'FAILED', 'DENIED'], true)) {
                    if ($attempt) {
                        CreditCheckoutAttempt::query()
                            ->where('id', $attempt->id)
                            ->whereNotIn('status', [CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED])
                            ->update([
                                'status' => CreditCheckoutAttempt::STATUS_TERMINAL_FAILED,
                                'failure_reason' => "PayPal capture returned status: {$captureStatus}",
                            ]);
                    }

                    throw new PayPalCaptureDeniedException(
                        "PayPal payment capture has status '{$captureStatus}' and was not completed.",
                        $captureStatus
                    );
                }

                if (in_array($captureStatus, ['REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
                    throw new RuntimeException("PayPal payment capture has status '{$captureStatus}' and cannot be fulfilled as a new purchase.");
                }

                throw new RuntimeException("PayPal payment capture has unexpected status '{$captureStatus}'.");
            }

            if ($captureId === '') {
                throw new RuntimeException('PayPal capture ID is missing.');
            }

            // Step 7: Delegate to fulfill() for snapshot/legacy fulfillment
            $fulfillment = $this->fulfill(
                order: $capturedOrder,
                capture: $capture,
                captureId: $captureId,
                orderId: $orderId,
            );

            return array_merge($fulfillment, ['capture_status' => 'COMPLETED']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Fulfill a credit purchase from a verified PayPal PAYMENT.CAPTURE.COMPLETED event
     * or a frontend capture response. Used by both PayPalController::capture() and
     * PayPalWebhookController::handleOneTimePayment().
     *
     * @param  array<string, mixed>  $order  Full PayPal order response
     * @param  array<string, mixed>  $capture  Nested capture from purchase_units[0].payments.captures[0]
     * @param  string  $captureId  Capture ID (pre-validated by caller)
     * @param  string  $orderId  PayPal Order ID
     * @return array{transaction: Transaction, credits_granted: int}
     *
     * @throws RuntimeException
     */
    public function fulfill(
        array $order,
        array $capture,
        string $captureId,
        string $orderId,
    ): array {
        // Enforce nested capture status === COMPLETED at service boundary
        if (($capture['status'] ?? null) !== 'COMPLETED') {
            throw new RuntimeException("PayPal payment capture has status '".($capture['status'] ?? 'UNKNOWN')."' and is not COMPLETED.");
        }

        $purchaseUnit = $order['purchase_units'][0] ?? null;

        if (! $purchaseUnit) {
            throw new RuntimeException('Invalid PayPal order: missing purchase_unit.');
        }

        $customId = (string) ($purchaseUnit['custom_id'] ?? '');

        // Parse custom_id: format may be user_id|plan_slug (legacy) or user_id|plan_slug|attempt_uuid (Phase 1+)
        $parts = explode('|', $customId, 3);
        $userId = $parts[0];
        $planSlug = $parts[1] ?? null;
        $attemptUuid = $parts[2] ?? null;

        if (! $userId || ! $planSlug) {
            throw new RuntimeException('PayPal order metadata is invalid: cannot parse custom_id.');
        }

        // Verify reference_id (plan slug duplication in purchase_unit)
        $referenceId = (string) ($purchaseUnit['reference_id'] ?? '');
        if ($referenceId !== $planSlug) {
            throw new RuntimeException('PayPal order plan slug mismatch between custom_id and reference_id.');
        }

        $user = User::whereKey($userId)->first();

        if (! $user) {
            throw new RuntimeException('PayPal order: user not found.');
        }

        // ── Attempt-based path (Phase 1+ orders) ──────────────────────────────
        if ($attemptUuid !== null && $attemptUuid !== '') {
            return $this->fulfillWithAttempt(
                user: $user,
                attempt: $this->resolveAttempt($user, $orderId, $attemptUuid),
                capture: $capture,
                captureId: $captureId,
                orderId: $orderId,
            );
        }

        // Fallback: Check if an attempt exists for this provider_order_id
        $existingAttempt = CreditCheckoutAttempt::query()
            ->where('user_id', $user->id)
            ->where('provider_order_id', $orderId)
            ->first();

        if ($existingAttempt) {
            return $this->fulfillWithAttempt(
                user: $user,
                attempt: $existingAttempt,
                capture: $capture,
                captureId: $captureId,
                orderId: $orderId,
            );
        }

        // ── Legacy path (orders created before idempotency rollout) ───────────
        return $this->fulfillLegacy(
            user: $user,
            planSlug: $planSlug,
            capture: $capture,
            captureId: $captureId,
            orderId: $orderId,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Attempt-based capture (Phase 1+ orders)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $capture
     * @return array{transaction: Transaction, credits_granted: int}
     */
    public function fulfillWithAttempt(
        User $user,
        CreditCheckoutAttempt $attempt,
        array $capture,
        string $captureId,
        string $orderId,
    ): array {
        // Guard: ownership
        if ((int) $attempt->user_id !== (int) $user->id) {
            throw new RuntimeException('Attempt user mismatch.');
        }

        // Guard: attempt must reference this exact PayPal order if already set
        if ($attempt->provider_order_id !== null && $attempt->provider_order_id !== $orderId) {
            throw new RuntimeException('Attempt order ID mismatch.');
        }

        // Guard: prevent double fulfillment via attempt state
        if ($attempt->status === CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED) {
            Log::info('CreditCaptureService: attempt already captured, skipping.', [
                'attempt_uuid' => $attempt->attempt_uuid,
                'capture_id' => $captureId,
            ]);

            return $this->resolveExistingCapture($attempt, $captureId);
        }

        // Guard: reject terminal stale status
        if ($attempt->status === CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE) {
            throw new RuntimeException("Attempt is in terminal status '{$attempt->status}' and cannot be captured.");
        }

        // ── Concurrency lock (90s TTL, 5s block) ─────────────────────────────
        // Cache lock is NOT the sole protection — the conditional DB state
        // transition below and credit_transactions.idempotency_key unique
        // constraint both independently prevent double-grant.
        $lockKey = "paypal-capture:attempt:{$attempt->attempt_uuid}";
        $lock = Cache::lock($lockKey, 90);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            $attempt->refresh();
            if ($attempt->status === CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED && $attempt->provider_capture_id) {
                return $this->resolveExistingCapture($attempt, $captureId);
            }

            throw new RuntimeException('Capture is being processed by another request. Please wait and retry.');
        }

        try {
            // Reload fresh state inside lock
            $attempt->refresh();

            if ($attempt->status === CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED) {
                return $this->resolveExistingCapture($attempt, $captureId);
            }

            // ── Snapshot-based amount validation ─────────────────────────────
            $expectedAmount = number_format($attempt->amount_minor / 100, 2, '.', '');
            $expectedCurrency = strtoupper($attempt->currency);

            $this->assertCaptureAmounts($capture, $expectedAmount, $expectedCurrency, $captureId);

            // ── Persisted state transition: mark as capturing ────────────────
            CreditCheckoutAttempt::query()
                ->where('id', $attempt->id)
                ->whereNotIn('status', [CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED])
                ->update([
                    'status' => CreditCheckoutAttempt::STATUS_CAPTURING,
                    'provider_capture_id' => $captureId,
                    'provider_order_id' => $orderId,
                ]);

            // ── Grant credits using snapshot ──────────────────────────────────
            $creditsToGrant = $attempt->credits_to_grant;
            $idempotencyKey = "capture-{$orderId}";

            $creditTx = $this->creditService->grantCredits(
                user: $user,
                amount: $creditsToGrant,
                type: 'purchase',
                referenceId: $captureId,
                idempotencyKey: $idempotencyKey,
                description: "PayPal purchase: {$captureId}",
            );

            // ── Record transaction ────────────────────────────────────────────
            $paidAt = isset($capture['create_time']) ? Carbon::parse($capture['create_time']) : now();

            $transaction = Transaction::updateOrCreate(
                ['payment_gateway_ref' => $captureId],
                [
                    'user_id' => $user->id,
                    'invoice_id' => $attempt->invoice_id,
                    'currency' => strtoupper($capture['amount']['currency_code'] ?? $expectedCurrency),
                    'amount' => $capture['amount']['value'] ?? $expectedAmount,
                    'type' => 'payment',
                    'category' => 'credit_purchase',
                    'payment_method' => 'paypal',
                    'description' => config("credit_plans.{$attempt->plan_slug}.label", $attempt->plan_slug),
                    'status' => 'success',
                    'paid_at' => $paidAt,
                ],
            );

            // ── Persisted state transition: mark capture_completed ───────────
            CreditCheckoutAttempt::query()
                ->where('id', $attempt->id)
                ->update([
                    'status' => CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
                    'provider_capture_id' => $captureId,
                    'provider_order_id' => $orderId,
                ]);

            if ($transaction->wasRecentlyCreated && (float) $transaction->amount > 0) {
                $this->dispatchSaleNotification($transaction);
            }

            Log::info('CreditCaptureService: credits granted via attempt.', [
                'attempt_uuid' => $attempt->attempt_uuid,
                'capture_id' => $captureId,
                'credits' => $creditsToGrant,
                'idempotency_key' => $idempotencyKey,
                'credit_transaction_id' => $creditTx->id,
            ]);

            return ['transaction' => $transaction, 'credits_granted' => $creditsToGrant];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{transaction: Transaction, credits_granted: int}
     */
    private function resolveExistingCapture(CreditCheckoutAttempt $attempt, string $captureId): array
    {
        $transaction = Transaction::where('payment_gateway_ref', $captureId)->first()
            ?? Transaction::where('payment_gateway_ref', $attempt->provider_capture_id)->first();

        if (! $transaction) {
            $paidAt = now();
            $transaction = Transaction::updateOrCreate(
                ['payment_gateway_ref' => $captureId],
                [
                    'user_id' => $attempt->user_id,
                    'invoice_id' => $attempt->invoice_id,
                    'currency' => $attempt->currency,
                    'amount' => number_format($attempt->amount_minor / 100, 2, '.', ''),
                    'type' => 'payment',
                    'category' => 'credit_purchase',
                    'payment_method' => 'paypal',
                    'description' => config("credit_plans.{$attempt->plan_slug}.label", $attempt->plan_slug),
                    'status' => 'success',
                    'paid_at' => $paidAt,
                ],
            );
        }

        return ['transaction' => $transaction, 'credits_granted' => $attempt->credits_to_grant];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Legacy capture (orders created before idempotency rollout)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $capture
     * @return array{transaction: Transaction, credits_granted: int}
     */
    private function fulfillLegacy(
        User $user,
        string $planSlug,
        array $capture,
        string $captureId,
        string $orderId,
    ): array {
        $plan = config("credit_plans.{$planSlug}");

        if (! $plan || ($plan['type'] ?? null) !== 'one_time') {
            throw new RuntimeException("Legacy capture: invalid credit plan '{$planSlug}'.");
        }

        $expectedAmount = number_format($plan['amount_minor'] / 100, 2, '.', '');
        $expectedCurrency = strtoupper($plan['currency']);

        $this->assertCaptureAmounts($capture, $expectedAmount, $expectedCurrency, $captureId);

        $creditsToGrant = (int) $plan['credits'];
        $idempotencyKey = "capture-{$orderId}";

        $creditTx = $this->creditService->grantCredits(
            user: $user,
            amount: $creditsToGrant,
            type: 'purchase',
            referenceId: $captureId,
            idempotencyKey: $idempotencyKey,
            description: "PayPal purchase: {$captureId}",
        );

        $paidAt = isset($capture['create_time']) ? Carbon::parse($capture['create_time']) : now();
        $invoiceId = 'UKC-PPL-'.strtoupper(substr($captureId, 0, 8));

        $transaction = Transaction::updateOrCreate(
            ['payment_gateway_ref' => $captureId],
            [
                'user_id' => $user->id,
                'invoice_id' => $invoiceId,
                'currency' => strtoupper($capture['amount']['currency_code'] ?? $expectedCurrency),
                'amount' => $capture['amount']['value'] ?? $expectedAmount,
                'type' => 'payment',
                'category' => 'credit_purchase',
                'payment_method' => 'paypal',
                'description' => $plan['label'],
                'status' => 'success',
                'paid_at' => $paidAt,
            ],
        );

        if ($transaction->wasRecentlyCreated && (float) $transaction->amount > 0) {
            $this->dispatchSaleNotification($transaction);
        }

        Log::info('CreditCaptureService: credits granted via legacy path.', [
            'plan_slug' => $planSlug,
            'capture_id' => $captureId,
            'credits' => $creditsToGrant,
            'idempotency_key' => $idempotencyKey,
            'credit_transaction_id' => $creditTx->id,
        ]);

        return ['transaction' => $transaction, 'credits_granted' => $creditsToGrant];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Shared helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the CreditCheckoutAttempt for a Phase 1+ order.
     * Accepts lookup by attempt_uuid (from custom_id) or by provider_order_id.
     */
    private function resolveAttempt(User $user, string $orderId, string $attemptUuid): CreditCheckoutAttempt
    {
        // Primary lookup: by attempt UUID parsed from custom_id
        $attempt = CreditCheckoutAttempt::query()
            ->where('user_id', $user->id)
            ->where('attempt_uuid', $attemptUuid)
            ->first();

        if ($attempt) {
            return $attempt;
        }

        // Fallback: by provider_order_id
        $attempt = CreditCheckoutAttempt::query()
            ->where('user_id', $user->id)
            ->where('provider_order_id', $orderId)
            ->first();

        if ($attempt) {
            return $attempt;
        }

        throw new RuntimeException("CreditCheckoutAttempt not found for order {$orderId} / uuid {$attemptUuid}.");
    }

    /**
     * Assert that a capture resource matches expected amount and currency.
     *
     * @param  array<string, mixed>  $capture
     *
     * @throws RuntimeException
     */
    private function assertCaptureAmounts(
        array $capture,
        string $expectedAmount,
        string $expectedCurrency,
        string $captureId,
    ): void {
        $captureAmount = (string) ($capture['amount']['value'] ?? '');
        $captureCurrency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));

        if ($captureAmount !== $expectedAmount || $captureCurrency !== $expectedCurrency) {
            Log::critical('CreditCaptureService: capture amount mismatch.', [
                'capture_id' => $captureId,
                'expected_amount' => $expectedAmount,
                'received_amount' => $captureAmount,
                'expected_currency' => $expectedCurrency,
                'received_currency' => $captureCurrency,
            ]);

            throw new RuntimeException(
                "PayPal capture amount mismatch: expected {$expectedCurrency} {$expectedAmount}, "
                ."got {$captureCurrency} {$captureAmount}."
            );
        }
    }

    private function dispatchSaleNotification(Transaction $transaction): void
    {
        $amount = number_format((float) $transaction->amount, 2, '.', ',');

        SendTelegramSaleNotification::dispatch(
            'Credit purchase',
            'PayPal',
            (string) $transaction->payment_gateway_ref,
            strtoupper((string) $transaction->currency).' '.$amount,
        )->afterCommit();
    }
}
