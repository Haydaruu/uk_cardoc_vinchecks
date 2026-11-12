<?php

use App\Models\GuestReportFulfillmentAttempt;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function createFulfillmentSchemaPurchase(array $attributes = []): GuestReportPurchase
{
    $vehicle = Vehicle::create([
        'registration_number' => 'REG-'.Str::upper(Str::random(6)),
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
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

function fulfillmentSchemaAttemptKey(string $kind, int $purchaseId, string $identity): string
{
    return hash('sha256', "{$kind}|{$purchaseId}|{$identity}");
}

function fulfillmentSchemaDefault(array $column): mixed
{
    $default = $column['default'];

    return is_string($default) ? trim($default, "'\"") : $default;
}

function createFulfillmentSchemaAttempt(
    GuestReportPurchase $purchase,
    array $attributes = [],
): GuestReportFulfillmentAttempt {
    $kind = $attributes['kind'] ?? GuestReportFulfillmentAttempt::KIND_CLAIM;

    $claimUuid = array_key_exists('claim_uuid', $attributes)
        ? $attributes['claim_uuid']
        : (string) Str::uuid();

    $identity = $claimUuid ?? $attributes['dispatch_uuid'] ?? (string) Str::uuid();

    return GuestReportFulfillmentAttempt::create(array_merge([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_key' => fulfillmentSchemaAttemptKey($kind, $purchase->id, $identity),
        'kind' => $kind,
        'origin' => GuestReportFulfillmentAttempt::ORIGIN_NORMAL,
        'status' => GuestReportFulfillmentAttempt::STATUS_ACTIVE,
        'generation' => 1,
        'claim_uuid' => $claimUuid,
    ], $attributes));
}

test('purchase reliability columns exist with the expected nullability and defaults', function () {
    $columns = collect(Schema::getColumns('guest_report_purchases'))->keyBy('name');

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
    ] as $column) {
        expect($columns->has($column))->toBeTrue();
    }

    expect($columns['fulfillment_generation']['nullable'])->toBeFalse()
        ->and((int) fulfillmentSchemaDefault($columns['fulfillment_generation']))->toBe(1)
        ->and($columns['fulfillment_dispatch_uuid']['nullable'])->toBeTrue()
        ->and($columns['fulfillment_dispatch_uuid']['default'])->toBeNull()
        ->and($columns['fulfillment_dispatch_expires_at']['nullable'])->toBeTrue()
        ->and($columns['fulfillment_claim_uuid']['nullable'])->toBeTrue()
        ->and($columns['fulfillment_claim_uuid']['default'])->toBeNull()
        ->and($columns['fulfillment_claim_expires_at']['nullable'])->toBeTrue()
        ->and($columns['fulfillment_generation_attempts']['nullable'])->toBeFalse()
        ->and((int) fulfillmentSchemaDefault($columns['fulfillment_generation_attempts']))->toBe(0)
        ->and($columns['fulfillment_recovery_attempts']['nullable'])->toBeFalse()
        ->and((int) fulfillmentSchemaDefault($columns['fulfillment_recovery_attempts']))->toBe(0)
        ->and($columns['fulfillment_recovery_claim_entitlement_uuid']['nullable'])->toBeTrue()
        ->and($columns['fulfillment_recovery_exhausted_at']['nullable'])->toBeTrue()
        ->and($columns['fulfillment_manual_review_at']['nullable'])->toBeTrue()
        ->and($columns['fulfillment_failure_reason']['nullable'])->toBeTrue()
        ->and($columns['fulfillment_dispatch_push_slots_allocated']['nullable'])->toBeFalse()
        ->and((int) fulfillmentSchemaDefault($columns['fulfillment_dispatch_push_slots_allocated']))->toBe(0);

    expect(Schema::hasIndex('guest_report_purchases', ['fulfillment_dispatch_uuid'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('guest_report_purchases', ['fulfillment_claim_uuid'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('guest_report_purchases', ['fulfillment_status', 'fulfillment_dispatch_expires_at']))->toBeTrue()
        ->and(Schema::hasIndex('guest_report_purchases', ['fulfillment_status', 'fulfillment_claim_expires_at']))->toBeTrue();

    $purchase = createFulfillmentSchemaPurchase();
    $purchase->refresh();

    expect($purchase->fulfillment_generation)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(0)
        ->and($purchase->fulfillment_recovery_attempts)->toBe(0)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(0)
        ->and($purchase->fulfillment_dispatch_uuid)->toBeNull()
        ->and($purchase->fulfillment_dispatch_expires_at)->toBeNull()
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull()
        ->and($purchase->fulfillment_recovery_claim_entitlement_uuid)->toBeNull()
        ->and($purchase->fulfillment_recovery_exhausted_at)->toBeNull()
        ->and($purchase->fulfillment_manual_review_at)->toBeNull()
        ->and($purchase->fulfillment_failure_reason)->toBeNull();
});

test('fulfillment attempt ledger has the expected columns and defaults', function () {
    expect(Schema::hasTable('guest_report_fulfillment_attempts'))->toBeTrue();

    expect(Schema::hasColumns('guest_report_fulfillment_attempts', [
        'id',
        'guest_report_purchase_id',
        'attempt_key',
        'kind',
        'origin',
        'status',
        'generation',
        'dispatch_uuid',
        'claim_uuid',
        'claimed_at',
        'released_at',
        'completed_at',
        'failed_at',
        'abandoned_at',
        'entitlement_used',
        'failure_reason',
        'queue_job_id',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    $columns = collect(Schema::getColumns('guest_report_fulfillment_attempts'))->keyBy('name');

    expect($columns['guest_report_purchase_id']['nullable'])->toBeFalse()
        ->and($columns['attempt_key']['nullable'])->toBeFalse()
        ->and($columns['kind']['nullable'])->toBeFalse()
        ->and($columns['origin']['nullable'])->toBeFalse()
        ->and($columns['status']['nullable'])->toBeFalse()
        ->and($columns['generation']['nullable'])->toBeFalse()
        ->and($columns['dispatch_uuid']['nullable'])->toBeTrue()
        ->and($columns['claim_uuid']['nullable'])->toBeTrue()
        ->and($columns['claimed_at']['nullable'])->toBeTrue()
        ->and($columns['released_at']['nullable'])->toBeTrue()
        ->and($columns['completed_at']['nullable'])->toBeTrue()
        ->and($columns['failed_at']['nullable'])->toBeTrue()
        ->and($columns['abandoned_at']['nullable'])->toBeTrue()
        ->and($columns['entitlement_used']['nullable'])->toBeFalse()
        ->and((int) fulfillmentSchemaDefault($columns['entitlement_used']))->toBe(0)
        ->and($columns['failure_reason']['nullable'])->toBeTrue()
        ->and($columns['queue_job_id']['nullable'])->toBeTrue();

    expect(Schema::hasIndex(
        'guest_report_fulfillment_attempts',
        ['guest_report_purchase_id', 'attempt_key'],
        'unique'
    ))->toBeTrue()
        ->and(Schema::hasIndex(
            'guest_report_fulfillment_attempts',
            ['claim_uuid'],
            'unique'
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'guest_report_fulfillment_attempts',
            ['guest_report_purchase_id', 'generation', 'status']
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'guest_report_fulfillment_attempts',
            ['guest_report_purchase_id', 'dispatch_uuid']
        ))->toBeTrue()
        ->and(Schema::hasIndex(
            'guest_report_fulfillment_attempts',
            ['status']
        ))->toBeTrue();

    expect(Schema::hasForeignKey(
        'guest_report_fulfillment_attempts',
        ['guest_report_purchase_id']
    ))->toBeTrue();
});

test('ledger enforces a unique attempt key per purchase', function () {
    $purchase = createFulfillmentSchemaPurchase();
    $sharedKey = str_repeat('a', 64);

    createFulfillmentSchemaAttempt($purchase, [
        'attempt_key' => $sharedKey,
    ]);

    expect(fn () => createFulfillmentSchemaAttempt($purchase, [
        'attempt_key' => $sharedKey,
    ]))->toThrow(QueryException::class);
});

test('ledger enforces a unique non-null claim uuid', function () {
    $purchase = createFulfillmentSchemaPurchase();
    $sharedClaimUuid = (string) Str::uuid();

    createFulfillmentSchemaAttempt($purchase, [
        'claim_uuid' => $sharedClaimUuid,
    ]);

    expect(fn () => createFulfillmentSchemaAttempt($purchase, [
        'attempt_key' => str_repeat('b', 64),
        'claim_uuid' => $sharedClaimUuid,
    ]))->toThrow(QueryException::class);
});

test('ledger accepts nullable dispatch and claim uuids', function () {
    $purchase = createFulfillmentSchemaPurchase();

    $first = createFulfillmentSchemaAttempt($purchase, [
        'claim_uuid' => null,
        'dispatch_uuid' => null,
    ]);

    $second = createFulfillmentSchemaAttempt($purchase, [
        'claim_uuid' => null,
        'dispatch_uuid' => null,
    ]);

    $withDispatchOnly = createFulfillmentSchemaAttempt($purchase, [
        'claim_uuid' => null,
        'dispatch_uuid' => (string) Str::uuid(),
    ]);

    expect($first->fresh()->claim_uuid)->toBeNull()
        ->and($first->fresh()->dispatch_uuid)->toBeNull()
        ->and($second->fresh()->claim_uuid)->toBeNull()
        ->and($withDispatchOnly->fresh()->claim_uuid)->toBeNull()
        ->and($withDispatchOnly->fresh()->dispatch_uuid)->not->toBeNull();
});

test('ledger foreign key prevents deleting a purchase with attempts', function () {
    $purchase = createFulfillmentSchemaPurchase();
    $attempt = createFulfillmentSchemaAttempt($purchase);

    expect(fn () => $purchase->delete())->toThrow(QueryException::class);

    expect(GuestReportPurchase::find($purchase->id))->not->toBeNull()
        ->and(GuestReportFulfillmentAttempt::find($attempt->id))->not->toBeNull();
});

test('purchase and ledger relationships work in both directions', function () {
    $purchase = createFulfillmentSchemaPurchase();

    $first = createFulfillmentSchemaAttempt($purchase);
    $second = createFulfillmentSchemaAttempt($purchase);

    $purchase->refresh();

    expect($purchase->fulfillmentAttempts)->toHaveCount(2)
        ->and($purchase->fulfillmentAttempts->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($first->purchase->is($purchase))->toBeTrue()
        ->and($second->purchase->is($purchase))->toBeTrue();
});

test('historical attempts are copied conservatively into generation attempts', function () {
    $migration = require database_path(
        'migrations/2026_11_10_000001_add_fulfillment_reliability_fields_to_guest_report_purchases_table.php'
    );

    $migration->down();

    expect(Schema::hasColumn('guest_report_purchases', 'fulfillment_generation'))->toBeFalse();

    $vehicle = Vehicle::create([
        'registration_number' => 'HIST-FULFILLMENT-001',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    $startedAt = now()->subHour()->format('Y-m-d H:i:s');
    $fulfilledAt = now()->subMinutes(10)->format('Y-m-d H:i:s');

    $historical = [
        [
            'attempts' => 0,
            'status' => GuestReportPurchase::FULFILLMENT_PENDING,
            'started_at' => null,
            'fulfilled_at' => null,
        ],
        [
            'attempts' => 2,
            'status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
            'started_at' => $startedAt,
            'fulfilled_at' => null,
        ],
        [
            'attempts' => 9,
            'status' => GuestReportPurchase::FULFILLMENT_FAILED,
            'started_at' => $startedAt,
            'fulfilled_at' => $fulfilledAt,
        ],
    ];

    $ids = [];

    foreach ($historical as $index => $record) {
        $ids[$index] = DB::table('guest_report_purchases')->insertGetId([
            'report_id' => $report->id,
            'email' => "historical-{$index}@example.com",
            'payment_provider' => 'stripe',
            'payment_status' => GuestReportPurchase::STATUS_PAID,
            'amount_minor' => 2000,
            'currency' => 'gbp',
            'checkout_reference' => (string) Str::uuid(),
            'provider_order_id' => "ORDER-HIST-{$index}",
            'fulfillment_status' => $record['status'],
            'fulfillment_attempts' => $record['attempts'],
            'fulfillment_started_at' => $record['started_at'],
            'fulfilled_at' => $record['fulfilled_at'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $migration->up();

    foreach ($historical as $index => $record) {
        $row = DB::table('guest_report_purchases')->find($ids[$index]);

        expect((int) $row->fulfillment_attempts)->toBe($record['attempts'])
            ->and((int) $row->fulfillment_generation)->toBe(1)
            ->and((int) $row->fulfillment_generation_attempts)->toBe($record['attempts'])
            ->and((int) $row->fulfillment_recovery_attempts)->toBe(0)
            ->and((int) $row->fulfillment_dispatch_push_slots_allocated)->toBe(0)
            ->and($row->fulfillment_dispatch_uuid)->toBeNull()
            ->and($row->fulfillment_dispatch_expires_at)->toBeNull()
            ->and($row->fulfillment_claim_uuid)->toBeNull()
            ->and($row->fulfillment_claim_expires_at)->toBeNull()
            ->and($row->fulfillment_recovery_claim_entitlement_uuid)->toBeNull()
            ->and($row->fulfillment_recovery_exhausted_at)->toBeNull()
            ->and($row->fulfillment_manual_review_at)->toBeNull()
            ->and($row->fulfillment_failure_reason)->toBeNull()
            ->and($row->fulfillment_status)->toBe($record['status'])
            ->and($row->fulfillment_started_at)->toBe($record['started_at'])
            ->and($row->fulfilled_at)->toBe($record['fulfilled_at']);
    }

    $aboveMaximum = DB::table('guest_report_purchases')->find($ids[2]);

    expect((int) $aboveMaximum->fulfillment_attempts)->toBe(9)
        ->and((int) $aboveMaximum->fulfillment_generation_attempts)->toBe(9);
});

test('reliability migration handles an empty purchase table', function () {
    $migration = require database_path(
        'migrations/2026_11_10_000001_add_fulfillment_reliability_fields_to_guest_report_purchases_table.php'
    );

    $migration->down();

    expect(DB::table('guest_report_purchases')->count())->toBe(0);

    $migration->up();

    expect(Schema::hasColumns('guest_report_purchases', [
        'fulfillment_generation',
        'fulfillment_generation_attempts',
        'fulfillment_recovery_attempts',
        'fulfillment_dispatch_push_slots_allocated',
    ]))->toBeTrue()
        ->and(DB::table('guest_report_purchases')->count())->toBe(0);
});

test('phase 1 migrations roll back and restore only their own schema', function () {
    $reliabilityMigration = require database_path(
        'migrations/2026_11_10_000001_add_fulfillment_reliability_fields_to_guest_report_purchases_table.php'
    );

    $ledgerMigration = require database_path(
        'migrations/2026_11_10_000002_create_guest_report_fulfillment_attempts_table.php'
    );

    $ledgerMigration->down();
    $reliabilityMigration->down();

    expect(Schema::hasTable('guest_report_fulfillment_attempts'))->toBeFalse()
        ->and(Schema::hasColumn('guest_report_purchases', 'fulfillment_generation'))->toBeFalse()
        ->and(Schema::hasColumn('guest_report_purchases', 'fulfillment_dispatch_push_slots_allocated'))->toBeFalse()
        ->and(Schema::hasColumns('guest_report_purchases', [
            'fulfillment_status',
            'fulfillment_attempts',
            'fulfillment_started_at',
            'fulfilled_at',
        ]))->toBeTrue();

    $reliabilityMigration->up();
    $ledgerMigration->up();

    expect(Schema::hasTable('guest_report_fulfillment_attempts'))->toBeTrue()
        ->and(Schema::hasColumn('guest_report_purchases', 'fulfillment_generation'))->toBeTrue()
        ->and(Schema::hasColumn('guest_report_purchases', 'fulfillment_dispatch_push_slots_allocated'))->toBeTrue();
});

test('internal fulfillment control fields are not mass assignable', function () {
    $purchase = new GuestReportPurchase;

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
        expect($purchase->isFillable($field))->toBeFalse();
    }
});

test('mass assignment cannot change internal fulfillment control state', function () {
    $purchase = createFulfillmentSchemaPurchase();

    $known = [
        'fulfillment_generation' => 4,
        'fulfillment_dispatch_uuid' => (string) Str::uuid(),
        'fulfillment_dispatch_expires_at' => now()->addMinutes(15),
        'fulfillment_claim_uuid' => (string) Str::uuid(),
        'fulfillment_claim_expires_at' => now()->addMinutes(10),
        'fulfillment_generation_attempts' => 2,
        'fulfillment_recovery_attempts' => 1,
        'fulfillment_recovery_claim_entitlement_uuid' => (string) Str::uuid(),
        'fulfillment_recovery_exhausted_at' => now()->subMinute(),
        'fulfillment_manual_review_at' => now()->subMinutes(2),
        'fulfillment_failure_reason' => 'trusted internal failure',
        'fulfillment_dispatch_push_slots_allocated' => 2,
    ];

    // Internal state is written only through an explicit trusted operation.
    $purchase->forceFill($known)->save();
    $purchase->refresh();

    $before = $purchase->getAttributes();

    $purchase->fill([
        'fulfillment_generation' => 99,
        'fulfillment_dispatch_uuid' => (string) Str::uuid(),
        'fulfillment_dispatch_expires_at' => now()->addDay(),
        'fulfillment_claim_uuid' => (string) Str::uuid(),
        'fulfillment_claim_expires_at' => now()->addDay(),
        'fulfillment_generation_attempts' => 0,
        'fulfillment_recovery_attempts' => 0,
        'fulfillment_recovery_claim_entitlement_uuid' => (string) Str::uuid(),
        'fulfillment_recovery_exhausted_at' => now(),
        'fulfillment_manual_review_at' => now(),
        'fulfillment_failure_reason' => 'mass assigned failure',
        'fulfillment_dispatch_push_slots_allocated' => 3,
    ]);

    expect($purchase->getAttributes())->toBe($before)
        ->and($purchase->isDirty())->toBeFalse()
        ->and($purchase->fulfillment_generation)->toBe(4)
        ->and($purchase->fulfillment_generation_attempts)->toBe(2)
        ->and($purchase->fulfillment_recovery_attempts)->toBe(1)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(2)
        ->and($purchase->fulfillment_failure_reason)->toBe('trusted internal failure')
        ->and($purchase->fulfillment_dispatch_uuid)->toBe($known['fulfillment_dispatch_uuid'])
        ->and($purchase->fulfillment_claim_uuid)->toBe($known['fulfillment_claim_uuid'])
        ->and($purchase->fulfillment_recovery_claim_entitlement_uuid)
        ->toBe($known['fulfillment_recovery_claim_entitlement_uuid']);

    $purchase->save();
    $purchase->refresh();

    expect($purchase->fulfillment_generation)->toBe(4)
        ->and($purchase->fulfillment_failure_reason)->toBe('trusted internal failure');
});

test('pre-existing public purchase attributes remain fillable', function () {
    $purchase = new GuestReportPurchase;

    foreach ([
        'report_id',
        'name',
        'phone',
        'access_email_sent_at',
        'email',
        'payment_provider',
        'payment_status',
        'amount_minor',
        'currency',
        'checkout_reference',
        'stripe_price_id',
        'provider_order_id',
        'provider_payment_id',
        'paid_at',
        'failed_at',
        'expired_at',
        'refunded_at',
        'fulfillment_status',
        'fulfillment_attempts',
        'fulfillment_started_at',
        'fulfilled_at',
        'capture_status',
        'capture_attempts',
        'capture_request_started_at',
        'capture_failure_reason',
        'last_reconciled_at',
        'last_reconciliation_dispatched_at',
        'active_capture_attempt_uuid',
    ] as $field) {
        expect($purchase->isFillable($field))->toBeTrue();
    }

    $created = createFulfillmentSchemaPurchase([
        'email' => 'public-fillable@example.com',
        'fulfillment_attempts' => 3,
    ]);

    expect($created->fresh()->email)->toBe('public-fillable@example.com')
        ->and($created->fresh()->fulfillment_attempts)->toBe(3);
});

test('ledger model casts generation entitlement and lifecycle timestamps', function () {
    $purchase = createFulfillmentSchemaPurchase();

    $attempt = createFulfillmentSchemaAttempt($purchase, [
        'generation' => 7,
        'entitlement_used' => true,
        'claimed_at' => now()->subMinutes(5),
        'released_at' => now()->subMinutes(4),
        'completed_at' => now()->subMinutes(3),
        'failed_at' => now()->subMinutes(2),
        'abandoned_at' => now()->subMinute(),
    ]);

    $attempt->refresh();

    $casts = $attempt->getCasts();

    expect($casts['generation'])->toBe('integer')
        ->and($casts['entitlement_used'])->toBe('boolean')
        ->and($casts['claimed_at'])->toBe('datetime')
        ->and($casts['released_at'])->toBe('datetime')
        ->and($casts['completed_at'])->toBe('datetime')
        ->and($casts['failed_at'])->toBe('datetime')
        ->and($casts['abandoned_at'])->toBe('datetime');

    expect($attempt->generation)->toBeInt()->toBe(7)
        ->and($attempt->entitlement_used)->toBeBool()->toBeTrue()
        ->and($attempt->claimed_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($attempt->released_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($attempt->completed_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($attempt->failed_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($attempt->abandoned_at)->toBeInstanceOf(CarbonInterface::class);
});

test('purchase dispatch uuid unique constraint rejects duplicates', function () {
    $dispatchUuid = (string) Str::uuid();

    $first = createFulfillmentSchemaPurchase();
    $first->forceFill(['fulfillment_dispatch_uuid' => $dispatchUuid])->save();

    $second = createFulfillmentSchemaPurchase();

    expect(fn () => $second->forceFill(['fulfillment_dispatch_uuid' => $dispatchUuid])->save())
        ->toThrow(QueryException::class);

    expect(GuestReportPurchase::where('fulfillment_dispatch_uuid', $dispatchUuid)->count())->toBe(1);
});

test('purchase claim uuid unique constraint rejects duplicates', function () {
    $claimUuid = (string) Str::uuid();

    $first = createFulfillmentSchemaPurchase();
    $first->forceFill(['fulfillment_claim_uuid' => $claimUuid])->save();

    $second = createFulfillmentSchemaPurchase();

    expect(fn () => $second->forceFill(['fulfillment_claim_uuid' => $claimUuid])->save())
        ->toThrow(QueryException::class);

    expect(GuestReportPurchase::where('fulfillment_claim_uuid', $claimUuid)->count())->toBe(1);
});
