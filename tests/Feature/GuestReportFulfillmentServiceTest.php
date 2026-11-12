<?php

use App\Jobs\ProcessVehicleCheck;
use App\Models\GuestReportFulfillmentAttempt;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\GuestReportFulfillmentService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function m3aPurchase(array $attributes = []): GuestReportPurchase
{
    $registration = 'M3A'.Str::upper(Str::random(6));

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => $registration,
        'check_type' => 'premium',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    return GuestReportPurchase::create(array_merge([
        'report_id' => $report->id,
        'email' => 'guest-'.Str::random(6).'@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'provider_order_id' => 'ORDER-'.Str::random(10),
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ], $attributes));
}

function m3aJob(GuestReportPurchase $purchase): ProcessVehicleCheck
{
    $report = $purchase->report()->firstOrFail();

    return new ProcessVehicleCheck(
        (int) $report->vin_check_id,
        (int) $report->id,
        (int) $purchase->id,
    );
}

function m3aService(): GuestReportFulfillmentService
{
    return app(GuestReportFulfillmentService::class);
}

function m3aUseAsyncQueue(): void
{
    config(['queue.default' => 'database']);
}

/**
 * Write internal reliability fields the model deliberately keeps out of $fillable.
 *
 * @param  array<string, mixed>  $attributes
 */
function m3aSetInternal(GuestReportPurchase $purchase, array $attributes): void
{
    DB::table('guest_report_purchases')
        ->where('id', $purchase->id)
        ->update($attributes);

    $purchase->refresh();
}

/**
 * @return Collection<int, GuestReportFulfillmentAttempt>
 */
function m3aLedgerRows(GuestReportPurchase $purchase)
{
    return GuestReportFulfillmentAttempt::query()
        ->where('guest_report_purchase_id', $purchase->id)
        ->get();
}

/**
 * Reserve a real Slice 2A dispatch and return the purchase, D, and G.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{0: GuestReportPurchase, 1: string, 2: int}
 */
function m3bReservedPurchase(array $attributes = []): array
{
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase($attributes);
    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isEnqueued())->toBeTrue();

    $purchase->refresh();

    return [$purchase, (string) $outcome->dispatchUuid, (int) $purchase->fulfillment_generation];
}

/**
 * @return array{status: string, claim_uuid: string|null}
 */
function m3bClaim(
    GuestReportPurchase $purchase,
    string $dispatchUuid,
    int $generation,
    string $origin = 'normal',
    ?string $queueJobUuid = 'm3b-payload-uuid',
): array {
    return m3aService()->claim(
        purchaseId: (int) $purchase->id,
        reportId: (int) $purchase->report_id,
        dispatchUuid: $dispatchUuid,
        generation: $generation,
        origin: $origin,
        queueJobUuid: $queueJobUuid,
    );
}

/**
 * Reserve and claim a purchase, returning the purchase, D, G, and U.
 *
 * @return array{0: GuestReportPurchase, 1: string, 2: int, 3: string}
 */
function m3cClaimedPurchase(): array
{
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $claim = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'm3c-payload');

    expect($claim['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED);

    $purchase->refresh();

    return [$purchase, $dispatchUuid, $generation, (string) $claim['claim_uuid']];
}

function m3cClaimRow(
    GuestReportPurchase $purchase,
    string $status = GuestReportFulfillmentAttempt::STATUS_ACTIVE,
): GuestReportFulfillmentAttempt {
    return m3aLedgerRows($purchase)
        ->where('kind', GuestReportFulfillmentAttempt::KIND_CLAIM)
        ->where('status', $status)
        ->firstOrFail();
}

test('1. paid eligible purchase receives a dispatch uuid, a 900 second lease, and one slot', function () {
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase();

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isEnqueued())->toBeTrue()
        ->and($outcome->dispatchUuid)->not->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($outcome->dispatchUuid)
        ->and($purchase->fulfillment_dispatch_expires_at)->not->toBeNull()
        ->and($purchase->fulfillment_dispatch_expires_at->between(
            now()->addSeconds(880),
            now()->addSeconds(920),
        ))->toBeTrue()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(1)
        ->and($purchase->fulfillment_generation)->toBe(1)
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and($purchase->fulfillment_generation_attempts)->toBe(0)
        ->and($purchase->fulfillment_recovery_attempts)->toBe(0)
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull();

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('2. unpaid purchase receives no reservation', function () {
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
    ]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isInFlight())->toBeTrue()
        ->and($outcome->dispatchUuid)->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBeNull()
        ->and($purchase->fulfillment_dispatch_expires_at)->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(0);

    Queue::assertNothingPushed();
});

test('3. completed purchase receives no reservation', function () {
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
    ]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isInFlight())->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(0);

    Queue::assertNothingPushed();
});

test('4. manual review purchase receives no reservation', function () {
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase();
    m3aSetInternal($purchase, ['fulfillment_manual_review_at' => now()]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'recovery');

    expect($outcome->isInFlight())->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(0);

    Queue::assertNothingPushed();
});

test('5. an existing unexpired dispatch reservation is not overwritten', function () {
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase();
    $existingUuid = (string) Str::uuid();
    $existingExpiry = now()->addMinutes(10)->startOfSecond();

    m3aSetInternal($purchase, [
        'fulfillment_dispatch_uuid' => $existingUuid,
        'fulfillment_dispatch_expires_at' => $existingExpiry,
        'fulfillment_dispatch_push_slots_allocated' => 1,
    ]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isInFlight())->toBeTrue()
        ->and($outcome->dispatchUuid)->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($existingUuid)
        ->and($purchase->fulfillment_dispatch_expires_at->timestamp)
        ->toBe($existingExpiry->timestamp)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(1);

    Queue::assertNothingPushed();
});

test('6. an active unexpired claim is not overwritten', function () {
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
    ]);
    $claimUuid = (string) Str::uuid();
    $claimExpiry = now()->addMinutes(10)->startOfSecond();

    m3aSetInternal($purchase, [
        'fulfillment_claim_uuid' => $claimUuid,
        'fulfillment_claim_expires_at' => $claimExpiry,
    ]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isInFlight())->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(0)
        ->and($purchase->fulfillment_claim_uuid)->toBe($claimUuid)
        ->and($purchase->fulfillment_claim_expires_at->timestamp)->toBe($claimExpiry->timestamp);

    Queue::assertNothingPushed();
});

test('7. lock contention invokes no reservation callback and causes no database writes', function () {
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase();
    $holder = Cache::store()->lock(UniqueLock::getKey(m3aJob($purchase)), 60);

    expect($holder->get())->toBeTrue();

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isInFlight())->toBeTrue()
        ->and($outcome->dispatchUuid)->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBeNull()
        ->and($purchase->fulfillment_dispatch_expires_at)->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(0);

    expect(m3aLedgerRows($purchase))->toHaveCount(0);

    Queue::assertNothingPushed();

    $holder->release();
});

test('8. queue push failure records exactly one pre-claim ledger row', function () {
    m3aUseAsyncQueue();

    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $job): void {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('queue store unavailable');
        }
    });

    $purchase = m3aPurchase();

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isPushFailed())->toBeTrue()
        ->and($outcome->dispatchUuid)->not->toBeNull();

    $rows = m3aLedgerRows($purchase);

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->attempt_key)->toBe(hash(
        'sha256',
        "pre_claim_failed|{$purchase->id}|{$outcome->dispatchUuid}",
    ))
        ->and($row->kind)->toBe(GuestReportFulfillmentAttempt::KIND_PRE_CLAIM_FAILED)
        ->and($row->origin)->toBe(GuestReportFulfillmentAttempt::ORIGIN_NORMAL)
        ->and($row->status)->toBe(GuestReportFulfillmentAttempt::STATUS_FAILED)
        ->and($row->generation)->toBe(1)
        ->and($row->dispatch_uuid)->toBe($outcome->dispatchUuid)
        ->and($row->claim_uuid)->toBeNull()
        ->and($row->entitlement_used)->toBeFalse()
        ->and($row->failed_at)->not->toBeNull()
        ->and($row->failure_reason)->toContain('queue store unavailable');

    Queue::assertNothingPushed();
});

test('9. a repeated failure for the same purchase and dispatch updates the existing ledger row', function () {
    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase();
    $dispatchUuid = (string) Str::uuid();
    $service = m3aService();

    $record = new ReflectionMethod($service, 'recordPreClaimFailure');

    $record->invoke($service, $purchase, 'normal', $dispatchUuid, 1, 'first failure');
    $record->invoke($service, $purchase, 'recovery', $dispatchUuid, 1, 'second failure');

    $rows = m3aLedgerRows($purchase);

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->attempt_key)->toBe(hash(
        'sha256',
        "pre_claim_failed|{$purchase->id}|{$dispatchUuid}",
    ))
        ->and($row->origin)->toBe(GuestReportFulfillmentAttempt::ORIGIN_RECOVERY)
        ->and($row->status)->toBe(GuestReportFulfillmentAttempt::STATUS_FAILED)
        ->and($row->failure_reason)->toBe('second failure')
        ->and($row->failed_at)->not->toBeNull();
});

test('10. pre-claim failures do not increment the attempt or recovery budgets', function () {
    m3aUseAsyncQueue();

    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $job): void {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('queue store unavailable');
        }
    });

    $purchase = m3aPurchase();
    m3aSetInternal($purchase, [
        'fulfillment_attempts' => 2,
        'fulfillment_generation_attempts' => 3,
        'fulfillment_recovery_attempts' => 1,
    ]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'recovery');

    expect($outcome->isPushFailed())->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_attempts)->toBe(2)
        ->and($purchase->fulfillment_generation_attempts)->toBe(3)
        ->and($purchase->fulfillment_recovery_attempts)->toBe(1);
});

test('11. a push failure does not clear the dispatch uuid or reclaim its slot', function () {
    m3aUseAsyncQueue();

    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $job): void {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('queue store unavailable');
        }
    });

    $purchase = m3aPurchase();

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isPushFailed())->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($outcome->dispatchUuid)
        ->and($purchase->fulfillment_dispatch_expires_at)->not->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(1);
});

test('12. the sync driver skips without reserving, writing a ledger row, or dispatching', function () {
    config(['queue.default' => 'sync']);
    Queue::fake();
    Log::spy();

    $purchase = m3aPurchase();

    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isSyncSkipped())->toBeTrue()
        ->and($outcome->dispatchUuid)->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBeNull()
        ->and($purchase->fulfillment_dispatch_expires_at)->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(0);

    expect(m3aLedgerRows($purchase))->toHaveCount(0);

    Queue::assertNothingPushed();

    Log::shouldHaveReceived('critical')->once();
});

test('13. the service never mass assigns protected fulfillment fields', function () {
    $fillable = (new GuestReportPurchase)->getFillable();

    foreach ([
        'fulfillment_generation',
        'fulfillment_dispatch_uuid',
        'fulfillment_dispatch_expires_at',
        'fulfillment_claim_uuid',
        'fulfillment_claim_expires_at',
        'fulfillment_generation_attempts',
        'fulfillment_recovery_attempts',
        'fulfillment_recovery_claim_entitlement_uuid',
        'fulfillment_recovery_exhausted_at',
        'fulfillment_manual_review_at',
        'fulfillment_failure_reason',
        'fulfillment_dispatch_push_slots_allocated',
    ] as $field) {
        expect($fillable)->not->toContain($field);
    }

    m3aUseAsyncQueue();
    Queue::fake();

    $purchase = m3aPurchase();
    $outcome = m3aService()->reserveAndDispatch($purchase, 'normal');

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($outcome->dispatchUuid);
});

test('14. a valid dispatch context acquires the claim and records one active ledger row', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED)
        ->and($result['claim_uuid'])->not->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($result['claim_uuid'])
        ->and($purchase->fulfillment_claim_expires_at)->not->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at->between(
            now()->addSeconds(880),
            now()->addSeconds(920),
        ))->toBeTrue()
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(1)
        ->and($purchase->fulfillment_recovery_attempts)->toBe(0);

    $rows = m3aLedgerRows($purchase);

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect($row->attempt_key)->toBe(hash(
        'sha256',
        "claim|{$purchase->id}|{$result['claim_uuid']}",
    ))
        ->and($row->kind)->toBe(GuestReportFulfillmentAttempt::KIND_CLAIM)
        ->and($row->origin)->toBe(GuestReportFulfillmentAttempt::ORIGIN_NORMAL)
        ->and($row->status)->toBe(GuestReportFulfillmentAttempt::STATUS_ACTIVE)
        ->and($row->generation)->toBe($generation)
        ->and($row->dispatch_uuid)->toBe($dispatchUuid)
        ->and($row->claim_uuid)->toBe($result['claim_uuid'])
        ->and($row->claimed_at)->not->toBeNull()
        ->and($row->entitlement_used)->toBeFalse()
        ->and($row->queue_job_id)->toBe('payload-1');
});

test('15. a retry with the same queue payload resumes without new counters or a ledger row', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $first = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');
    $second = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($first['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED)
        ->and($second['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_RESUMED)
        ->and($second['claim_uuid'])->toBe($first['claim_uuid']);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($first['claim_uuid'])
        ->and($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(1)
        ->and(m3aLedgerRows($purchase))->toHaveCount(1);
});

test('16. a different queue payload is rejected while the claim is active', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $first = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');
    $second = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-2');

    expect($first['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED)
        ->and($second['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED)
        ->and($second['claim_uuid'])->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($first['claim_uuid'])
        ->and($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(1)
        ->and(m3aLedgerRows($purchase))->toHaveCount(1);
});

test('17. a claim without a queue payload uuid cannot resume an active claim', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $first = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: null);
    $second = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: null);

    expect($first['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED)
        ->and($second['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(1)
        ->and(m3aLedgerRows($purchase))->toHaveCount(1);
});

test('18. an expired claim allows a new claim for the same dispatch', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $first = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    m3aSetInternal($purchase, [
        'fulfillment_claim_expires_at' => now()->subMinute(),
    ]);

    $second = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($second['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED)
        ->and($second['claim_uuid'])->not->toBe($first['claim_uuid']);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($second['claim_uuid'])
        ->and($purchase->fulfillment_attempts)->toBe(2)
        ->and($purchase->fulfillment_generation_attempts)->toBe(2)
        ->and(m3aLedgerRows($purchase))->toHaveCount(2);
});

test('19. a mismatched dispatch uuid is rejected without any writes', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $result = m3bClaim($purchase, (string) Str::uuid(), $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and($purchase->fulfillment_generation_attempts)->toBe(0)
        ->and($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('20. a mismatched generation is rejected without any writes', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $result = m3bClaim($purchase, $dispatchUuid, $generation + 1, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and($purchase->fulfillment_generation_attempts)->toBe(0)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('21. an expired dispatch lease is rejected without any writes', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_dispatch_expires_at' => now()->subMinute(),
    ]);

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('22. an unpaid purchase is rejected without any writes', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
    ]);

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and($purchase->fulfillment_generation_attempts)->toBe(0)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('23. a completed purchase returns completed without any writes', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
    ]);

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_COMPLETED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('24. a manual review purchase is rejected without any writes', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_manual_review_at' => now(),
    ]);

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('25. the generation budget blocks a claim without an entitlement', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_generation_attempts' => GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS,
        'fulfillment_attempts' => GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS,
    ]);

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS)
        ->and($purchase->fulfillment_generation_attempts)->toBe(GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('26. the lifetime budget blocks a claim without an entitlement', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_generation_attempts' => 1,
        'fulfillment_attempts' => GuestReportFulfillmentService::MAX_TOTAL_ATTEMPTS,
    ]);

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(GuestReportFulfillmentService::MAX_TOTAL_ATTEMPTS)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('27. the entitlement allows exactly one extra claim and is consumed', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_generation_attempts' => GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS,
        'fulfillment_attempts' => GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS,
        'fulfillment_recovery_claim_entitlement_uuid' => $dispatchUuid,
    ]);

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($result['claim_uuid'])
        ->and($purchase->fulfillment_attempts)->toBe(GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS + 1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS + 1)
        ->and($purchase->fulfillment_recovery_claim_entitlement_uuid)->toBeNull();

    $row = m3aLedgerRows($purchase)->first();

    expect($row->entitlement_used)->toBeTrue();
});

test('28. the entitlement is never restored after it is consumed', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_generation_attempts' => GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS,
        'fulfillment_attempts' => GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS,
        'fulfillment_recovery_claim_entitlement_uuid' => $dispatchUuid,
    ]);

    m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    m3aSetInternal($purchase, [
        'fulfillment_claim_expires_at' => now()->subMinute(),
    ]);

    $second = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($second['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_recovery_claim_entitlement_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS + 1)
        ->and(m3aLedgerRows($purchase))->toHaveCount(1);
});

test('29. an entitlement for a different dispatch does not authorise the claim', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_generation_attempts' => GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS,
        'fulfillment_attempts' => GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS,
        'fulfillment_recovery_claim_entitlement_uuid' => (string) Str::uuid(),
    ]);

    $result = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(GuestReportFulfillmentService::MAX_CLAIM_ATTEMPTS)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('30. a ledger write failure rolls back the whole claim', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    $fixedClaimUuid = (string) Str::uuid();

    GuestReportFulfillmentAttempt::query()->create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_key' => hash('sha256', "claim|{$purchase->id}|{$fixedClaimUuid}"),
        'kind' => GuestReportFulfillmentAttempt::KIND_CLAIM,
        'origin' => GuestReportFulfillmentAttempt::ORIGIN_NORMAL,
        'status' => GuestReportFulfillmentAttempt::STATUS_FAILED,
        'generation' => $generation,
        'dispatch_uuid' => $dispatchUuid,
        'claim_uuid' => (string) Str::uuid(),
        'entitlement_used' => false,
        'queue_job_id' => 'previous-payload',
    ]);

    Str::createUuidsUsing(fn () => $fixedClaimUuid);

    try {
        expect(fn () => m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'payload-1'))
            ->toThrow(QueryException::class);
    } finally {
        Str::createUuidsNormally();
    }

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING)
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and($purchase->fulfillment_generation_attempts)->toBe(0)
        ->and(m3aLedgerRows($purchase))->toHaveCount(1);
});

test('31. a rejected claim leaves payment, provider, dispatch, lease, slots, and budgets untouched', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase([
        'provider_payment_id' => 'PAY-ORIGINAL',
    ]);

    $before = $purchase->fresh();

    $result = m3bClaim($purchase, (string) Str::uuid(), $generation, queueJobUuid: 'payload-1');

    expect($result['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_REJECTED);

    $after = $purchase->fresh();

    expect($after->payment_status)->toBe($before->payment_status)
        ->and($after->payment_provider)->toBe($before->payment_provider)
        ->and($after->provider_order_id)->toBe($before->provider_order_id)
        ->and($after->provider_payment_id)->toBe($before->provider_payment_id)
        ->and($after->fulfillment_dispatch_uuid)->toBe($before->fulfillment_dispatch_uuid)
        ->and($after->fulfillment_dispatch_expires_at->timestamp)->toBe($before->fulfillment_dispatch_expires_at->timestamp)
        ->and($after->fulfillment_dispatch_push_slots_allocated)->toBe($before->fulfillment_dispatch_push_slots_allocated)
        ->and($after->fulfillment_claim_uuid)->toBeNull()
        ->and($after->fulfillment_attempts)->toBe(0)
        ->and($after->fulfillment_generation_attempts)->toBe(0)
        ->and($after->fulfillment_recovery_attempts)->toBe(0)
        ->and(m3aLedgerRows($purchase))->toHaveCount(0);
});

test('32. the active claim owner completes the purchase and its ledger row', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    $result = m3aService()->complete($purchase->id, $dispatchUuid, $generation, $claimUuid);

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_COMPLETED);

    $purchase->refresh();
    $row = m3cClaimRow($purchase, GuestReportFulfillmentAttempt::STATUS_COMPLETED);

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_COMPLETED)
        ->and($purchase->fulfilled_at)->not->toBeNull()
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->released_at)->toBeNull()
        ->and($row->failed_at)->toBeNull();
});

test('33. completion clears the claim uuid and expiry but keeps dispatch D/G', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    expect($purchase->fulfillment_claim_uuid)->toBe($claimUuid)
        ->and($purchase->fulfillment_claim_expires_at)->not->toBeNull();

    m3aService()->complete($purchase->id, $dispatchUuid, $generation, $claimUuid);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull()
        ->and($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($purchase->fulfillment_generation)->toBe($generation);
});

test('34. completion invalidates the entitlement and preserves A, A_g, and R', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_recovery_claim_entitlement_uuid' => $dispatchUuid,
    ]);

    m3aService()->complete($purchase->id, $dispatchUuid, $generation, $claimUuid);

    $purchase->refresh();

    expect($purchase->fulfillment_recovery_claim_entitlement_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(1)
        ->and($purchase->fulfillment_recovery_attempts)->toBe(0)
        ->and($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID);
});

test('35. completion with a stale dispatch uuid changes nothing', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();
    $before = $purchase->fresh();

    $result = m3aService()->complete(
        $purchase->id,
        (string) Str::uuid(),
        $generation,
        $claimUuid,
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $after = $purchase->fresh();
    $row = m3cClaimRow($purchase);

    expect($after->fulfillment_status)->toBe($before->fulfillment_status)
        ->and($after->fulfillment_claim_uuid)->toBe($claimUuid)
        ->and($after->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($after->fulfilled_at)->toBeNull()
        ->and($row->status)->toBe(GuestReportFulfillmentAttempt::STATUS_ACTIVE)
        ->and($row->completed_at)->toBeNull();
});

test('36. completion with a stale generation changes nothing', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();
    $before = $purchase->fresh();

    $result = m3aService()->complete(
        $purchase->id,
        $dispatchUuid,
        $generation + 1,
        $claimUuid,
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $after = $purchase->fresh();

    expect($after->fulfillment_status)->toBe($before->fulfillment_status)
        ->and($after->fulfillment_generation)->toBe($generation)
        ->and($after->fulfillment_claim_uuid)->toBe($claimUuid)
        ->and($after->fulfilled_at)->toBeNull()
        ->and(m3cClaimRow($purchase)->status)->toBe(GuestReportFulfillmentAttempt::STATUS_ACTIVE);
});

test('37. completion with a stale claim uuid changes nothing', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();
    $before = $purchase->fresh();

    $result = m3aService()->complete(
        $purchase->id,
        $dispatchUuid,
        $generation,
        (string) Str::uuid(),
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $after = $purchase->fresh();

    expect($after->fulfillment_status)->toBe($before->fulfillment_status)
        ->and($after->fulfillment_claim_uuid)->toBe($claimUuid)
        ->and($after->fulfilled_at)->toBeNull()
        ->and(m3cClaimRow($purchase)->status)->toBe(GuestReportFulfillmentAttempt::STATUS_ACTIVE);
});

test('38. completion is rejected when the claim ledger row is not active', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    DB::table('guest_report_fulfillment_attempts')
        ->where('guest_report_purchase_id', $purchase->id)
        ->where('claim_uuid', $claimUuid)
        ->update(['status' => GuestReportFulfillmentAttempt::STATUS_RELEASED]);

    $result = m3aService()->complete($purchase->id, $dispatchUuid, $generation, $claimUuid);

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $purchase->refresh();
    $row = m3cClaimRow($purchase, GuestReportFulfillmentAttempt::STATUS_RELEASED);

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_claim_uuid)->toBe($claimUuid)
        ->and($row->completed_at)->toBeNull();
});

test('39. the active claim owner releases the claim and the ledger records released', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    $result = m3aService()->release(
        $purchase->id,
        $dispatchUuid,
        $generation,
        $claimUuid,
        'Temporary provider error',
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_RELEASED);

    $purchase->refresh();
    $row = m3cClaimRow($purchase, GuestReportFulfillmentAttempt::STATUS_RELEASED);

    expect($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull()
        ->and($row->released_at)->not->toBeNull()
        ->and($row->failure_reason)->toContain('Temporary provider error')
        ->and($row->completed_at)->toBeNull()
        ->and($row->failed_at)->toBeNull();
});

test('40. release preserves D/G, push slots, counters, and the entitlement', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_recovery_claim_entitlement_uuid' => (string) Str::uuid(),
    ]);

    $before = $purchase->fresh();

    m3aService()->release($purchase->id, $dispatchUuid, $generation, $claimUuid, 'retry');

    $after = $purchase->fresh();

    expect($after->fulfillment_dispatch_uuid)->toBe($before->fulfillment_dispatch_uuid)
        ->and($after->fulfillment_generation)->toBe($before->fulfillment_generation)
        ->and($after->fulfillment_dispatch_push_slots_allocated)->toBe($before->fulfillment_dispatch_push_slots_allocated)
        ->and($after->fulfillment_attempts)->toBe($before->fulfillment_attempts)
        ->and($after->fulfillment_generation_attempts)->toBe($before->fulfillment_generation_attempts)
        ->and($after->fulfillment_recovery_attempts)->toBe($before->fulfillment_recovery_attempts)
        ->and($after->fulfillment_recovery_claim_entitlement_uuid)->toBe($before->fulfillment_recovery_claim_entitlement_uuid)
        ->and($after->payment_status)->toBe($before->payment_status);
});

test('41. a stale release does not clear a newer claim', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_claim_expires_at' => now()->subMinute(),
    ]);

    $newClaim = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'm3c-payload-2');

    expect($newClaim['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED);

    $result = m3aService()->release(
        $purchase->id,
        $dispatchUuid,
        $generation,
        $claimUuid,
        'stale release',
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($newClaim['claim_uuid'])
        ->and($purchase->fulfillment_attempts)->toBe(2)
        ->and(m3aLedgerRows($purchase)
            ->where('claim_uuid', $newClaim['claim_uuid'])
            ->firstOrFail()
            ->status)->toBe(GuestReportFulfillmentAttempt::STATUS_ACTIVE);
});

test('42. release is rejected when the claim ledger row is not active', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    DB::table('guest_report_fulfillment_attempts')
        ->where('guest_report_purchase_id', $purchase->id)
        ->where('claim_uuid', $claimUuid)
        ->update(['status' => GuestReportFulfillmentAttempt::STATUS_COMPLETED]);

    $result = m3aService()->release($purchase->id, $dispatchUuid, $generation, $claimUuid, 'stale');

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($claimUuid)
        ->and(m3cClaimRow($purchase, GuestReportFulfillmentAttempt::STATUS_COMPLETED)->released_at)->toBeNull();
});

test('43. a same-payload retry after release acquires a new claim and ledger row', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    m3aService()->release($purchase->id, $dispatchUuid, $generation, $claimUuid, 'retry');

    $second = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'm3c-payload');

    expect($second['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED)
        ->and($second['claim_uuid'])->not->toBe($claimUuid);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($second['claim_uuid'])
        ->and($purchase->fulfillment_attempts)->toBe(2)
        ->and($purchase->fulfillment_generation_attempts)->toBe(2)
        ->and(m3aLedgerRows($purchase))->toHaveCount(2);
});

test('44. the active claim owner marks the claim and purchase failed', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    $result = m3aService()->fail(
        purchaseId: $purchase->id,
        dispatchUuid: $dispatchUuid,
        generation: $generation,
        claimUuid: $claimUuid,
        queueJobUuid: null,
        reason: 'Provider permanently rejected the report',
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_FAILED);

    $purchase->refresh();
    $row = m3cClaimRow($purchase, GuestReportFulfillmentAttempt::STATUS_FAILED);

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_FAILED)
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull()
        ->and($row->failed_at)->not->toBeNull()
        ->and($row->failure_reason)->toContain('Provider permanently rejected');
});

test('45. terminal failure invalidates entitlement and preserves counters and D/G', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_recovery_claim_entitlement_uuid' => $dispatchUuid,
    ]);

    $before = $purchase->fresh();

    m3aService()->fail(
        $purchase->id,
        $dispatchUuid,
        $generation,
        $claimUuid,
        null,
        'terminal',
    );

    $after = $purchase->fresh();

    expect($after->fulfillment_recovery_claim_entitlement_uuid)->toBeNull()
        ->and($after->fulfillment_attempts)->toBe($before->fulfillment_attempts)
        ->and($after->fulfillment_generation_attempts)->toBe($before->fulfillment_generation_attempts)
        ->and($after->fulfillment_recovery_attempts)->toBe($before->fulfillment_recovery_attempts)
        ->and($after->fulfillment_dispatch_uuid)->toBe($before->fulfillment_dispatch_uuid)
        ->and($after->fulfillment_generation)->toBe($before->fulfillment_generation)
        ->and($after->payment_status)->toBe($before->payment_status);
});

test('46. a stale terminal failure does not overwrite a newer claim', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    m3aSetInternal($purchase, [
        'fulfillment_claim_expires_at' => now()->subMinute(),
    ]);

    $newClaim = m3bClaim($purchase, $dispatchUuid, $generation, queueJobUuid: 'm3c-payload-2');

    $result = m3aService()->fail(
        $purchase->id,
        $dispatchUuid,
        $generation,
        $claimUuid,
        null,
        'stale terminal',
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_claim_uuid)->toBe($newClaim['claim_uuid'])
        ->and(m3aLedgerRows($purchase)
            ->where('claim_uuid', $newClaim['claim_uuid'])
            ->firstOrFail()
            ->status)->toBe(GuestReportFulfillmentAttempt::STATUS_ACTIVE);
});

test('47. terminal failure is rejected when the claim ledger row is not active', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    DB::table('guest_report_fulfillment_attempts')
        ->where('guest_report_purchase_id', $purchase->id)
        ->where('claim_uuid', $claimUuid)
        ->update(['status' => GuestReportFulfillmentAttempt::STATUS_RELEASED]);

    $result = m3aService()->fail(
        $purchase->id,
        $dispatchUuid,
        $generation,
        $claimUuid,
        null,
        'stale terminal',
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_claim_uuid)->toBe($claimUuid)
        ->and(m3cClaimRow($purchase, GuestReportFulfillmentAttempt::STATUS_RELEASED)->failed_at)->toBeNull();
});

test('48. lifecycle callbacks after completion are safe no-ops', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    m3aService()->complete($purchase->id, $dispatchUuid, $generation, $claimUuid);

    $completeAgain = m3aService()->complete($purchase->id, $dispatchUuid, $generation, $claimUuid);
    $release = m3aService()->release($purchase->id, $dispatchUuid, $generation, $claimUuid, 'stale');
    $fail = m3aService()->fail($purchase->id, $dispatchUuid, $generation, $claimUuid, null, 'stale');

    expect($completeAgain['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE)
        ->and($release['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE)
        ->and($fail['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_STALE);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_COMPLETED)
        ->and(m3cClaimRow($purchase, GuestReportFulfillmentAttempt::STATUS_COMPLETED)->completed_at)->not->toBeNull();
});

test('49. terminal failure resolves the claim from the queue payload uuid', function () {
    [$purchase, $dispatchUuid, $generation, $claimUuid] = m3cClaimedPurchase();

    $result = m3aService()->fail(
        purchaseId: $purchase->id,
        dispatchUuid: $dispatchUuid,
        generation: $generation,
        claimUuid: null,
        queueJobUuid: 'm3c-payload',
        reason: 'Laravel terminal failure',
    );

    expect($result['status'])->toBe(GuestReportFulfillmentService::LIFECYCLE_STATUS_FAILED);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_FAILED)
        ->and(m3cClaimRow($purchase, GuestReportFulfillmentAttempt::STATUS_FAILED)->claim_uuid)->toBe($claimUuid);
});

function m3aReleaseDispatchLock(GuestReportPurchase $purchase): void
{
    Cache::store()
        ->lock(UniqueLock::getKey(m3aJob($purchase)), 1)
        ->forceRelease();
}

test('50. recovery repush reuses the same dispatch uuid, allocates one slot, and keeps the generation', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aReleaseDispatchLock($purchase);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'recovery');

    expect($outcome->isEnqueued())->toBeTrue()
        ->and($outcome->dispatchUuid)->toBe($dispatchUuid);

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($purchase->fulfillment_generation)->toBe($generation)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(2);

    Queue::assertPushed(ProcessVehicleCheck::class, 2);
    Queue::assertPushed(ProcessVehicleCheck::class, function (ProcessVehicleCheck $job): bool {
        return (new ReflectionProperty($job, 'fulfillmentOrigin'))->getValue($job) === 'recovery';
    });
});

test('51. recovery repush does not allocate a slot while the dispatch lock is held', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aReleaseDispatchLock($purchase);

    $holder = Cache::store()->lock(UniqueLock::getKey(m3aJob($purchase)), 60);
    expect($holder->get())->toBeTrue();

    try {
        $outcome = m3aService()->reserveAndDispatch($purchase, 'recovery');
    } finally {
        $holder->release();
    }

    expect($outcome->isInFlight())->toBeTrue()
        ->and($outcome->dispatchUuid)->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($purchase->fulfillment_generation)->toBe($generation)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(1);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('52. repeated recovery push failures keep the allocated slots and upsert one ledger row', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aReleaseDispatchLock($purchase);

    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $job): void {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('queue store unavailable');
        }
    });

    $first = m3aService()->reserveAndDispatch($purchase, 'recovery');
    $second = m3aService()->reserveAndDispatch($purchase, 'recovery');

    expect($first->isPushFailed())->toBeTrue()
        ->and($second->isPushFailed())->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($purchase->fulfillment_generation)->toBe($generation)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(3);

    $rows = m3aLedgerRows($purchase);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->kind)->toBe(GuestReportFulfillmentAttempt::KIND_PRE_CLAIM_FAILED)
        ->and($rows->first()->status)->toBe(GuestReportFulfillmentAttempt::STATUS_FAILED);
});

test('53. recovery escalates to manual review when all push slots are used and never decrements', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aReleaseDispatchLock($purchase);

    m3aSetInternal($purchase, [
        'fulfillment_dispatch_push_slots_allocated' => GuestReportFulfillmentService::MAX_PUSH_SLOTS,
    ]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'recovery');

    expect($outcome->isInFlight())->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($purchase->fulfillment_generation)->toBe($generation)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(GuestReportFulfillmentService::MAX_PUSH_SLOTS)
        ->and($purchase->fulfillment_manual_review_at)->not->toBeNull();

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('54. recovery with an expired dispatch creates a new reservation without changing the generation', function () {
    [$purchase, $oldDispatchUuid, $generation] = m3bReservedPurchase();

    m3aReleaseDispatchLock($purchase);

    m3aSetInternal($purchase, [
        'fulfillment_dispatch_expires_at' => now()->subMinute(),
    ]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'recovery');

    expect($outcome->isEnqueued())->toBeTrue()
        ->and($outcome->dispatchUuid)->not->toBe($oldDispatchUuid);

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($outcome->dispatchUuid)
        ->and($purchase->fulfillment_dispatch_uuid)->not->toBe($oldDispatchUuid)
        ->and($purchase->fulfillment_generation)->toBe($generation)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(1);
});

test('55. recovery does not repush while an unexpired claim is active', function () {
    [$purchase, $dispatchUuid, $generation] = m3bReservedPurchase();

    m3aReleaseDispatchLock($purchase);

    m3aSetInternal($purchase, [
        'fulfillment_claim_uuid' => (string) Str::uuid(),
        'fulfillment_claim_expires_at' => now()->addMinutes(5),
    ]);

    $outcome = m3aService()->reserveAndDispatch($purchase, 'recovery');

    expect($outcome->isInFlight())->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($purchase->fulfillment_generation)->toBe($generation)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(1);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});
