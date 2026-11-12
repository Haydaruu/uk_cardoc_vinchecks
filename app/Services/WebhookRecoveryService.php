<?php

namespace App\Services;

use App\Models\CreditCheckoutAttempt;
use App\Models\CreditTransaction;
use App\Models\GuestReportFulfillmentAttempt;
use App\Models\GuestReportPurchase;
use App\Models\ProcessedWebhookEvent;
use App\Models\RefundRecord;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\AdminRefundReconciliationService;
use App\Support\GuestReportDispatchOutcome;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Throwable;

class WebhookRecoveryService
{
    public const MAX_RETRIES = 3;

    public const BACKOFF_MINUTES = [5, 15, 30];

    public const DEFAULT_STALE_MINUTES = 10;

    public function __construct(
        protected AdminRefundReconciliationService $refundReconciliationService,
        protected GuestReportCaptureService $guestReportCaptureService,
        protected GuestReportPaymentService $guestReportPaymentService,
        protected CreditCaptureService $creditCaptureService,
        protected CreditService $creditService,
        protected PayPalService $payPalService,
        protected GuestReportFulfillmentService $guestReportFulfillmentService,
        protected ?StripeClient $stripeClient = null,
    ) {
        $this->stripeClient = $this->stripeClient ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * Scan and recover stale webhook events that are stuck in 'processing' or eligible 'failed' retries.
     *
     * @return array{
     *     candidates_count: int,
     *     claimed_count: int,
     *     completed_count: int,
     *     retried_count: int,
     *     in_flight_count: int,
     *     manual_review_count: int,
     *     skipped_count: int,
     *     details: array<int, array<string, mixed>>
     * }
     */
    public function recoverStaleEvents(
        int $limit = 50,
        int $staleMinutes = self::DEFAULT_STALE_MINUTES,
        bool $dryRun = false,
    ): array {
        $staleThreshold = now()->subMinutes(max(1, $staleMinutes));

        // Candidates:
        // 1. Status 'processing' with updated_at older than stale threshold.
        // 2. Status 'failed' where next_retry_at is null or due, and retry_count < MAX_RETRIES.
        $candidates = ProcessedWebhookEvent::query()
            ->where(function ($q) use ($staleThreshold) {
                $q->where(function ($sub) use ($staleThreshold) {
                    $sub->where('status', ProcessedWebhookEvent::STATUS_PROCESSING)
                        ->where('updated_at', '<=', $staleThreshold);
                })->orWhere(function ($sub) {
                    $sub->where('status', ProcessedWebhookEvent::STATUS_FAILED)
                        ->where(function ($retryQ) {
                            $retryQ->whereNull('next_retry_at')
                                ->orWhere('next_retry_at', '<=', now());
                        })
                        ->where('retry_count', '<', self::MAX_RETRIES);
                });
            })
            ->orderBy('updated_at', 'asc')
            ->limit(max(1, $limit))
            ->get();

        $stats = [
            'candidates_count' => $candidates->count(),
            'claimed_count' => 0,
            'completed_count' => 0,
            'retried_count' => 0,
            'in_flight_count' => 0,
            'manual_review_count' => 0,
            'skipped_count' => 0,
            'details' => [],
        ];

        if ($dryRun) {
            foreach ($candidates as $candidate) {
                $stats['details'][] = [
                    'id' => $candidate->id,
                    'event_id' => $candidate->event_id,
                    'provider' => $candidate->provider,
                    'event_type' => $candidate->event_type,
                    'current_status' => $candidate->status,
                    'action' => 'would_reconcile',
                ];
            }

            return $stats;
        }

        foreach ($candidates as $candidate) {
            $newOwnershipToken = (string) Str::uuid();

            // 1. Atomic conditional claim with fencing token
            $affected = ProcessedWebhookEvent::query()
                ->where('id', $candidate->id)
                ->where(function ($q) use ($staleThreshold) {
                    $q->where(function ($sub) use ($staleThreshold) {
                        $sub->where('status', ProcessedWebhookEvent::STATUS_PROCESSING)
                            ->where('updated_at', '<=', $staleThreshold);
                    })->orWhere(function ($sub) {
                        $sub->where('status', ProcessedWebhookEvent::STATUS_FAILED)
                            ->where(function ($retryQ) {
                                $retryQ->whereNull('next_retry_at')
                                    ->orWhere('next_retry_at', '<=', now());
                            })
                            ->where('retry_count', '<', self::MAX_RETRIES);
                    });
                })
                ->update([
                    'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
                    'ownership_token' => $newOwnershipToken,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                // Claim failed: another worker/scheduler grabbed it or status moved
                $stats['skipped_count']++;

                continue;
            }

            $stats['claimed_count']++;

            // 2. Perform state-aware domain reconciliation
            try {
                $outcome = $this->reconcileEvent($candidate);
            } catch (Throwable $e) {
                Log::error('Unexpected exception during stale webhook recovery', [
                    'event_id' => $candidate->event_id,
                    'provider' => $candidate->provider,
                    'error' => $e->getMessage(),
                ]);
                $outcome = [
                    'action' => 'retry',
                    'reason' => 'Exception: '.$e->getMessage(),
                ];
            }

            // 3. Fenced state transitions
            if ($outcome['action'] === 'completed') {
                $updated = ProcessedWebhookEvent::query()
                    ->where('id', $candidate->id)
                    ->where('ownership_token', $newOwnershipToken)
                    ->where('status', ProcessedWebhookEvent::STATUS_PROCESSING)
                    ->update([
                        'status' => ProcessedWebhookEvent::STATUS_COMPLETED,
                        'processed_at' => now(),
                        'error_message' => null,
                        'updated_at' => now(),
                    ]);

                if ($updated > 0) {
                    $stats['completed_count']++;
                } else {
                    $stats['skipped_count']++;
                }
            } elseif ($outcome['action'] === 'retry') {
                $currentRetry = (int) $candidate->retry_count;
                if ($currentRetry + 1 >= self::MAX_RETRIES) {
                    // Exceeded max retries: escalate to manual review
                    $updated = ProcessedWebhookEvent::query()
                        ->where('id', $candidate->id)
                        ->where('ownership_token', $newOwnershipToken)
                        ->where('status', ProcessedWebhookEvent::STATUS_PROCESSING)
                        ->update([
                            'status' => ProcessedWebhookEvent::STATUS_MANUAL_REVIEW,
                            'retry_count' => $currentRetry + 1,
                            'error_message' => 'Exceeded max retries ('.self::MAX_RETRIES.'). Reason: '.$outcome['reason'],
                            'updated_at' => now(),
                        ]);

                    if ($updated > 0) {
                        $stats['manual_review_count']++;
                    }
                } else {
                    $backoffIndex = min($currentRetry, count(self::BACKOFF_MINUTES) - 1);
                    $nextRetryMinutes = self::BACKOFF_MINUTES[$backoffIndex];

                    $updated = ProcessedWebhookEvent::query()
                        ->where('id', $candidate->id)
                        ->where('ownership_token', $newOwnershipToken)
                        ->where('status', ProcessedWebhookEvent::STATUS_PROCESSING)
                        ->update([
                            'status' => ProcessedWebhookEvent::STATUS_FAILED,
                            'retry_count' => $currentRetry + 1,
                            'next_retry_at' => now()->addMinutes($nextRetryMinutes),
                            'error_message' => substr($outcome['reason'], 0, 1000),
                            'updated_at' => now(),
                        ]);

                    if ($updated > 0) {
                        $stats['retried_count']++;
                    }
                }
            } elseif ($outcome['action'] === 'in_flight') {
                // The vehicle-check unique lock is already held. Leave the
                // event eligible for a future recovery pass without consuming
                // a retry or claiming completion, and bump updated_at so the
                // existing processing/staleness timeout applies from now.
                $updated = ProcessedWebhookEvent::query()
                    ->where('id', $candidate->id)
                    ->where('ownership_token', $newOwnershipToken)
                    ->where('status', ProcessedWebhookEvent::STATUS_PROCESSING)
                    ->update([
                        'updated_at' => now(),
                    ]);

                if ($updated > 0) {
                    $stats['in_flight_count']++;
                } else {
                    $stats['skipped_count']++;
                }
            } else {
                // Manual review requested
                $updated = ProcessedWebhookEvent::query()
                    ->where('id', $candidate->id)
                    ->where('ownership_token', $newOwnershipToken)
                    ->where('status', ProcessedWebhookEvent::STATUS_PROCESSING)
                    ->update([
                        'status' => ProcessedWebhookEvent::STATUS_MANUAL_REVIEW,
                        'error_message' => substr($outcome['reason'], 0, 1000),
                        'updated_at' => now(),
                    ]);

                if ($updated > 0) {
                    $stats['manual_review_count']++;
                }
            }

            $stats['details'][] = [
                'id' => $candidate->id,
                'event_id' => $candidate->event_id,
                'provider' => $candidate->provider,
                'event_type' => $candidate->event_type,
                'action' => $outcome['action'],
                'reason' => $outcome['reason'],
            ];
        }

        return $stats;
    }

    /**
     * Core domain state evaluation based on whitelisted event types.
     *
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcileEvent(ProcessedWebhookEvent $event): array
    {
        return match ($event->provider) {
            'stripe' => $this->reconcileStripeEvent($event),
            'paypal' => $this->reconcilePayPalEvent($event),
            default => [
                'action' => 'manual_review',
                'reason' => "Unsupported provider '{$event->provider}'",
            ],
        };
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcileStripeEvent(ProcessedWebhookEvent $event): array
    {
        $summary = $event->payload_summary ?? [];
        $objectId = (string) ($summary['object_id'] ?? '');

        if ($objectId === '') {
            return [
                'action' => 'manual_review',
                'reason' => 'Stripe event has no object_id in payload_summary',
            ];
        }

        return match ($event->event_type) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->reconcileStripeCheckoutSession($event, $objectId),

            'checkout.session.async_payment_failed',
            'checkout.session.expired' => $this->reconcileStripeCheckoutSessionFailed($event, $objectId),

            'payment_intent.succeeded' => $this->reconcileStripePaymentIntent($event, $objectId),

            'charge.refunded',
            'refund.created',
            'refund.updated' => $this->reconcileStripeRefund($event, $objectId),

            'invoice.paid' => $this->reconcileStripeInvoicePaid($event, $objectId),

            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.payment_failed' => $this->reconcileStripeSubscriptionEvent($event, $objectId),

            default => [
                'action' => 'manual_review',
                'reason' => "Unsupported Stripe event type '{$event->event_type}'",
            ],
        };
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcileStripeCheckoutSession(ProcessedWebhookEvent $event, string $sessionId): array
    {
        // 1. Check if GuestReportPurchase exists
        $purchase = GuestReportPurchase::query()
            ->where('provider_order_id', $sessionId)
            ->orWhere('checkout_reference', $sessionId)
            ->first();

        if ($purchase) {
            if ($purchase->payment_status === GuestReportPurchase::STATUS_PAID) {
                return $this->dispatchVehicleCheckIfEligible($purchase, $event);
            }

            if ($purchase->payment_status === GuestReportPurchase::STATUS_REFUNDED ||
                $purchase->payment_status === GuestReportPurchase::PAYMENT_STATUS_FAILED
            ) {
                return [
                    'action' => 'completed',
                    'reason' => "Guest purchase in terminal state: {$purchase->payment_status}",
                ];
            }

            // Still pending locally -> query Stripe live status
            try {
                $session = $this->stripeClient->checkout->sessions->retrieve($sessionId);
                if (($session->payment_status ?? null) === 'paid') {
                    $this->guestReportPaymentService->handleCheckoutSession(
                        $session,
                        $event->event_type,
                        GuestReportFulfillmentAttempt::ORIGIN_RECOVERY,
                    );

                    return $this->mapGuestFulfillmentOutcome(
                        $this->guestReportPaymentService->lastFulfillmentOutcome(),
                        $purchase,
                        'Stripe session verified paid and processed via GuestReportPaymentService',
                    );
                }

                return [
                    'action' => 'retry',
                    'reason' => 'Stripe session payment_status is '.($session->payment_status ?? 'unknown'),
                ];
            } catch (Throwable $e) {
                return [
                    'action' => 'retry',
                    'reason' => 'Stripe API error: '.$e->getMessage(),
                ];
            }
        }

        // 2. Check if Subscription checkout session
        try {
            $session = $this->stripeClient->checkout->sessions->retrieve($sessionId);
            $subId = $session['subscription'] ?? null;
            if ($subId && Subscription::where('stripe_subscription_id', $subId)->where('status', 'active')->exists()) {
                return [
                    'action' => 'completed',
                    'reason' => 'Stripe subscription checkout confirmed active',
                ];
            }

            return [
                'action' => 'manual_review',
                'reason' => 'Stripe checkout session could not be correlated to guest purchase or active subscription',
            ];
        } catch (Throwable $e) {
            return [
                'action' => 'retry',
                'reason' => 'Stripe API error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcileStripeCheckoutSessionFailed(ProcessedWebhookEvent $event, string $sessionId): array
    {
        $purchase = GuestReportPurchase::query()
            ->where('provider_order_id', $sessionId)
            ->orWhere('checkout_reference', $sessionId)
            ->first();

        if ($purchase) {
            if ($purchase->payment_status === GuestReportPurchase::STATUS_PENDING) {
                $purchase->update([
                    'payment_status' => GuestReportPurchase::PAYMENT_STATUS_FAILED,
                    'failed_at' => now(),
                ]);
            }

            return [
                'action' => 'completed',
                'reason' => 'Guest purchase updated to failed based on Stripe failure webhook',
            ];
        }

        return [
            'action' => 'manual_review',
            'reason' => 'Failed Stripe checkout session not found in local purchases',
        ];
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcileStripePaymentIntent(ProcessedWebhookEvent $event, string $intentId): array
    {
        $creditTx = CreditTransaction::where('idempotency_key', $intentId)->first();
        $tx = Transaction::where('payment_gateway_ref', $intentId)->where('status', 'success')->first();

        if ($creditTx && $tx) {
            return [
                'action' => 'completed',
                'reason' => 'CreditTransaction and Transaction already confirmed success',
            ];
        }

        try {
            $intent = $this->stripeClient->paymentIntents->retrieve($intentId);
            if ($intent->status === 'succeeded') {
                $userId = $intent->metadata->user_id ?? null;
                $credits = (int) ($intent->metadata->credits ?? 0);
                $user = $userId ? User::find($userId) : null;

                if (! $user || $credits <= 0) {
                    return [
                        'action' => 'manual_review',
                        'reason' => 'Payment intent metadata missing user_id or valid credits',
                    ];
                }

                $this->creditService->grantCredits(
                    user: $user,
                    amount: $credits,
                    type: 'purchase',
                    referenceId: $intent->id,
                    idempotencyKey: $intent->id,
                    description: "Stripe purchase : {$intent->id}",
                );

                Transaction::updateOrCreate(
                    ['payment_gateway_ref' => $intent->id],
                    [
                        'user_id' => $user->id,
                        'invoice_id' => 'UKC-'.strtoupper(substr($intent->id, 3, 8)),
                        'currency' => strtoupper((string) ($intent['currency'] ?? 'GBP')),
                        'amount' => ((float) ($intent['amount'] ?? 0)) / 100,
                        'payment_method' => 'stripe',
                        'type' => 'payment',
                        'category' => 'credit_purchase',
                        'description' => $intent->metadata->product_name ?? 'Credit purchase',
                        'status' => 'success',
                        'paid_at' => now(),
                    ]
                );

                return [
                    'action' => 'completed',
                    'reason' => 'Live Stripe payment intent verified succeeded; credits granted',
                ];
            }

            return [
                'action' => 'retry',
                'reason' => "Stripe payment intent status is '{$intent->status}'",
            ];
        } catch (Throwable $e) {
            return [
                'action' => 'retry',
                'reason' => 'Stripe API error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcileStripeRefund(ProcessedWebhookEvent $event, string $objectId): array
    {
        $record = RefundRecord::where('gateway', 'stripe')
            ->where(function ($q) use ($objectId) {
                $q->where('refund_gateway_ref', $objectId)
                    ->orWhere('payment_reference', $objectId);
            })
            ->first();

        if (! $record) {
            return [
                'action' => 'manual_review',
                'reason' => "No RefundRecord found for Stripe object {$objectId}",
            ];
        }

        if (in_array($record->status, [RefundRecord::STATUS_SUCCESS, RefundRecord::STATUS_FAILED], true)) {
            return [
                'action' => 'completed',
                'reason' => "RefundRecord already in terminal state {$record->status}",
            ];
        }

        $reconciled = $this->refundReconciliationService->reconcileUnknownRecord($record);
        if (in_array($reconciled->status, [RefundRecord::STATUS_SUCCESS, RefundRecord::STATUS_FAILED], true)) {
            return [
                'action' => 'completed',
                'reason' => "RefundRecord reconciled to terminal state {$reconciled->status}",
            ];
        }

        return [
            'action' => 'retry',
            'reason' => 'RefundRecord remains in non-terminal state after reconciliation',
        ];
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcileStripeInvoicePaid(ProcessedWebhookEvent $event, string $invoiceId): array
    {
        $creditTx = CreditTransaction::where('idempotency_key', $invoiceId)->first();
        $tx = Transaction::where('payment_gateway_ref', $invoiceId)->where('status', 'success')->first();

        if ($creditTx && $tx) {
            return [
                'action' => 'completed',
                'reason' => 'Subscription invoice payment already recorded',
            ];
        }

        return [
            'action' => 'manual_review',
            'reason' => 'Invoice.paid requires manual review to prevent subscription plan mismatch',
        ];
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcileStripeSubscriptionEvent(ProcessedWebhookEvent $event, string $objectId): array
    {
        $subscription = Subscription::where('stripe_subscription_id', $objectId)->first();

        if ($subscription) {
            return [
                'action' => 'completed',
                'reason' => 'Subscription record exists locally',
            ];
        }

        return [
            'action' => 'manual_review',
            'reason' => 'No matching Subscription found for Stripe object',
        ];
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcilePayPalEvent(ProcessedWebhookEvent $event): array
    {
        $summary = $event->payload_summary ?? [];
        $resourceId = (string) ($summary['resource_id'] ?? '');

        if ($resourceId === '') {
            return [
                'action' => 'manual_review',
                'reason' => 'PayPal event has no resource_id in payload_summary',
            ];
        }

        return match ($event->event_type) {
            'PAYMENT.CAPTURE.COMPLETED' => $this->reconcilePayPalCaptureCompleted($event, $resourceId),

            'CHECKOUT.ORDER.APPROVED' => $this->reconcilePayPalOrderApproved($event, $resourceId),

            'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
            'PAYMENT.CAPTURE.PENDING',
            'PAYMENT.CAPTURE.DENIED' => $this->reconcilePayPalAttemptStatusEvent($event, $resourceId),

            'PAYMENT.CAPTURE.REFUNDED' => $this->reconcilePayPalRefund($event, $resourceId),

            'PAYMENT.SALE.COMPLETED' => $this->reconcilePayPalSaleCompleted($event, $resourceId),

            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.SUSPENDED',
            'BILLING.SUBSCRIPTION.EXPIRED',
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->reconcilePayPalSubscriptionEvent($event, $resourceId),

            default => [
                'action' => 'manual_review',
                'reason' => "Unsupported PayPal event type '{$event->event_type}'",
            ],
        };
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcilePayPalCaptureCompleted(ProcessedWebhookEvent $event, string $captureId): array
    {
        // 1. Check if GuestReportPurchase already recorded by this capture ID
        $purchase = GuestReportPurchase::query()
            ->where('provider_payment_id', $captureId)
            ->first();

        if ($purchase) {
            if ($purchase->payment_status === GuestReportPurchase::STATUS_PAID &&
                $purchase->capture_status === GuestReportPurchase::CAPTURE_COMPLETED
            ) {
                return $this->dispatchVehicleCheckIfEligible($purchase, $event);
            }
        }

        // 2. Check if CreditCheckoutAttempt already recorded by this capture ID
        $attempt = CreditCheckoutAttempt::query()
            ->where('provider_capture_id', $captureId)
            ->first();

        if ($attempt && $attempt->status === CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED) {
            return [
                'action' => 'completed',
                'reason' => 'PayPal credit attempt already capture_completed',
            ];
        }

        // 3. Not found by capture ID directly -> get live Capture from PayPal to extract order_id
        try {
            $capture = $this->payPalService->getCapture($captureId);
            $orderId = (string) ($capture['supplementary_data']['related_ids']['order_id'] ?? '');

            if ($orderId === '') {
                return [
                    'action' => 'manual_review',
                    'reason' => 'Cannot extract order_id from PayPal capture supplementary_data',
                ];
            }

            return $this->reconcilePayPalOrderCorrelation($orderId, $captureId, $event);
        } catch (Throwable $e) {
            return [
                'action' => 'retry',
                'reason' => 'PayPal getCapture error: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcilePayPalOrderApproved(ProcessedWebhookEvent $event, string $orderId): array
    {
        return $this->reconcilePayPalOrderCorrelation($orderId, null, $event);
    }

    /**
     * Reconcile PayPal order correlation across GuestReportPurchase and CreditCheckoutAttempt.
     *
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcilePayPalOrderCorrelation(string $orderId, ?string $knownCaptureId, ProcessedWebhookEvent $event): array
    {
        // 1. Guest Report Purchase
        $purchase = GuestReportPurchase::query()
            ->where('payment_provider', 'paypal')
            ->where('provider_order_id', $orderId)
            ->first();

        if ($purchase) {
            if ($purchase->payment_status === GuestReportPurchase::STATUS_PAID &&
                $purchase->capture_status === GuestReportPurchase::CAPTURE_COMPLETED
            ) {
                return $this->dispatchVehicleCheckIfEligible($purchase, $event);
            }

            // Reconcile via GET-only
            $res = $this->guestReportCaptureService->reconcileGetOnly($orderId);
            if ($res->isSuccess()) {
                return [
                    'action' => 'completed',
                    'reason' => 'Guest purchase successfully reconciled via GET-only',
                ];
            }
            if ($res->isTerminalFailure()) {
                return [
                    'action' => 'completed',
                    'reason' => 'Guest purchase confirmed terminal failure',
                ];
            }

            return [
                'action' => 'retry',
                'reason' => 'Guest purchase reconciliation still pending or unconfirmed',
            ];
        }

        // 2. Credit Checkout Attempt
        $attempt = CreditCheckoutAttempt::query()
            ->where('provider_order_id', $orderId)
            ->first();

        if ($attempt) {
            if ($attempt->status === CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED) {
                return [
                    'action' => 'completed',
                    'reason' => 'Credit checkout attempt already capture_completed',
                ];
            }

            $res = $this->creditCaptureService->reconcileAttempt($attempt);
            if (! empty($res['reconciled'])) {
                return [
                    'action' => 'completed',
                    'reason' => 'Credit checkout attempt reconciled successfully',
                ];
            }

            return [
                'action' => 'retry',
                'reason' => 'Credit checkout attempt reconciliation pending: '.($res['status'] ?? 'unknown'),
            ];
        }

        return [
            'action' => 'manual_review',
            'reason' => "PayPal order [{$orderId}] does not match any local purchase or credit attempt",
        ];
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcilePayPalAttemptStatusEvent(ProcessedWebhookEvent $event, string $identifier): array
    {
        $attempt = CreditCheckoutAttempt::query()
            ->where('provider_order_id', $identifier)
            ->orWhere('provider_capture_id', $identifier)
            ->first();

        if ($attempt) {
            return [
                'action' => 'completed',
                'reason' => 'PayPal attempt status event recorded',
            ];
        }

        return [
            'action' => 'manual_review',
            'reason' => "No CreditCheckoutAttempt found for PayPal identifier [{$identifier}]",
        ];
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcilePayPalRefund(ProcessedWebhookEvent $event, string $refundId): array
    {
        $record = RefundRecord::where('gateway', 'paypal')
            ->where('refund_gateway_ref', $refundId)
            ->first();

        if (! $record) {
            return [
                'action' => 'manual_review',
                'reason' => "No RefundRecord found for PayPal refund [{$refundId}]",
            ];
        }

        if (in_array($record->status, [RefundRecord::STATUS_SUCCESS, RefundRecord::STATUS_FAILED], true)) {
            return [
                'action' => 'completed',
                'reason' => "PayPal RefundRecord already in terminal state {$record->status}",
            ];
        }

        $reconciled = $this->refundReconciliationService->reconcileUnknownRecord($record);
        if (in_array($reconciled->status, [RefundRecord::STATUS_SUCCESS, RefundRecord::STATUS_FAILED], true)) {
            return [
                'action' => 'completed',
                'reason' => "PayPal RefundRecord reconciled to terminal state {$reconciled->status}",
            ];
        }

        return [
            'action' => 'retry',
            'reason' => 'PayPal RefundRecord remains in non-terminal state',
        ];
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcilePayPalSaleCompleted(ProcessedWebhookEvent $event, string $saleId): array
    {
        $creditTx = CreditTransaction::where('idempotency_key', $saleId)->first();
        $tx = Transaction::where('payment_gateway_ref', $saleId)->where('status', 'success')->first();

        if ($creditTx && $tx) {
            return [
                'action' => 'completed',
                'reason' => 'PayPal subscription sale payment already confirmed',
            ];
        }

        return [
            'action' => 'manual_review',
            'reason' => 'PayPal subscription sale requires manual review to verify plan details',
        ];
    }

    /**
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function reconcilePayPalSubscriptionEvent(ProcessedWebhookEvent $event, string $subscriptionId): array
    {
        $subscription = Subscription::where('paypal_subscription_id', $subscriptionId)->first();

        if ($subscription) {
            return [
                'action' => 'completed',
                'reason' => 'PayPal subscription status recorded locally',
            ];
        }

        return [
            'action' => 'manual_review',
            'reason' => "No Subscription found for PayPal subscription [{$subscriptionId}]",
        ];
    }

    /**
     * Dispatch a vehicle check for a paid guest purchase, or report why recovery
     * cannot safely proceed.
     *
     * This method never mutates payment, capture, provider identifier, or paid
     * timestamp state. The only permitted mutation is normalizing a locked
     * fulfillment to pending before dispatch.
     *
     * The recovery retry boundary is evaluated here, before any dispatch, so an
     * event that is about to be escalated to manual_review cannot leave an
     * orphaned vehicle-check job queued behind it.
     *
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function dispatchVehicleCheckIfEligible(GuestReportPurchase $purchase, ProcessedWebhookEvent $event): array
    {
        if ($purchase->fulfillment_status === GuestReportPurchase::FULFILLMENT_COMPLETED) {
            return [
                'action' => 'completed',
                'reason' => 'Guest purchase fulfillment already completed',
            ];
        }

        if (! in_array($purchase->fulfillment_status, [
            GuestReportPurchase::FULFILLMENT_LOCKED,
            GuestReportPurchase::FULFILLMENT_PENDING,
            GuestReportPurchase::FULFILLMENT_PROCESSING,
            GuestReportPurchase::FULFILLMENT_FAILED,
        ], true)) {
            return [
                'action' => 'manual_review',
                'reason' => 'Guest purchase has unrecognised fulfillment status ['.($purchase->fulfillment_status ?? 'null').']',
            ];
        }

        $report = $purchase->report;

        if (! $report) {
            return [
                'action' => 'manual_review',
                'reason' => 'Guest purchase is paid but has no associated report',
            ];
        }

        if (! $report->vin_check_id) {
            return [
                'action' => 'manual_review',
                'reason' => 'Guest purchase report ['.$report->id.'] has no vin_check_id',
            ];
        }

        // Retry boundary must be evaluated before any lock claim or dispatch:
        // if this pass would exhaust the event's retry budget, the event is
        // escalated to manual_review and no job may be enqueued for it.
        $retryBoundaryReached = ((int) $event->retry_count + 1 >= self::MAX_RETRIES);

        if ($retryBoundaryReached) {
            return [
                'action' => 'retry',
                'reason' => 'Guest purchase fulfillment not enqueued: recovery retry limit reached',
            ];
        }

        if ($purchase->fulfillment_status === GuestReportPurchase::FULFILLMENT_LOCKED) {
            $purchase->update(['fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING]);
        }

        $outcome = $this->guestReportFulfillmentService->reserveAndDispatch(
            $purchase,
            GuestReportFulfillmentAttempt::ORIGIN_RECOVERY,
        );

        return $this->mapGuestFulfillmentOutcome(
            $outcome,
            $purchase,
            'Guest purchase confirmed PAID and vehicle check enqueued',
        );
    }

    /**
     * Map a guest fulfillment reservation outcome to a recovery action.
     *
     * A push failure and a sync-driver skip are retryable; neither proves the
     * job was never delivered. Lock contention is reported as in_flight so the
     * event stays eligible without consuming a retry. A purchase that exhausted
     * its push slots is escalated to manual review using the existing gate.
     *
     * @return array{action: 'completed'|'retry'|'in_flight'|'manual_review', reason: string}
     */
    protected function mapGuestFulfillmentOutcome(
        ?GuestReportDispatchOutcome $outcome,
        GuestReportPurchase $purchase,
        string $completedReason,
    ): array {
        $fresh = GuestReportPurchase::query()->find($purchase->id);

        if ($fresh !== null && $fresh->fulfillment_manual_review_at !== null) {
            return [
                'action' => 'manual_review',
                'reason' => 'Guest purchase fulfillment requires manual review: push slots exhausted',
            ];
        }

        if ($outcome === null) {
            return [
                'action' => 'completed',
                'reason' => $completedReason,
            ];
        }

        if ($outcome->isPushFailed()) {
            return [
                'action' => 'retry',
                'reason' => 'Guest purchase fulfillment not enqueued: vehicle check push failed ('.($outcome->error ?? 'unknown error').')',
            ];
        }

        if ($outcome->isInFlight()) {
            return [
                'action' => 'in_flight',
                'reason' => 'Guest purchase fulfillment not enqueued: vehicle check is already in flight',
            ];
        }

        if ($outcome->isSyncSkipped()) {
            return [
                'action' => 'retry',
                'reason' => 'Guest purchase fulfillment not enqueued: sync queue driver is not safe for post-payment dispatch',
            ];
        }

        $status = $fresh?->fulfillment_status ?? $purchase->fulfillment_status;

        if ($status === GuestReportPurchase::FULFILLMENT_FAILED) {
            return [
                'action' => 'retry',
                'reason' => 'Guest purchase fulfillment had failed; vehicle check re-enqueued and awaiting completion',
            ];
        }

        if ($status === GuestReportPurchase::FULFILLMENT_PROCESSING) {
            return [
                'action' => 'completed',
                'reason' => 'Guest purchase fulfillment was processing; vehicle check re-enqueued',
            ];
        }

        return [
            'action' => 'completed',
            'reason' => $completedReason,
        ];
    }
}
