<?php

namespace App\Services;

use App\Jobs\ProcessVehicleCheck;
use App\Models\GuestReportFulfillmentAttempt;
use App\Models\GuestReportPurchase;
use App\Support\GuestReportDispatchOutcome;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Purchase-level fulfillment reservation, claim acquisition, and pre-claim
 * failure recording.
 *
 * This service owns the decision to reserve a dispatch. VehicleCheckDispatchService
 * (M1) remains the sole owner of the unique-job lock, the reservation callback
 * transaction, and the queue push. The callback below therefore never opens its
 * own transaction: it runs inside M1's transaction, so a CAS loss is rolled back
 * by M1 before the lock is released.
 *
 * The claim lease, claim CAS, retry resume, entitlement consumption, attempt
 * budgets, and the ownership-fenced completion, release, and terminal failure
 * transitions are implemented here. Every lifecycle mutation is a write-time
 * CAS on the purchase plus the active claim ledger row, so a stale worker can
 * never mutate a newer claim.
 */
class GuestReportFulfillmentService
{
    /**
     * Dispatch reservation lease in seconds.
     *
     * This is the accepted M3 decision. It bounds "enqueued but not yet claimed".
     */
    public const DISPATCH_LEASE_SECONDS = 900;

    /**
     * Claim lease in seconds. A worker holds the purchase for this long.
     */
    public const CLAIM_LEASE_SECONDS = 900;

    /**
     * Maximum successful claim acquisitions allowed per generation.
     */
    public const MAX_CLAIM_ATTEMPTS = 4;

    /**
     * Maximum successful claim acquisitions allowed over the purchase lifetime.
     */
    public const MAX_TOTAL_ATTEMPTS = 12;

    /**
     * Maximum queue pushes allowed per dispatch generation.
     *
     * The initial reservation allocates slot one. A same-D repush may allocate
     * one more slot per attempt, up to this limit. Slots are never reclaimed.
     */
    public const MAX_PUSH_SLOTS = 3;

    public const CLAIM_STATUS_CLAIMED = 'claimed';

    public const CLAIM_STATUS_RESUMED = 'resumed';

    public const CLAIM_STATUS_COMPLETED = 'completed';

    public const CLAIM_STATUS_REJECTED = 'rejected';

    public const LIFECYCLE_STATUS_COMPLETED = 'completed';

    public const LIFECYCLE_STATUS_RELEASED = 'released';

    public const LIFECYCLE_STATUS_FAILED = 'failed';

    public const LIFECYCLE_STATUS_STALE = 'stale';

    private const FAILURE_REASON_LIMIT = 500;

    /**
     * @var list<string>
     */
    private const ALLOWED_ORIGINS = [
        GuestReportFulfillmentAttempt::ORIGIN_NORMAL,
        GuestReportFulfillmentAttempt::ORIGIN_RECOVERY,
    ];

    public function __construct(
        protected VehicleCheckDispatchService $dispatcher,
    ) {}

    /**
     * Reserve a dispatch generation for a paid purchase and push the vehicle check.
     *
     * Normal payment flows always create a new reservation. The recovery flow
     * first reuses the current dispatch generation when it is still valid and
     * only creates a new reservation when that generation is missing, expired,
     * completed, or already in manual review.
     *
     * The sync queue driver is rejected centrally so every caller (Stripe, PayPal,
     * recovery) gets identical behavior. Under sync nothing is reserved, no ledger
     * row is written, and no job is pushed.
     */
    public function reserveAndDispatch(
        GuestReportPurchase $purchase,
        string $origin,
    ): GuestReportDispatchOutcome {
        if (! in_array($origin, self::ALLOWED_ORIGINS, true)) {
            throw new InvalidArgumentException(
                "Unsupported guest report fulfillment origin [{$origin}]."
            );
        }

        if ($this->queueDriver() === 'sync') {
            Log::critical('Guest report fulfillment skipped: sync queue driver is not safe for post-payment dispatch', [
                'purchase_id' => $purchase->id,
                'origin' => $origin,
                'queue_driver' => 'sync',
            ]);

            return GuestReportDispatchOutcome::syncSkipped();
        }

        if ($origin === GuestReportFulfillmentAttempt::ORIGIN_RECOVERY) {
            $state = DB::table('guest_report_purchases')
                ->where('id', $purchase->id)
                ->first();

            if ($state !== null && $this->canRepushSameDispatch($state)) {
                return $this->repushSameDispatch($purchase, $state, $origin);
            }
        }

        return $this->reserveNewDispatch($purchase, $origin);
    }

    /**
     * Create a new dispatch generation and push it through M1.
     */
    protected function reserveNewDispatch(
        GuestReportPurchase $purchase,
        string $origin,
    ): GuestReportDispatchOutcome {
        $report = $purchase->report()->first();

        if (! $report || ! $report->vin_check_id) {
            throw new RuntimeException(
                'Guest report purchase cannot be dispatched: the report or its vin_check_id is missing.'
            );
        }

        $job = new ProcessVehicleCheck(
            (int) $report->vin_check_id,
            (int) $report->id,
            (int) $purchase->id,
        );

        // Read the expected generation from committed state: a freshly created
        // model does not carry the database default. The CAS below re-checks this
        // value at write time, so a concurrent generation bump loses safely.
        $generation = (int) DB::table('guest_report_purchases')
            ->where('id', $purchase->id)
            ->value('fulfillment_generation');

        $reservedDispatchUuid = null;

        $result = $this->dispatcher->dispatch(
            $job,
            function () use ($purchase, $generation, $origin, $job, &$reservedDispatchUuid): bool {
                $dispatchUuid = (string) Str::uuid();

                if (! $this->reserveDispatch($purchase, $generation, $dispatchUuid)) {
                    return false;
                }

                // The job is pushed by M1 after this callback commits, so the
                // dispatch context is serialized into the payload and survives
                // every automatic retry and queue:retry.
                $job->withGuestFulfillmentContext($dispatchUuid, $generation, $origin);

                $reservedDispatchUuid = $dispatchUuid;

                return true;
            },
        );

        if ($result->isPushFailed()) {
            if ($reservedDispatchUuid !== null) {
                $this->recordPreClaimFailure(
                    purchase: $purchase,
                    origin: $origin,
                    dispatchUuid: $reservedDispatchUuid,
                    generation: $generation,
                    reason: $result->errorDescription(),
                );
            }

            return GuestReportDispatchOutcome::pushFailed(
                $reservedDispatchUuid,
                $result->errorDescription(),
            );
        }

        if ($result->isEnqueued() && $reservedDispatchUuid !== null) {
            return GuestReportDispatchOutcome::enqueued($reservedDispatchUuid);
        }

        return GuestReportDispatchOutcome::inFlight();
    }

    /**
     * Decide whether the current dispatch generation can be pushed again.
     *
     * A same-D repush is only safe while the generation is still valid and no
     * worker holds an unexpired claim. A missing/expired D, a completed
     * fulfillment, or an existing manual-review gate falls through to a new
     * reservation (or to a no-op when the new reservation CAS loses).
     */
    protected function canRepushSameDispatch(object $state): bool
    {
        if ((string) ($state->payment_status ?? '') !== GuestReportPurchase::STATUS_PAID) {
            return false;
        }

        if ((string) ($state->fulfillment_status ?? '') === GuestReportPurchase::FULFILLMENT_COMPLETED) {
            return false;
        }

        if (($state->fulfillment_manual_review_at ?? null) !== null) {
            return false;
        }

        if (($state->fulfillment_dispatch_uuid ?? null) === null
            || (string) $state->fulfillment_dispatch_uuid === ''
        ) {
            return false;
        }

        if (($state->fulfillment_dispatch_expires_at ?? null) === null) {
            return false;
        }

        if (Carbon::parse($state->fulfillment_dispatch_expires_at)->lte(now())) {
            return false;
        }

        if (($state->fulfillment_generation ?? null) === null) {
            return false;
        }

        // An unexpired claim means a worker already owns this generation. Do
        // not push a second payload while the claim is active; M1's lock may
        // or may not still be held, and the claim is the authority here.
        if (($state->fulfillment_claim_uuid ?? null) !== null
            && ($state->fulfillment_claim_expires_at ?? null) !== null
            && Carbon::parse($state->fulfillment_claim_expires_at)->gt(now())
        ) {
            return false;
        }

        return true;
    }

    /**
     * Push the current dispatch generation again after atomically allocating
     * one push slot. The dispatch UUID and generation are never replaced.
     */
    protected function repushSameDispatch(
        GuestReportPurchase $purchase,
        object $state,
        string $origin,
    ): GuestReportDispatchOutcome {
        $dispatchUuid = (string) ($state->fulfillment_dispatch_uuid ?? '');
        $generation = (int) ($state->fulfillment_generation ?? 0);
        $slotsAllocated = (int) ($state->fulfillment_dispatch_push_slots_allocated ?? 0);

        if ($slotsAllocated >= self::MAX_PUSH_SLOTS) {
            $this->escalatePushSlotsExhausted($purchase, $dispatchUuid, $generation);

            return GuestReportDispatchOutcome::inFlight();
        }

        $report = $purchase->report()->first();

        if (! $report || ! $report->vin_check_id) {
            throw new RuntimeException(
                'Guest report purchase cannot be dispatched: the report or its vin_check_id is missing.'
            );
        }

        $job = new ProcessVehicleCheck(
            (int) $report->vin_check_id,
            (int) $report->id,
            (int) $purchase->id,
        );

        $job->withGuestFulfillmentContext($dispatchUuid, $generation, $origin);

        $slotAllocated = false;

        $result = $this->dispatcher->repush(
            $job,
            function () use ($purchase, $dispatchUuid, $generation, &$slotAllocated): bool {
                $now = now();

                $updated = DB::table('guest_report_purchases')
                    ->where('id', $purchase->id)
                    ->where('fulfillment_generation', $generation)
                    ->where('fulfillment_dispatch_uuid', $dispatchUuid)
                    ->where('payment_status', GuestReportPurchase::STATUS_PAID)
                    ->where('fulfillment_status', '!=', GuestReportPurchase::FULFILLMENT_COMPLETED)
                    ->whereNull('fulfillment_manual_review_at')
                    ->where('fulfillment_dispatch_expires_at', '>', $now)
                    ->where('fulfillment_dispatch_push_slots_allocated', '<', self::MAX_PUSH_SLOTS)
                    ->update([
                        'fulfillment_dispatch_push_slots_allocated' => DB::raw('fulfillment_dispatch_push_slots_allocated + 1'),
                        'updated_at' => $now,
                    ]);

                if ($updated !== 1) {
                    return false;
                }

                $slotAllocated = true;

                return true;
            },
        );

        if ($result->isPushFailed()) {
            if ($slotAllocated) {
                $this->recordPreClaimFailure(
                    purchase: $purchase,
                    origin: $origin,
                    dispatchUuid: $dispatchUuid,
                    generation: $generation,
                    reason: $result->errorDescription(),
                );
            }

            return GuestReportDispatchOutcome::pushFailed(
                $dispatchUuid,
                $result->errorDescription(),
            );
        }

        if ($result->isEnqueued() && $slotAllocated) {
            return GuestReportDispatchOutcome::enqueued($dispatchUuid);
        }

        return GuestReportDispatchOutcome::inFlight();
    }

    /**
     * Escalate a dispatch that has consumed all three push slots.
     *
     * The existing manual-review gate is used so recovery stops re-pushing and
     * an operator can inspect the purchase. Slots are never decremented.
     */
    protected function escalatePushSlotsExhausted(
        GuestReportPurchase $purchase,
        string $dispatchUuid,
        int $generation,
    ): void {
        $now = now();

        DB::table('guest_report_purchases')
            ->where('id', $purchase->id)
            ->where('fulfillment_generation', $generation)
            ->where('fulfillment_dispatch_uuid', $dispatchUuid)
            ->where('payment_status', GuestReportPurchase::STATUS_PAID)
            ->where('fulfillment_status', '!=', GuestReportPurchase::FULFILLMENT_COMPLETED)
            ->whereNull('fulfillment_manual_review_at')
            ->update([
                'fulfillment_manual_review_at' => $now,
                'updated_at' => $now,
            ]);

        Log::critical('Guest report fulfillment push slots exhausted; manual review required', [
            'purchase_id' => $purchase->id,
            'dispatch_uuid' => $dispatchUuid,
            'generation' => $generation,
            'push_slots_allocated' => self::MAX_PUSH_SLOTS,
        ]);
    }

    /**
     * Atomically claim a new dispatch reservation for the current generation.
     *
     * Every eligibility condition is evaluated in the UPDATE's WHERE clause, so a
     * purchase that changed after it was loaded cannot be overwritten. The update
     * touches only the dispatch columns: payment, provider settlement, claim,
     * generation, and attempt counters are left untouched.
     *
     * Returns true only when exactly one row was reserved.
     */
    protected function reserveDispatch(
        GuestReportPurchase $purchase,
        int $generation,
        string $dispatchUuid,
    ): bool {
        $now = now();
        $expiresAt = $now->copy()->addSeconds(self::DISPATCH_LEASE_SECONDS);

        $updated = DB::table('guest_report_purchases')
            ->where('id', $purchase->id)
            ->where('fulfillment_generation', $generation)
            ->where('payment_status', GuestReportPurchase::STATUS_PAID)
            ->where('fulfillment_status', '!=', GuestReportPurchase::FULFILLMENT_COMPLETED)
            ->whereNull('fulfillment_manual_review_at')
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('fulfillment_dispatch_uuid')
                    ->orWhereNull('fulfillment_dispatch_expires_at')
                    ->orWhere('fulfillment_dispatch_expires_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('fulfillment_claim_uuid')
                    ->orWhereNull('fulfillment_claim_expires_at')
                    ->orWhere('fulfillment_claim_expires_at', '<=', $now);
            })
            ->update([
                'fulfillment_dispatch_uuid' => $dispatchUuid,
                'fulfillment_dispatch_expires_at' => $expiresAt,
                'fulfillment_dispatch_push_slots_allocated' => 1,
                'updated_at' => $now,
            ]);

        return $updated === 1;
    }

    /**
     * Atomically acquire the worker claim for a reserved dispatch.
     *
     * The claim UUID is the claim identity. queue_job_id stores the Laravel
     * queue payload UUID only so a retry of the same payload can be recognised;
     * it is never used in attempt_key and never acts as the claim identity.
     *
     * A matching retry resumes the existing claim without creating a new claim,
     * incrementing A/A_g, consuming the entitlement again, or writing another
     * ledger row. A different payload is rejected while another unexpired claim
     * is active. Every budget and eligibility condition is evaluated in the
     * UPDATE's WHERE clause so the claim is a single write-time CAS.
     *
     * @return array{status: string, claim_uuid: string|null}
     */
    public function claim(
        int $purchaseId,
        int $reportId,
        string $dispatchUuid,
        int $generation,
        string $origin,
        ?string $queueJobUuid,
    ): array {
        if (! in_array($origin, self::ALLOWED_ORIGINS, true)) {
            throw new InvalidArgumentException(
                "Unsupported guest report fulfillment origin [{$origin}]."
            );
        }

        return DB::transaction(function () use (
            $purchaseId,
            $reportId,
            $dispatchUuid,
            $generation,
            $origin,
            $queueJobUuid,
        ): array {
            $now = now();

            $purchase = DB::table('guest_report_purchases')
                ->where('id', $purchaseId)
                ->lockForUpdate()
                ->first();

            if ($purchase === null) {
                return $this->claimResult(self::CLAIM_STATUS_REJECTED);
            }

            if ((string) $purchase->fulfillment_status === GuestReportPurchase::FULFILLMENT_COMPLETED) {
                return $this->claimResult(self::CLAIM_STATUS_COMPLETED);
            }

            $resumedClaimUuid = $this->resumeClaimUuid(
                purchaseId: $purchaseId,
                reportId: $reportId,
                dispatchUuid: $dispatchUuid,
                generation: $generation,
                queueJobUuid: $queueJobUuid,
                now: $now,
            );

            if ($resumedClaimUuid !== null) {
                return $this->claimResult(self::CLAIM_STATUS_RESUMED, $resumedClaimUuid);
            }

            $claimUuid = (string) Str::uuid();
            $expiresAt = $now->copy()->addSeconds(self::CLAIM_LEASE_SECONDS);

            $entitlementUsed = false;

            $claimed = $this->claimWithinBudget(
                purchaseId: $purchaseId,
                reportId: $reportId,
                dispatchUuid: $dispatchUuid,
                generation: $generation,
                claimUuid: $claimUuid,
                expiresAt: $expiresAt,
                now: $now,
            );

            if (! $claimed) {
                $claimed = $this->claimWithEntitlement(
                    purchaseId: $purchaseId,
                    reportId: $reportId,
                    dispatchUuid: $dispatchUuid,
                    generation: $generation,
                    claimUuid: $claimUuid,
                    expiresAt: $expiresAt,
                    now: $now,
                );

                $entitlementUsed = $claimed;
            }

            if (! $claimed) {
                return $this->claimResult(self::CLAIM_STATUS_REJECTED);
            }

            $this->recordClaim(
                purchaseId: $purchaseId,
                origin: $origin,
                generation: $generation,
                dispatchUuid: $dispatchUuid,
                claimUuid: $claimUuid,
                claimedAt: $now,
                entitlementUsed: $entitlementUsed,
                queueJobUuid: $queueJobUuid,
            );

            return $this->claimResult(self::CLAIM_STATUS_CLAIMED, $claimUuid);
        });
    }

    /**
     * Complete the active claim owned by the given dispatch, generation, and claim.
     *
     * The purchase and its claim ledger row are updated in one transaction.
     * Every ownership condition is part of the UPDATE's WHERE clause, so a stale
     * worker can never complete a newer claim or clear another worker's claim.
     *
     * @return array{status: string}
     */
    public function complete(
        int $purchaseId,
        string $dispatchUuid,
        int $generation,
        string $claimUuid,
        ?\DateTimeInterface $fulfilledAt = null,
    ): array {
        return $this->applyLifecycleTransition(
            purchaseId: $purchaseId,
            dispatchUuid: $dispatchUuid,
            generation: $generation,
            claimUuid: $claimUuid,
            status: self::LIFECYCLE_STATUS_COMPLETED,
            purchaseColumns: [
                'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
                'fulfilled_at' => $fulfilledAt ?? now(),
                'fulfillment_recovery_claim_entitlement_uuid' => null,
            ],
            ledgerColumns: [
                'status' => GuestReportFulfillmentAttempt::STATUS_COMPLETED,
                'completed_at' => now(),
                'failure_reason' => null,
            ],
            requirePaid: true,
        );
    }

    /**
     * Release the active claim so the same payload may retry.
     *
     * Only the claim owner can release. The purchase keeps its dispatch D/G,
     * push slots, attempt counters, and entitlement; only the claim UUID and
     * expiry are cleared, and only while U still matches.
     *
     * @return array{status: string}
     */
    public function release(
        int $purchaseId,
        string $dispatchUuid,
        int $generation,
        string $claimUuid,
        ?string $reason,
    ): array {
        return $this->applyLifecycleTransition(
            purchaseId: $purchaseId,
            dispatchUuid: $dispatchUuid,
            generation: $generation,
            claimUuid: $claimUuid,
            status: self::LIFECYCLE_STATUS_RELEASED,
            purchaseColumns: [],
            ledgerColumns: [
                'status' => GuestReportFulfillmentAttempt::STATUS_RELEASED,
                'released_at' => now(),
                'failure_reason' => $this->safeFailureReason(
                    $reason,
                    'Fulfillment attempt released for retry.',
                ),
            ],
        );
    }

    /**
     * Mark the active claim as a terminal failure.
     *
     * Laravel re-instantiates the job for failed(), so the claim UUID may not be
     * available in memory. In that case it is resolved from the active ledger
     * row that belongs to this queue payload and to the purchase's current claim.
     *
     * @return array{status: string}
     */
    public function fail(
        int $purchaseId,
        string $dispatchUuid,
        int $generation,
        ?string $claimUuid,
        ?string $queueJobUuid,
        ?string $reason,
    ): array {
        $resolvedClaimUuid = $claimUuid
            ?? $this->resolveClaimUuidForPayload(
                purchaseId: $purchaseId,
                dispatchUuid: $dispatchUuid,
                generation: $generation,
                queueJobUuid: $queueJobUuid,
            );

        if ($resolvedClaimUuid === null) {
            return $this->lifecycleResult(self::LIFECYCLE_STATUS_STALE);
        }

        return $this->applyLifecycleTransition(
            purchaseId: $purchaseId,
            dispatchUuid: $dispatchUuid,
            generation: $generation,
            claimUuid: $resolvedClaimUuid,
            status: self::LIFECYCLE_STATUS_FAILED,
            purchaseColumns: [
                'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
                'fulfillment_recovery_claim_entitlement_uuid' => null,
            ],
            ledgerColumns: [
                'status' => GuestReportFulfillmentAttempt::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $this->safeFailureReason(
                    $reason,
                    'Fulfillment attempt failed.',
                ),
            ],
        );
    }

    /**
     * Apply a lifecycle transition to the purchase and its active claim row.
     *
     * The purchase CAS requires the exact D/G/U ownership. The ledger CAS
     * requires the same purchase/D/G/U claim row and an active status. If either
     * CAS loses, the transaction is rolled back and no state is changed.
     *
     * @param  array<string, mixed>  $purchaseColumns
     * @param  array<string, mixed>  $ledgerColumns
     * @return array{status: string}
     */
    protected function applyLifecycleTransition(
        int $purchaseId,
        string $dispatchUuid,
        int $generation,
        string $claimUuid,
        string $status,
        array $purchaseColumns,
        array $ledgerColumns,
        bool $requirePaid = false,
    ): array {
        $now = now();

        DB::beginTransaction();

        try {
            $purchaseQuery = DB::table('guest_report_purchases')
                ->where('id', $purchaseId)
                ->where('fulfillment_generation', $generation)
                ->where('fulfillment_dispatch_uuid', $dispatchUuid)
                ->where('fulfillment_claim_uuid', $claimUuid)
                ->where('fulfillment_status', '!=', GuestReportPurchase::FULFILLMENT_COMPLETED);

            if ($requirePaid) {
                $purchaseQuery->where('payment_status', GuestReportPurchase::STATUS_PAID);
            }

            $purchaseUpdated = $purchaseQuery->update(array_merge($purchaseColumns, [
                'fulfillment_claim_uuid' => null,
                'fulfillment_claim_expires_at' => null,
                'updated_at' => $now,
            ]));

            if ($purchaseUpdated !== 1) {
                DB::rollBack();

                return $this->lifecycleResult(self::LIFECYCLE_STATUS_STALE);
            }

            $ledgerUpdated = DB::table('guest_report_fulfillment_attempts')
                ->where('guest_report_purchase_id', $purchaseId)
                ->where('kind', GuestReportFulfillmentAttempt::KIND_CLAIM)
                ->where('status', GuestReportFulfillmentAttempt::STATUS_ACTIVE)
                ->where('generation', $generation)
                ->where('dispatch_uuid', $dispatchUuid)
                ->where('claim_uuid', $claimUuid)
                ->update(array_merge($ledgerColumns, [
                    'updated_at' => $now,
                ]));

            if ($ledgerUpdated !== 1) {
                DB::rollBack();

                return $this->lifecycleResult(self::LIFECYCLE_STATUS_STALE);
            }

            DB::commit();

            return $this->lifecycleResult($status);
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    /**
     * Resolve the active claim owned by this queue payload.
     *
     * This mirrors the same-payload retry match but without the lease expiry
     * check: Laravel may call failed() after the lease elapsed, and the purchase
     * must still only be mutated while its current claim is this payload's claim.
     */
    protected function resolveClaimUuidForPayload(
        int $purchaseId,
        string $dispatchUuid,
        int $generation,
        ?string $queueJobUuid,
    ): ?string {
        if ($queueJobUuid === null || $queueJobUuid === '') {
            return null;
        }

        $claimUuid = DB::table('guest_report_fulfillment_attempts as attempts')
            ->join(
                'guest_report_purchases as purchases',
                'purchases.id',
                '=',
                'attempts.guest_report_purchase_id',
            )
            ->where('attempts.guest_report_purchase_id', $purchaseId)
            ->where('attempts.kind', GuestReportFulfillmentAttempt::KIND_CLAIM)
            ->where('attempts.status', GuestReportFulfillmentAttempt::STATUS_ACTIVE)
            ->where('attempts.generation', $generation)
            ->where('attempts.dispatch_uuid', $dispatchUuid)
            ->where('attempts.queue_job_id', $queueJobUuid)
            ->whereColumn('attempts.claim_uuid', 'purchases.fulfillment_claim_uuid')
            ->where('purchases.fulfillment_generation', $generation)
            ->where('purchases.fulfillment_dispatch_uuid', $dispatchUuid)
            ->orderByDesc('attempts.claimed_at')
            ->value('attempts.claim_uuid');

        return $claimUuid !== null ? (string) $claimUuid : null;
    }

    /**
     * @return array{status: string}
     */
    protected function lifecycleResult(string $status): array
    {
        return ['status' => $status];
    }

    /**
     * Find the active claim for the same queue payload, if one exists.
     *
     * The match is deliberately strict: same payload UUID, same dispatch UUID,
     * same generation, same report, and the purchase's claim UUID must equal the
     * active ledger row's claim UUID. If any part is missing or stale, null is
     * returned and the caller falls through to the normal claim CAS.
     */
    protected function resumeClaimUuid(
        int $purchaseId,
        int $reportId,
        string $dispatchUuid,
        int $generation,
        ?string $queueJobUuid,
        \DateTimeInterface $now,
    ): ?string {
        if ($queueJobUuid === null || $queueJobUuid === '') {
            return null;
        }

        $claimUuid = DB::table('guest_report_fulfillment_attempts as attempts')
            ->join(
                'guest_report_purchases as purchases',
                'purchases.id',
                '=',
                'attempts.guest_report_purchase_id',
            )
            ->where('attempts.guest_report_purchase_id', $purchaseId)
            ->where('attempts.kind', GuestReportFulfillmentAttempt::KIND_CLAIM)
            ->where('attempts.status', GuestReportFulfillmentAttempt::STATUS_ACTIVE)
            ->where('attempts.generation', $generation)
            ->where('attempts.dispatch_uuid', $dispatchUuid)
            ->where('attempts.queue_job_id', $queueJobUuid)
            ->whereColumn('attempts.claim_uuid', 'purchases.fulfillment_claim_uuid')
            ->where('purchases.fulfillment_generation', $generation)
            ->where('purchases.fulfillment_dispatch_uuid', $dispatchUuid)
            ->where('purchases.report_id', $reportId)
            ->where('purchases.fulfillment_claim_expires_at', '>', $now)
            ->value('attempts.claim_uuid');

        return $claimUuid !== null ? (string) $claimUuid : null;
    }

    /**
     * Claim under the normal generation and lifetime budgets.
     */
    protected function claimWithinBudget(
        int $purchaseId,
        int $reportId,
        string $dispatchUuid,
        int $generation,
        string $claimUuid,
        \DateTimeInterface $expiresAt,
        \DateTimeInterface $now,
    ): bool {
        $updated = $this->claimQuery(
            purchaseId: $purchaseId,
            reportId: $reportId,
            dispatchUuid: $dispatchUuid,
            generation: $generation,
            now: $now,
        )
            ->where('fulfillment_generation_attempts', '<', self::MAX_CLAIM_ATTEMPTS)
            ->where('fulfillment_attempts', '<', self::MAX_TOTAL_ATTEMPTS)
            ->update($this->claimColumns($claimUuid, $expiresAt, $now));

        return $updated === 1;
    }

    /**
     * Consume the single recovery entitlement for this dispatch.
     *
     * The entitlement is only usable when the normal budget is exhausted and
     * the entitlement UUID equals the current dispatch UUID. It is cleared in
     * the same UPDATE, so it can never be restored or reused.
     */
    protected function claimWithEntitlement(
        int $purchaseId,
        int $reportId,
        string $dispatchUuid,
        int $generation,
        string $claimUuid,
        \DateTimeInterface $expiresAt,
        \DateTimeInterface $now,
    ): bool {
        $updated = $this->claimQuery(
            purchaseId: $purchaseId,
            reportId: $reportId,
            dispatchUuid: $dispatchUuid,
            generation: $generation,
            now: $now,
        )
            ->where('fulfillment_recovery_claim_entitlement_uuid', $dispatchUuid)
            ->update(array_merge($this->claimColumns($claimUuid, $expiresAt, $now), [
                'fulfillment_recovery_claim_entitlement_uuid' => null,
            ]));

        return $updated === 1;
    }

    /**
     * Shared eligibility and claim-availability conditions for both CAS paths.
     */
    protected function claimQuery(
        int $purchaseId,
        int $reportId,
        string $dispatchUuid,
        int $generation,
        \DateTimeInterface $now,
    ): Builder {
        return DB::table('guest_report_purchases')
            ->where('id', $purchaseId)
            ->where('report_id', $reportId)
            ->where('fulfillment_generation', $generation)
            ->where('fulfillment_dispatch_uuid', $dispatchUuid)
            ->where('fulfillment_dispatch_expires_at', '>', $now)
            ->where('payment_status', GuestReportPurchase::STATUS_PAID)
            ->whereIn('fulfillment_status', [
                GuestReportPurchase::FULFILLMENT_PENDING,
                GuestReportPurchase::FULFILLMENT_PROCESSING,
                GuestReportPurchase::FULFILLMENT_FAILED,
            ])
            ->whereNull('fulfillment_manual_review_at')
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('fulfillment_claim_uuid')
                    ->orWhereNull('fulfillment_claim_expires_at')
                    ->orWhere('fulfillment_claim_expires_at', '<=', $now);
            });
    }

    /**
     * @return array<string, mixed>
     */
    protected function claimColumns(
        string $claimUuid,
        \DateTimeInterface $expiresAt,
        \DateTimeInterface $now,
    ): array {
        return [
            'fulfillment_claim_uuid' => $claimUuid,
            'fulfillment_claim_expires_at' => $expiresAt,
            'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
            'fulfillment_started_at' => $now,
            'fulfillment_attempts' => DB::raw('fulfillment_attempts + 1'),
            'fulfillment_generation_attempts' => DB::raw('fulfillment_generation_attempts + 1'),
            'updated_at' => $now,
        ];
    }

    protected function recordClaim(
        int $purchaseId,
        string $origin,
        int $generation,
        string $dispatchUuid,
        string $claimUuid,
        \DateTimeInterface $claimedAt,
        bool $entitlementUsed,
        ?string $queueJobUuid,
    ): void {
        GuestReportFulfillmentAttempt::query()->create([
            'guest_report_purchase_id' => $purchaseId,
            'attempt_key' => $this->claimAttemptKey($purchaseId, $claimUuid),
            'kind' => GuestReportFulfillmentAttempt::KIND_CLAIM,
            'origin' => $origin,
            'status' => GuestReportFulfillmentAttempt::STATUS_ACTIVE,
            'generation' => $generation,
            'dispatch_uuid' => $dispatchUuid,
            'claim_uuid' => $claimUuid,
            'claimed_at' => $claimedAt,
            'released_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'abandoned_at' => null,
            'entitlement_used' => $entitlementUsed,
            'failure_reason' => null,
            'queue_job_id' => $queueJobUuid,
        ]);
    }

    protected function claimAttemptKey(int $purchaseId, string $claimUuid): string
    {
        return hash(
            'sha256',
            GuestReportFulfillmentAttempt::KIND_CLAIM."|{$purchaseId}|{$claimUuid}",
        );
    }

    /**
     * @return array{status: string, claim_uuid: string|null}
     */
    protected function claimResult(string $status, ?string $claimUuid = null): array
    {
        return [
            'status' => $status,
            'claim_uuid' => $claimUuid,
        ];
    }

    /**
     * Record (or refresh) the single pre-claim failure row for this purchase/dispatch.
     *
     * The attempt key is unique per purchase and dispatch UUID, so a repeated push
     * failure for the same D updates the existing row instead of inserting a
     * duplicate. A single upsert is atomic; database exceptions are propagated.
     */
    protected function recordPreClaimFailure(
        GuestReportPurchase $purchase,
        string $origin,
        string $dispatchUuid,
        int $generation,
        ?string $reason,
    ): void {
        $now = now();

        GuestReportFulfillmentAttempt::query()->upsert(
            [[
                'guest_report_purchase_id' => $purchase->id,
                'attempt_key' => $this->preClaimAttemptKey((int) $purchase->id, $dispatchUuid),
                'kind' => GuestReportFulfillmentAttempt::KIND_PRE_CLAIM_FAILED,
                'origin' => $origin,
                'status' => GuestReportFulfillmentAttempt::STATUS_FAILED,
                'generation' => $generation,
                'dispatch_uuid' => $dispatchUuid,
                'claim_uuid' => null,
                'claimed_at' => null,
                'released_at' => null,
                'completed_at' => null,
                'failed_at' => $now,
                'abandoned_at' => null,
                'entitlement_used' => false,
                'failure_reason' => $this->safeFailureReason($reason),
                'queue_job_id' => null,
            ]],
            ['guest_report_purchase_id', 'attempt_key'],
            ['origin', 'status', 'generation', 'failed_at', 'failure_reason'],
        );
    }

    protected function preClaimAttemptKey(int $purchaseId, string $dispatchUuid): string
    {
        return hash(
            'sha256',
            GuestReportFulfillmentAttempt::KIND_PRE_CLAIM_FAILED
                ."|{$purchaseId}|{$dispatchUuid}",
        );
    }

    protected function safeFailureReason(
        ?string $reason,
        string $fallback = 'Queue push failed.',
    ): string {
        $reason = trim((string) preg_replace('/\s+/', ' ', (string) $reason));

        if ($reason === '') {
            return $fallback;
        }

        return Str::limit($reason, self::FAILURE_REASON_LIMIT);
    }

    protected function queueDriver(): string
    {
        $connection = config('queue.default', 'sync');

        return (string) config("queue.connections.{$connection}.driver", 'sync');
    }
}
