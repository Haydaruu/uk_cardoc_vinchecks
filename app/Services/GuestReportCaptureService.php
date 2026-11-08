<?php

namespace App\Services;

use App\Exceptions\PayPalApiException;
use App\Exceptions\PayPalGuestOrderMismatchException;
use App\Models\GuestReportCaptureAttempt;
use App\Models\GuestReportPurchase;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class GuestReportCaptureService
{
    public function __construct(
        protected PayPalService $payPalService,
        protected GuestReportPaymentService $paymentService,
        protected GuestReportCheckoutService $checkoutService,
        protected ?TelegramNotificationService $telegramNotificationService = null,
    ) {}

    /**
     * Unified capture orchestration method.
     */
    public function captureAndFulfill(
        string $orderId,
        CaptureIntent $intent = CaptureIntent::INITIAL_BUYER_CAPTURE,
    ): GuestCaptureResult {
        $lock = Cache::lock("paypal-order-capture:{$orderId}", 85);
        $acquired = false;

        try {
            $lock->block(5);
            $acquired = true;
        } catch (LockTimeoutException) {
            return new GuestCaptureResult(
                status: 'processing',
                errorMessage: 'Payment capture is currently being processed by another worker. Please wait.',
                httpStatus: 202,
            );
        }

        try {
            return $this->orchestrateCapture($orderId, $intent);
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }

    public function captureInitialBuyer(string $orderId): GuestCaptureResult
    {
        return $this->captureAndFulfill($orderId, CaptureIntent::INITIAL_BUYER_CAPTURE);
    }

    public function captureFromWebhook(string $orderId): GuestCaptureResult
    {
        return $this->captureAndFulfill($orderId, CaptureIntent::WEBHOOK_CAPTURE);
    }

    public function retryFunding(string $orderId): GuestCaptureResult
    {
        return $this->captureAndFulfill($orderId, CaptureIntent::EXPLICIT_FUNDING_RETRY);
    }

    public function continuePayerAction(string $orderId): GuestCaptureResult
    {
        return $this->captureAndFulfill($orderId, CaptureIntent::PAYER_ACTION_CONTINUATION);
    }

    public function replayAmbiguousAttempt(string $orderId): GuestCaptureResult
    {
        return $this->captureAndFulfill($orderId, CaptureIntent::AMBIGUOUS_REPLAY);
    }

    public function reconcileGetOnly(string $orderId): GuestCaptureResult
    {
        return $this->captureAndFulfill($orderId, CaptureIntent::RECONCILIATION_GET_ONLY);
    }

    /**
     * Core orchestration executed while holding the distributed lock.
     * Note: DB transactions are strictly short-lived and NEVER held open during HTTP requests.
     */
    protected function orchestrateCapture(string $orderId, CaptureIntent $intent): GuestCaptureResult
    {
        // ── Phase 1: Evaluate state and prepare/select attempt inside a short DB transaction ──
        $eval = DB::transaction(function () use ($orderId, $intent) {
            $purchase = GuestReportPurchase::query()
                ->where('payment_provider', 'paypal')
                ->where('provider_order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if (! $purchase) {
                return [
                    'early_result' => new GuestCaptureResult(
                        status: 'failed',
                        errorMessage: 'Guest report purchase record not found for provider order.',
                        httpStatus: 404,
                    ),
                ];
            }

            // 1. Terminal Payment Status Guards
            if ($purchase->payment_status === GuestReportPurchase::STATUS_PAID) {
                if ($purchase->capture_status === GuestReportPurchase::CAPTURE_COMPLETED && $purchase->provider_payment_id) {
                    return [
                        'early_result' => new GuestCaptureResult(
                            status: 'completed',
                            redirect: $this->buildSuccessUrl($purchase),
                            reconciled: true,
                            httpStatus: 200,
                        ),
                    ];
                }

                // Paid without complete capture proof -> strictly GET reconciliation only!
                return [
                    'purchase_id' => $purchase->id,
                    'is_paid_needs_proof' => true,
                    'allow_post' => false,
                    'attempt_id' => null,
                    'idempotency_key' => null,
                ];
            }

            if (in_array($purchase->payment_status, [
                GuestReportPurchase::PAYMENT_STATUS_FAILED,
                GuestReportPurchase::STATUS_EXPIRED,
                GuestReportPurchase::STATUS_REFUNDED,
            ], true)) {
                return [
                    'early_result' => new GuestCaptureResult(
                        status: 'failed',
                        errorMessage: "Purchase is in terminal status '{$purchase->payment_status}' and cannot be captured.",
                        httpStatus: 422,
                    ),
                ];
            }

            // 2. Terminal Capture Status Guards
            if ($purchase->capture_status === GuestReportPurchase::CAPTURE_COMPLETED) {
                return [
                    'early_result' => new GuestCaptureResult(
                        status: 'completed',
                        redirect: $this->buildSuccessUrl($purchase),
                        reconciled: true,
                        httpStatus: 200,
                    ),
                ];
            }

            if ($purchase->capture_status === GuestReportPurchase::CAPTURE_STATUS_FAILED) {
                return [
                    'early_result' => new GuestCaptureResult(
                        status: 'failed',
                        errorMessage: 'Capture has permanently failed: '.($purchase->capture_failure_reason ?? 'unknown reason'),
                        httpStatus: 422,
                    ),
                ];
            }

            // Resolve existing active attempt if present
            $activeAttempt = null;
            if ($purchase->active_capture_attempt_uuid) {
                $activeAttempt = GuestReportCaptureAttempt::query()
                    ->where('attempt_uuid', $purchase->active_capture_attempt_uuid)
                    ->lockForUpdate()
                    ->first();
            }
            if (! $activeAttempt) {
                $activeAttempt = $purchase->captureAttempts()
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
            }

            // 3. State & Intent Permission Evaluation (§4 canonical rules)

            // Explicit Funding Retry
            if ($intent === CaptureIntent::EXPLICIT_FUNDING_RETRY) {
                if ($purchase->capture_status !== GuestReportPurchase::CAPTURE_FUNDING_DECLINED) {
                    return [
                        'early_result' => new GuestCaptureResult(
                            status: 'failed',
                            errorMessage: 'Explicit funding retry is only valid after previous funding was declined.',
                            httpStatus: 422,
                        ),
                    ];
                }

                $attemptNumber = ($activeAttempt ? $activeAttempt->attempt_number : $purchase->captureAttempts()->count()) + 1;
                $attemptUuid = (string) Str::uuid();
                $idempotencyKey = "guest-cap-{$attemptUuid}";

                $newAttempt = GuestReportCaptureAttempt::create([
                    'guest_report_purchase_id' => $purchase->id,
                    'attempt_uuid' => $attemptUuid,
                    'idempotency_key' => $idempotencyKey,
                    'provider_order_id' => $orderId,
                    'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
                    'attempt_number' => $attemptNumber,
                    'request_started_at' => now(),
                ]);

                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
                    'capture_attempts' => $purchase->capture_attempts + 1,
                    'capture_request_started_at' => now(),
                    'active_capture_attempt_uuid' => $attemptUuid,
                    'capture_failure_reason' => null,
                ]);

                return [
                    'purchase_id' => $purchase->id,
                    'attempt_id' => $newAttempt->id,
                    'idempotency_key' => $idempotencyKey,
                    'allow_post' => true,
                    'is_paid_needs_proof' => false,
                ];
            }

            // Payer-Action Continuation
            if ($intent === CaptureIntent::PAYER_ACTION_CONTINUATION) {
                if ($purchase->capture_status !== GuestReportPurchase::CAPTURE_PAYER_ACTION_REQUIRED) {
                    return [
                        'early_result' => new GuestCaptureResult(
                            status: 'failed',
                            errorMessage: 'Payer-action continuation is only valid when purchase is waiting for payer action.',
                            httpStatus: 422,
                        ),
                    ];
                }

                if (! $activeAttempt) {
                    return [
                        'early_result' => new GuestCaptureResult(
                            status: 'failed',
                            errorMessage: 'No active attempt found for payer-action continuation.',
                            httpStatus: 422,
                        ),
                    ];
                }

                $activeAttempt->update([
                    'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
                    'request_started_at' => $activeAttempt->request_started_at ?? now(),
                ]);
                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
                    'capture_request_started_at' => $purchase->capture_request_started_at ?? now(),
                ]);

                return [
                    'purchase_id' => $purchase->id,
                    'attempt_id' => $activeAttempt->id,
                    'idempotency_key' => $activeAttempt->idempotency_key,
                    'allow_post' => true,
                    'is_paid_needs_proof' => false,
                ];
            }

            // GET-only states: capture_pending, capture_reconciliation_required, legacy_untracked
            if ($purchase->capture_status === GuestReportPurchase::CAPTURE_PENDING) {
                return [
                    'purchase_id' => $purchase->id,
                    'attempt_id' => $activeAttempt?->id,
                    'idempotency_key' => $activeAttempt?->idempotency_key,
                    'allow_post' => false,
                    'is_paid_needs_proof' => false,
                ];
            }

            if ($purchase->capture_status === GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED
                || $purchase->capture_status === GuestReportPurchase::CAPTURE_LEGACY_UNTRACKED
                || $intent === CaptureIntent::RECONCILIATION_GET_ONLY
            ) {
                return [
                    'purchase_id' => $purchase->id,
                    'attempt_id' => $activeAttempt?->id,
                    'idempotency_key' => $activeAttempt?->idempotency_key,
                    'allow_post' => false,
                    'is_paid_needs_proof' => false,
                ];
            }

            // If in funding_declined or payer_action_required without corresponding intent -> GET-only
            if ($purchase->capture_status === GuestReportPurchase::CAPTURE_FUNDING_DECLINED
                || $purchase->capture_status === GuestReportPurchase::CAPTURE_PAYER_ACTION_REQUIRED
            ) {
                return [
                    'purchase_id' => $purchase->id,
                    'attempt_id' => $activeAttempt?->id,
                    'idempotency_key' => $activeAttempt?->idempotency_key,
                    'allow_post' => false,
                    'is_paid_needs_proof' => false,
                ];
            }

            // If already capturing: check 6-hour policy for ambiguous retry replay
            if ($purchase->capture_status === GuestReportPurchase::CAPTURE_CAPTURING) {
                $isFresh = $this->isAttemptWithinSixHours($activeAttempt);
                if (! $isFresh) {
                    // Expired or unestablished attempt window: do NOT issue POST!
                    return [
                        'purchase_id' => $purchase->id,
                        'attempt_id' => $activeAttempt?->id,
                        'idempotency_key' => $activeAttempt?->idempotency_key,
                        'allow_post' => false,
                        'is_paid_needs_proof' => false,
                    ];
                }

                // Permitted replay of active attempt
                return [
                    'purchase_id' => $purchase->id,
                    'attempt_id' => $activeAttempt->id,
                    'idempotency_key' => $activeAttempt->idempotency_key,
                    'allow_post' => true,
                    'is_paid_needs_proof' => false,
                ];
            }

            // Initial capture for newly approved order: capture_status === NULL
            if ($purchase->capture_status === null) {
                $attemptUuid = (string) Str::uuid();
                $idempotencyKey = "guest-cap-{$attemptUuid}";

                $newAttempt = GuestReportCaptureAttempt::create([
                    'guest_report_purchase_id' => $purchase->id,
                    'attempt_uuid' => $attemptUuid,
                    'idempotency_key' => $idempotencyKey,
                    'provider_order_id' => $orderId,
                    'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
                    'attempt_number' => 1,
                    'request_started_at' => now(),
                ]);

                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
                    'capture_attempts' => 1,
                    'capture_request_started_at' => now(),
                    'active_capture_attempt_uuid' => $attemptUuid,
                    'capture_failure_reason' => null,
                ]);

                return [
                    'purchase_id' => $purchase->id,
                    'attempt_id' => $newAttempt->id,
                    'idempotency_key' => $idempotencyKey,
                    'allow_post' => true,
                    'is_paid_needs_proof' => false,
                ];
            }

            return [
                'purchase_id' => $purchase->id,
                'attempt_id' => $activeAttempt?->id,
                'idempotency_key' => $activeAttempt?->idempotency_key,
                'allow_post' => false,
                'is_paid_needs_proof' => false,
            ];
        });

        if (isset($eval['early_result']) && $eval['early_result'] instanceof GuestCaptureResult) {
            return $eval['early_result'];
        }

        // ── Phase 2: HTTP Provider Interactions (strictly outside DB transactions) ──
        $purchase = GuestReportPurchase::findOrFail($eval['purchase_id']);
        $attempt = $eval['attempt_id'] ? GuestReportCaptureAttempt::find($eval['attempt_id']) : null;

        if (! empty($eval['is_paid_needs_proof'])) {
            return $this->verifyPaidRecordProof($purchase, $orderId);
        }

        try {
            $order = $this->payPalService->getOrder($orderId);
            $order = $this->payPalService->prepareGuestReportOrderForCapture($purchase, $order);
        } catch (PayPalGuestOrderMismatchException $e) {
            Log::warning("PayPal guest order mismatch for order {$orderId}", ['exception' => $e]);

            return new GuestCaptureResult(
                status: 'failed',
                errorMessage: 'PayPal order validation failed.',
                httpStatus: 403,
            );
        } catch (\Throwable $e) {
            Log::error("Failed to retrieve or prepare PayPal order {$orderId}", ['exception' => $e]);

            return new GuestCaptureResult(
                status: 'failed',
                errorMessage: 'Unable to communicate with payment provider.',
                httpStatus: 500,
            );
        }

        $orderStatus = (string) ($order['status'] ?? '');

        // 1. Order already COMPLETED at provider
        if ($orderStatus === 'COMPLETED') {
            return $this->settleSuccessfulCapture($order, $purchase, $attempt, isReconciliation: true);
        }

        // 2. Order VOIDED at provider
        if ($orderStatus === 'VOIDED') {
            DB::transaction(function () use ($purchase, $attempt) {
                $attempt?->update([
                    'status' => GuestReportCaptureAttempt::STATUS_FAILED,
                    'failure_reason' => 'PayPal order was VOIDED.',
                ]);
                $purchase->update([
                    'payment_status' => GuestReportPurchase::PAYMENT_STATUS_FAILED,
                    'capture_status' => GuestReportPurchase::CAPTURE_STATUS_FAILED,
                    'failed_at' => now(),
                    'capture_failure_reason' => 'PayPal order was VOIDED.',
                    'last_reconciled_at' => now(),
                ]);
            });

            return new GuestCaptureResult(
                status: 'failed',
                errorMessage: 'Payment order was voided by provider.',
                httpStatus: 422,
            );
        }

        // 3. Order APPROVED at provider
        if ($orderStatus === 'APPROVED') {
            if ($eval['allow_post']) {
                try {
                    $capturedOrder = $this->payPalService->captureOrder($orderId, $eval['idempotency_key']);

                    return $this->handleCaptureResponse($capturedOrder, $purchase, $attempt, $orderId);
                } catch (PayPalApiException $e) {
                    return $this->handlePayPalApiException($e, $purchase, $attempt, $orderId);
                } catch (\Throwable $e) {
                    DB::transaction(function () use ($purchase, $attempt, $e) {
                        $attempt?->update([
                            'status' => GuestReportCaptureAttempt::STATUS_ERROR,
                            'failure_reason' => $e->getMessage(),
                        ]);
                        $purchase->update([
                            'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
                            'capture_failure_reason' => 'Network error during capture: '.$e->getMessage(),
                        ]);
                    });

                    return new GuestCaptureResult(
                        status: 'failed',
                        errorMessage: 'Payment processing error.',
                        httpStatus: 500,
                    );
                }
            }

            // Order is APPROVED but POST is not permitted (GET-only operation)
            if ($purchase->capture_status === GuestReportPurchase::CAPTURE_PENDING) {
                $purchase->update([
                    'last_reconciled_at' => now(),
                ]);

                return new GuestCaptureResult(
                    status: 'processing',
                    errorMessage: 'Payment is pending bank clearance.',
                    reconciled: true,
                    httpStatus: 202,
                );
            }

            $purchase->update([
                'last_reconciled_at' => now(),
            ]);

            return new GuestCaptureResult(
                status: 'processing',
                errorMessage: 'Order is approved and awaiting capture.',
                reconciled: true,
                httpStatus: 202,
            );
        }

        // 4. Order not yet approved
        if ($orderStatus === 'CREATED') {
            return new GuestCaptureResult(
                status: 'failed',
                errorMessage: 'PayPal order has not been approved yet.',
                httpStatus: 422,
            );
        }

        return new GuestCaptureResult(
            status: 'failed',
            errorMessage: "Unexpected PayPal order status: {$orderStatus}",
            httpStatus: 422,
        );
    }

    /**
     * Handles successful HTTP response from captureOrder().
     */
    protected function handleCaptureResponse(
        array $capturedOrder,
        GuestReportPurchase $purchase,
        ?GuestReportCaptureAttempt $attempt,
        string $orderId,
    ): GuestCaptureResult {
        $purchaseUnit = $capturedOrder['purchase_units'][0] ?? null;
        $captures = $purchaseUnit['payments']['captures'] ?? [];
        $completedCapture = collect($captures)->firstWhere('status', 'COMPLETED');
        $pendingCapture = collect($captures)->firstWhere('status', 'PENDING');

        // Handle PENDING capture status (eCheck, delayed settlement)
        if ($pendingCapture && ! $completedCapture) {
            DB::transaction(function () use ($purchase, $attempt, $pendingCapture) {
                $attempt?->update([
                    'status' => GuestReportCaptureAttempt::STATUS_PENDING,
                ]);
                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_PENDING,
                    'provider_payment_id' => $pendingCapture['id'],
                    'capture_failure_reason' => 'Payment pending: '.($pendingCapture['status_details']['reason'] ?? 'eCheck/processing'),
                ]);
            });

            return new GuestCaptureResult(
                status: 'processing',
                errorMessage: 'Payment is pending bank clearance.',
                reconciled: false,
                httpStatus: 202,
            );
        }

        // Handle malformed/missing capture response -> fallback GET order
        if (! $completedCapture || ! is_string($completedCapture['id'] ?? null) || trim($completedCapture['id']) === '') {
            $fallbackOrder = $this->payPalService->getOrder($orderId);
            if (($fallbackOrder['status'] ?? null) === 'COMPLETED') {
                return $this->settleSuccessfulCapture($fallbackOrder, $purchase, $attempt, isReconciliation: true);
            }

            DB::transaction(function () use ($purchase, $attempt) {
                $attempt?->update([
                    'status' => GuestReportCaptureAttempt::STATUS_ERROR,
                ]);
                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
                    'capture_failure_reason' => 'Capture response missing valid COMPLETED capture resource.',
                ]);
            });

            return new GuestCaptureResult(
                status: 'failed',
                errorMessage: 'Payment verification failed.',
                httpStatus: 500,
            );
        }

        return $this->settleSuccessfulCapture($capturedOrder, $purchase, $attempt, isReconciliation: false);
    }

    /**
     * Delegates settlement to existing payment service and updates tracking state.
     */
    protected function settleSuccessfulCapture(
        array $capturedOrder,
        GuestReportPurchase $purchase,
        ?GuestReportCaptureAttempt $attempt,
        bool $isReconciliation = false,
    ): GuestCaptureResult {
        // Enforce existing settlement invariants (amounts, report eligibility, ProcessVehicleCheck dispatch, Telegram sale notification)
        $this->paymentService->handlePayPalGuestOrder($capturedOrder);

        DB::transaction(function () use ($purchase, $attempt, $isReconciliation) {
            $attempt?->update([
                'status' => GuestReportCaptureAttempt::STATUS_COMPLETED,
            ]);

            $updates = [
                'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
                'capture_failure_reason' => null,
            ];

            if ($isReconciliation) {
                $updates['last_reconciled_at'] = now();
            }

            $purchase->update($updates);
        });

        $purchase->refresh();

        return new GuestCaptureResult(
            status: 'completed',
            redirect: $this->buildSuccessUrl($purchase),
            reconciled: $isReconciliation,
            httpStatus: 200,
        );
    }

    /**
     * Handles structured PayPal API exceptions.
     */
    protected function handlePayPalApiException(
        PayPalApiException $e,
        GuestReportPurchase $purchase,
        ?GuestReportCaptureAttempt $attempt,
        string $orderId,
    ): GuestCaptureResult {
        if ($e->hasIssue('ORDER_ALREADY_CAPTURED')) {
            $order = $this->payPalService->getOrder($orderId);
            if (($order['status'] ?? null) === 'COMPLETED') {
                return $this->settleSuccessfulCapture($order, $purchase, $attempt, isReconciliation: true);
            }
        }

        if ($e->hasIssue('INSTRUMENT_DECLINED')) {
            DB::transaction(function () use ($purchase, $attempt) {
                $attempt?->update([
                    'status' => GuestReportCaptureAttempt::STATUS_FUNDING_DECLINED,
                    'failure_reason' => 'Funding instrument declined by processor.',
                ]);
                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_FUNDING_DECLINED,
                    'capture_failure_reason' => 'Funding instrument declined by processor.',
                ]);
            });

            return new GuestCaptureResult(
                status: 'funding_declined',
                errorMessage: 'Payment method declined. Please choose a different payment method.',
                httpStatus: 422,
            );
        }

        if ($e->hasIssue('PAYER_ACTION_REQUIRED')) {
            $payerActionUrl = $this->extractPayerActionUrl($e, $purchase);

            DB::transaction(function () use ($purchase, $attempt) {
                $attempt?->update([
                    'status' => GuestReportCaptureAttempt::STATUS_PAYER_ACTION_REQUIRED,
                    'failure_reason' => 'Buyer authentication required.',
                ]);
                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_PAYER_ACTION_REQUIRED,
                    'capture_failure_reason' => 'Buyer authentication required.',
                ]);
            });

            return new GuestCaptureResult(
                status: 'payer_action_required',
                payerActionUrl: $payerActionUrl,
                errorMessage: 'Customer authentication required.',
                httpStatus: 200,
            );
        }

        if ($e->hasIssue('MAX_NUMBER_OF_PAYMENT_ATTEMPTS_EXCEEDED') || $e->hasIssue('ORDER_NOT_APPROVED')) {
            DB::transaction(function () use ($purchase, $attempt, $e) {
                $attempt?->update([
                    'status' => GuestReportCaptureAttempt::STATUS_FAILED,
                    'failure_reason' => $e->getMessage(),
                ]);
                $purchase->update([
                    'payment_status' => GuestReportPurchase::PAYMENT_STATUS_FAILED,
                    'capture_status' => GuestReportPurchase::CAPTURE_STATUS_FAILED,
                    'failed_at' => now(),
                    'capture_failure_reason' => 'Terminal decline: '.($e->getIssue() ?? $e->getMessage()),
                ]);
            });

            return new GuestCaptureResult(
                status: 'failed',
                errorMessage: 'Payment failed: '.($e->getIssue() ?? 'Declined by provider.'),
                httpStatus: 422,
            );
        }

        // Generic error -> queue for reconciliation
        DB::transaction(function () use ($purchase, $attempt, $e) {
            $attempt?->update([
                'status' => GuestReportCaptureAttempt::STATUS_ERROR,
                'failure_reason' => $e->getMessage(),
            ]);
            $purchase->update([
                'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
                'capture_failure_reason' => 'API error during capture: '.$e->getMessage(),
            ]);
        });

        return new GuestCaptureResult(
            status: 'failed',
            errorMessage: 'Payment capture failed and requires reconciliation.',
            httpStatus: 500,
        );
    }

    /**
     * Verifies paid record proof without issuing any capture POST.
     */
    protected function verifyPaidRecordProof(
        GuestReportPurchase $purchase,
        string $orderId,
    ): GuestCaptureResult {
        try {
            $order = $this->payPalService->getOrder($orderId);

            // Require PayPal order status exactly COMPLETED
            if (($order['status'] ?? null) !== 'COMPLETED') {
                throw new \RuntimeException('PayPal order status is not COMPLETED.');
            }

            // Since order is confirmed COMPLETED, prepareGuestReportOrderForCapture
            // validates order correlation (order ID, reference ID, custom ID, invoice ID,
            // report ID, amount, currency) and returns without provider PATCH.
            $order = $this->payPalService->prepareGuestReportOrderForCapture($purchase, $order);

            $purchaseUnit = $order['purchase_units'][0] ?? null;
            $captures = is_array($purchaseUnit['payments']['captures'] ?? null)
                ? $purchaseUnit['payments']['captures']
                : [];

            $completedCapture = collect($captures)->first(
                fn ($item) => is_array($item) && ($item['status'] ?? null) === 'COMPLETED'
            );

            if (! is_array($completedCapture)) {
                throw new \RuntimeException('PayPal order contains no COMPLETED capture.');
            }

            $captureId = $completedCapture['id'] ?? null;

            if (! is_string($captureId) || trim($captureId) === '') {
                throw new \RuntimeException('PayPal completed capture ID is missing or empty.');
            }

            // If a provider capture ID is already recorded, require exact match
            if (
                is_string($purchase->provider_payment_id)
                && $purchase->provider_payment_id !== ''
                && ! hash_equals($purchase->provider_payment_id, $captureId)
            ) {
                throw new \RuntimeException('Provider payment ID mismatch.');
            }

            DB::transaction(function () use ($purchase, $captureId) {
                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
                    'provider_payment_id' => $captureId,
                    'last_reconciled_at' => now(),
                    'capture_failure_reason' => null,
                ]);
            });

            $purchase->refresh();

            return new GuestCaptureResult(
                status: 'completed',
                redirect: $this->buildSuccessUrl($purchase),
                reconciled: true,
                httpStatus: 200,
            );
        } catch (\Throwable $e) {
            Log::warning("Paid record proof verification failed for order {$orderId}: ".$e->getMessage());

            // Intentionally omit updating `last_reconciled_at`: this discrepancy indicates a paid
            // record without verified provider capture proof. Preserving a null/stale timestamp keeps
            // the record eligible for repeated scheduler evaluation and continued alerting/escalation
            // until manually investigated and resolved.
            DB::transaction(function () use ($purchase, $e) {
                $purchase->update([
                    'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
                    'capture_failure_reason' => 'Discrepancy: paid record missing valid provider capture proof: '.$e->getMessage(),
                ]);
            });

            if ($this->telegramNotificationService) {
                try {
                    $this->telegramNotificationService->sendMessage(
                        "⚠️ *Paid-State Discrepancy* for guest purchase #{$purchase->id}: order {$orderId} missing valid capture proof: {$e->getMessage()}"
                    );
                } catch (\Throwable) {
                    // Ignore alert delivery failure
                }
            }

            return new GuestCaptureResult(
                status: 'failed',
                errorMessage: 'Paid record discrepancy: verified capture proof missing.',
                httpStatus: 422,
            );
        }
    }

    public function extractPayerActionUrl(PayPalApiException $e, GuestReportPurchase $purchase): ?string
    {
        $baseUrl = $e->getPayerActionUrl();
        if (! $baseUrl) {
            return null;
        }

        // Validate that the base URL originates from a trusted PayPal domain to prevent open redirects
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if ($scheme !== 'https' || ! is_string($host) || ! preg_match('/(?:^|\.)paypal\.com\z/i', $host)) {
            Log::warning('Rejected untrusted payer-action URL from PayPal exception', [
                'url' => $baseUrl,
            ]);

            return null;
        }

        if (Route::has('guest-report.paypal.return')) {
            $signedReturnUrl = URL::temporarySignedRoute(
                'guest-report.paypal.return',
                now()->addMinutes(60),
                [
                    'reference' => (string) $purchase->checkout_reference,
                    'order_id' => (string) $purchase->provider_order_id,
                ],
            );
            $separator = str_contains($baseUrl, '?') ? '&' : '?';

            return $baseUrl.$separator.'redirect_uri='.urlencode($signedReturnUrl);
        }

        return $baseUrl;
    }

    public function buildSuccessUrl(GuestReportPurchase $purchase): string
    {
        $guestToken = $this->checkoutService->guestAccessToken($purchase);

        return route('guest-report.checkout.success', [
            'order_id' => $purchase->provider_order_id,
            'guest_token' => $guestToken,
        ]);
    }

    public function isAttemptWithinSixHours(?GuestReportCaptureAttempt $attempt): bool
    {
        if (! $attempt || ! $attempt->request_started_at) {
            return false;
        }

        $now = now();
        $startedAt = $attempt->request_started_at;

        // Future timestamp beyond small clock drift margin is invalid
        if ($startedAt->gt($now->copy()->addMinute())) {
            return false;
        }

        $ageInSeconds = $startedAt->diffInSeconds($now);

        return $ageInSeconds < 21600;
    }
}
