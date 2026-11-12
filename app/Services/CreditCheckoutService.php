<?php

namespace App\Services;

use App\Models\CreditCheckoutAttempt;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CreditCheckoutService
{
    public function __construct(
        protected PayPalService $payPalService,
    ) {}

    /**
     * Retrieve an existing attempt by logical intent or create a new one with snapshot data.
     *
     * @throws ValidationException
     */
    public function getOrCreateAttempt(User $user, string $planSlug, ?string $intentReference = null): CreditCheckoutAttempt
    {
        $plan = config("credit_plans.{$planSlug}");

        if (! $plan || ($plan['type'] ?? null) !== 'one_time') {
            throw new RuntimeException('Invalid credit plan.');
        }

        if ($intentReference !== null && trim($intentReference) !== '') {
            $intentReference = trim($intentReference);

            $existing = CreditCheckoutAttempt::query()
                ->where('user_id', $user->id)
                ->where('intent_reference', $intentReference)
                ->first();

            if ($existing) {
                // Reject if the same intent token is sent for a different credit plan
                if ($existing->plan_slug !== $planSlug) {
                    throw ValidationException::withMessages([
                        'intent_reference' => ['Intent reference is already bound to a different credit plan.'],
                    ]);
                }

                return $existing;
            }
        } else {
            $intentReference = (string) Str::uuid();
        }

        $attemptUuid = (string) Str::uuid();
        $invoiceId = 'UKC-CR-'.str_replace('-', '', strtoupper($attemptUuid));

        return CreditCheckoutAttempt::create([
            'user_id' => $user->id,
            'intent_reference' => $intentReference,
            'attempt_uuid' => $attemptUuid,
            'invoice_id' => $invoiceId,
            'plan_slug' => $planSlug,
            'credits_to_grant' => (int) $plan['credits'],
            'amount_minor' => (int) $plan['amount_minor'],
            'currency' => strtoupper((string) ($plan['currency'] ?? 'GBP')),
            'status' => CreditCheckoutAttempt::STATUS_INITIATED,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * Create or retrieve a PayPal Order for the given attempt idempotently.
     *
     * @return array{id?: string, attempt_id: string, status?: string, retry_after?: int}
     */
    public function createPayPalOrder(User $user, string $planSlug, ?string $intentReference = null): array
    {
        $attempt = $this->getOrCreateAttempt($user, $planSlug, $intentReference);

        if ((int) $attempt->user_id !== (int) $user->id) {
            abort(403);
        }

        // Fast-path 1: Already created with an active Order ID
        if (
            $attempt->status === CreditCheckoutAttempt::STATUS_PROVIDER_CREATED
            && is_string($attempt->provider_order_id)
            && $attempt->provider_order_id !== ''
        ) {
            return [
                'id' => $attempt->provider_order_id,
                'attempt_id' => $attempt->attempt_uuid,
            ];
        }

        if ($attempt->isCompleted()) {
            throw new RuntimeException('This checkout attempt has already been paid and captured.');
        }

        if ($attempt->status === CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE) {
            throw new RuntimeException('Checkout attempt idempotency window has expired (> 6 hours). Please initiate a new checkout.');
        }

        // Quarantine check: covers both create_ambiguous and calling_provider that
        // exceed the 6-hour PayPal Orders v2 retention window.
        // Do NOT send a new POST to PayPal — the original outcome is unknown.
        if ($attempt->isQuarantined()) {
            $attempt->update(['status' => CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE]);

            throw new RuntimeException('Checkout attempt idempotency window has expired (> 6 hours). Please initiate a new checkout.');
        }

        // Concurrency protection: Distributed atomic lock (Lock TTL: 90s, Block Wait: 3s)
        $lockKey = "paypal-order:attempt:{$attempt->attempt_uuid}";
        $lock = Cache::lock($lockKey, 90);

        try {
            $lock->block(3);
        } catch (LockTimeoutException) {
            // Check if concurrent thread completed creation while we waited
            $attempt->refresh();
            if ($attempt->status === CreditCheckoutAttempt::STATUS_PROVIDER_CREATED && $attempt->provider_order_id) {
                return [
                    'id' => $attempt->provider_order_id,
                    'attempt_id' => $attempt->attempt_uuid,
                ];
            }

            // Concurrent execution still calling PayPal: return HTTP 202 processing status
            return [
                'status' => 'processing',
                'attempt_id' => $attempt->attempt_uuid,
                'retry_after' => 2,
            ];
        }

        try {
            $attempt->refresh();

            if (
                $attempt->status === CreditCheckoutAttempt::STATUS_PROVIDER_CREATED
                && is_string($attempt->provider_order_id)
                && $attempt->provider_order_id !== ''
            ) {
                return [
                    'id' => $attempt->provider_order_id,
                    'attempt_id' => $attempt->attempt_uuid,
                ];
            }

            if ($attempt->isCompleted()) {
                throw new RuntimeException('This checkout attempt has already been paid and captured.');
            }

            if ($attempt->status === CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE) {
                throw new RuntimeException('Checkout attempt idempotency window has expired (> 6 hours). Please initiate a new checkout.');
            }

            if ($attempt->isQuarantined()) {
                $attempt->update(['status' => CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE]);

                throw new RuntimeException('Checkout attempt idempotency window has expired (> 6 hours). Please initiate a new checkout.');
            }

            // Phase 1: Conditional database state transition before external call.
            // Also accepts calling_provider to allow re-entry after a process crash.
            // provider_request_started_at is stamped once on first call and preserved across retries.
            $providerStartedAt = $attempt->provider_request_started_at ?? now();

            $updated = CreditCheckoutAttempt::query()
                ->where('id', $attempt->id)
                ->whereIn('status', [
                    CreditCheckoutAttempt::STATUS_INITIATED,
                    CreditCheckoutAttempt::STATUS_CREATE_AMBIGUOUS,
                    CreditCheckoutAttempt::STATUS_CALLING_PROVIDER,
                ])
                ->update([
                    'status' => CreditCheckoutAttempt::STATUS_CALLING_PROVIDER,
                    'provider_request_started_at' => $providerStartedAt,
                ]);

            if ($updated === 0) {
                $attempt->refresh();
                if ($attempt->provider_order_id) {
                    return [
                        'id' => $attempt->provider_order_id,
                        'attempt_id' => $attempt->attempt_uuid,
                    ];
                }
            }

            // Phase 2: HTTP call to PayPal outside of database transaction
            try {
                $order = $this->payPalService->createOrder($user, $planSlug, $attempt);
                $orderId = $order['id'] ?? null;

                if (! is_string($orderId) || $orderId === '') {
                    throw new RuntimeException('PayPal did not return an order ID.');
                }
            } catch (Throwable $e) {
                // Ambiguous outcome: network dropped, connection timed out, or HTTP 5xx
                $attempt->update([
                    'status' => CreditCheckoutAttempt::STATUS_CREATE_AMBIGUOUS,
                    'failure_reason' => substr($e->getMessage(), 0, 500),
                ]);

                throw $e;
            }

            // Phase 3: Short atomic database write to persist Order ID
            CreditCheckoutAttempt::query()
                ->where('id', $attempt->id)
                ->update([
                    'provider_order_id' => $orderId,
                    'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
                    'failure_reason' => null,
                ]);

            return [
                'id' => $orderId,
                'attempt_id' => $attempt->attempt_uuid,
            ];
        } finally {
            $lock->release();
        }
    }
}
