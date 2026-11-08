<?php

use App\Models\GuestReportCaptureAttempt;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function createTestGuestPurchase(array $attributes = []): GuestReportPurchase
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
        'payment_provider' => 'paypal',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'provider_order_id' => 'ORDER-'.Str::random(10),
    ], $attributes));
}

test('guest report purchases table has all seven capture tracking columns and indexes', function () {
    expect(Schema::hasTable('guest_report_purchases'))->toBeTrue();

    expect(Schema::hasColumns('guest_report_purchases', [
        'capture_status',
        'capture_attempts',
        'capture_request_started_at',
        'capture_failure_reason',
        'last_reconciled_at',
        'last_reconciliation_dispatched_at',
        'active_capture_attempt_uuid',
    ]))->toBeTrue();
});

test('guest report capture attempts table has the expected schema', function () {
    expect(Schema::hasTable('guest_report_capture_attempts'))->toBeTrue();

    expect(Schema::hasColumns('guest_report_capture_attempts', [
        'id',
        'guest_report_purchase_id',
        'attempt_uuid',
        'idempotency_key',
        'provider_order_id',
        'status',
        'attempt_number',
        'failure_reason',
        'request_started_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('capture attempt can be created and is linked to the correct guest purchase', function () {
    $purchase = createTestGuestPurchase();
    $attemptUuid = (string) Str::uuid();
    $idempotencyKey = "guest-cap-{$attemptUuid}";

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => $attemptUuid,
        'idempotency_key' => $idempotencyKey,
        'provider_order_id' => $purchase->provider_order_id,
        'status' => GuestReportCaptureAttempt::STATUS_INITIATED,
        'attempt_number' => 1,
        'request_started_at' => now(),
    ]);

    expect($attempt->id)->not->toBeNull()
        ->and($attempt->guest_report_purchase_id)->toBe($purchase->id)
        ->and($attempt->attempt_uuid)->toBe($attemptUuid)
        ->and($attempt->idempotency_key)->toBe($idempotencyKey)
        ->and($attempt->provider_order_id)->toBe($purchase->provider_order_id)
        ->and($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_INITIATED)
        ->and($attempt->attempt_number)->toBe(1)
        ->and($attempt->purchase->id)->toBe($purchase->id);
});

test('attempt UUID must be unique', function () {
    $purchase = createTestGuestPurchase();
    $sharedUuid = (string) Str::uuid();

    GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => $sharedUuid,
        'idempotency_key' => 'key-unique-1',
        'provider_order_id' => 'ORDER-1',
        'status' => 'initiated',
        'attempt_number' => 1,
    ]);

    expect(fn () => GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => $sharedUuid,
        'idempotency_key' => 'key-unique-2',
        'provider_order_id' => 'ORDER-2',
        'status' => 'initiated',
        'attempt_number' => 2,
    ]))->toThrow(QueryException::class);
});

test('idempotency key must be unique', function () {
    $purchase = createTestGuestPurchase();
    $sharedKey = 'guest-cap-shared-idempotency-key';

    GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => $sharedKey,
        'provider_order_id' => 'ORDER-1',
        'status' => 'initiated',
        'attempt_number' => 1,
    ]);

    expect(fn () => GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => $sharedKey,
        'provider_order_id' => 'ORDER-2',
        'status' => 'initiated',
        'attempt_number' => 2,
    ]))->toThrow(QueryException::class);
});

test('attempt number is preserved accurately and defaults correctly', function () {
    $purchase = createTestGuestPurchase();

    $defaultAttempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'key-'.Str::random(8),
        'provider_order_id' => 'ORDER-DEFAULT',
    ]);

    expect($defaultAttempt->attempt_number)->toBe(1);

    $defaultAttempt->refresh();
    expect($defaultAttempt->attempt_number)->toBe(1);

    $secondAttempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'key-'.Str::random(8),
        'provider_order_id' => 'ORDER-SECOND',
        'attempt_number' => 2,
    ]);

    expect($secondAttempt->attempt_number)->toBe(2);
});

test('captureAttempts relationship works in both directions', function () {
    $purchase = createTestGuestPurchase();

    $attempt1 = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'key-rel-1',
        'provider_order_id' => 'ORDER-REL',
        'status' => GuestReportCaptureAttempt::STATUS_FUNDING_DECLINED,
        'attempt_number' => 1,
    ]);

    $attempt2 = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'key-rel-2',
        'provider_order_id' => 'ORDER-REL',
        'status' => GuestReportCaptureAttempt::STATUS_COMPLETED,
        'attempt_number' => 2,
    ]);

    $purchase->refresh();

    expect($purchase->captureAttempts)->toHaveCount(2)
        ->and($purchase->captureAttempts->pluck('id')->all())->toBe([$attempt1->id, $attempt2->id])
        ->and($attempt1->purchase->id)->toBe($purchase->id)
        ->and($attempt2->purchase->id)->toBe($purchase->id);
});

test('integer and timestamp casts are properly applied on both models', function () {
    $now = now()->startOfSecond();

    $purchase = createTestGuestPurchase([
        'capture_attempts' => 3,
        'capture_request_started_at' => $now,
        'last_reconciled_at' => $now->copy()->addMinutes(5),
        'last_reconciliation_dispatched_at' => $now->copy()->addMinutes(10),
    ]);

    expect($purchase->capture_attempts)->toBeInt()->toBe(3)
        ->and($purchase->capture_request_started_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($purchase->capture_request_started_at->timestamp)->toBe($now->timestamp)
        ->and($purchase->last_reconciled_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($purchase->last_reconciled_at->timestamp)->toBe($now->copy()->addMinutes(5)->timestamp)
        ->and($purchase->last_reconciliation_dispatched_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($purchase->last_reconciliation_dispatched_at->timestamp)->toBe($now->copy()->addMinutes(10)->timestamp);

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'key-cast-'.Str::random(6),
        'provider_order_id' => 'ORDER-CAST',
        'attempt_number' => '4', // string input to test cast
        'request_started_at' => $now,
    ]);

    expect($attempt->attempt_number)->toBeInt()->toBe(4)
        ->and($attempt->request_started_at)->toBeInstanceOf(CarbonInterface::class)
        ->and($attempt->request_started_at->timestamp)->toBe($now->timestamp);
});

test('migration rollback drops the table and columns safely without error', function () {
    $trackingMigration = require database_path('migrations/2026_11_08_140000_add_capture_tracking_to_guest_report_purchases_table.php');
    $attemptsMigration = require database_path('migrations/2026_11_08_140001_create_guest_report_capture_attempts_table.php');

    // 1. Verify schema is currently present
    expect(Schema::hasTable('guest_report_capture_attempts'))->toBeTrue()
        ->and(Schema::hasColumn('guest_report_purchases', 'capture_status'))->toBeTrue()
        ->and(Schema::hasColumn('guest_report_purchases', 'active_capture_attempt_uuid'))->toBeTrue();

    // 2. Rollback the migrations
    $attemptsMigration->down();
    $trackingMigration->down();

    // 3. Verify table and columns are dropped
    expect(Schema::hasTable('guest_report_capture_attempts'))->toBeFalse()
        ->and(Schema::hasColumn('guest_report_purchases', 'capture_status'))->toBeFalse()
        ->and(Schema::hasColumn('guest_report_purchases', 'active_capture_attempt_uuid'))->toBeFalse();

    // 4. Re-run migrations to restore state
    $trackingMigration->up();
    $attemptsMigration->up();

    expect(Schema::hasTable('guest_report_capture_attempts'))->toBeTrue()
        ->and(Schema::hasColumn('guest_report_purchases', 'capture_status'))->toBeTrue();
});

test('migration backfill classifies historical records conservatively without synthetic timestamps', function () {
    $trackingMigration = require database_path('migrations/2026_11_08_140000_add_capture_tracking_to_guest_report_purchases_table.php');
    $attemptsMigration = require database_path('migrations/2026_11_08_140001_create_guest_report_capture_attempts_table.php');

    // Rollback the Phase 1 migrations first so we can seed historical records
    $attemptsMigration->down();
    $trackingMigration->down();

    $vehicle = Vehicle::create(['registration_number' => 'HIST-001']);
    $report = Report::create(['vehicle_id' => $vehicle->id, 'report_type' => 'basic']);

    // Seed historical pre-Phase-3 records directly via DB table
    $paidWithProofId = DB::table('guest_report_purchases')->insertGetId([
        'report_id' => $report->id,
        'email' => 'paid-proof@example.com',
        'payment_provider' => 'paypal',
        'payment_status' => 'paid',
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'checkout_reference' => (string) Str::uuid(),
        'provider_order_id' => 'ORDER-PAID-1',
        'provider_payment_id' => 'CAPTURE-PROVEN-123',
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $paidWithoutProofId = DB::table('guest_report_purchases')->insertGetId([
        'report_id' => $report->id,
        'email' => 'paid-noproof@example.com',
        'payment_provider' => 'paypal',
        'payment_status' => 'paid',
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'checkout_reference' => (string) Str::uuid(),
        'provider_order_id' => 'ORDER-PAID-2',
        'provider_payment_id' => null,
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $failedId = DB::table('guest_report_purchases')->insertGetId([
        'report_id' => $report->id,
        'email' => 'failed@example.com',
        'payment_provider' => 'paypal',
        'payment_status' => 'failed',
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'checkout_reference' => (string) Str::uuid(),
        'provider_order_id' => 'ORDER-FAIL-1',
        'failed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $expiredId = DB::table('guest_report_purchases')->insertGetId([
        'report_id' => $report->id,
        'email' => 'expired@example.com',
        'payment_provider' => 'paypal',
        'payment_status' => 'expired',
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'checkout_reference' => (string) Str::uuid(),
        'provider_order_id' => 'ORDER-EXP-1',
        'expired_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $refundedId = DB::table('guest_report_purchases')->insertGetId([
        'report_id' => $report->id,
        'email' => 'refunded@example.com',
        'payment_provider' => 'paypal',
        'payment_status' => 'refunded',
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'checkout_reference' => (string) Str::uuid(),
        'provider_order_id' => 'ORDER-REF-1',
        'provider_payment_id' => 'CAPTURE-REF-123',
        'refunded_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pendingId = DB::table('guest_report_purchases')->insertGetId([
        'report_id' => $report->id,
        'email' => 'pending@example.com',
        'payment_provider' => 'paypal',
        'payment_status' => 'pending',
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'checkout_reference' => (string) Str::uuid(),
        'provider_order_id' => 'ORDER-PEND-1',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Re-run migration to execute the backfill
    $trackingMigration->up();
    $attemptsMigration->up();

    // 1. Paid with proof -> completed
    $paidWithProof = DB::table('guest_report_purchases')->find($paidWithProofId);
    expect($paidWithProof->capture_status)->toBe('completed')
        ->and($paidWithProof->capture_attempts)->toBe(1);

    // 2. Paid without proof -> capture_reconciliation_required
    $paidWithoutProof = DB::table('guest_report_purchases')->find($paidWithoutProofId);
    expect($paidWithoutProof->capture_status)->toBe('capture_reconciliation_required')
        ->and($paidWithoutProof->capture_attempts)->toBe(1)
        ->and($paidWithoutProof->capture_failure_reason)->toBe('Legacy paid record missing verified provider payment ID.');

    // 3. Failed -> failed
    $failed = DB::table('guest_report_purchases')->find($failedId);
    expect($failed->capture_status)->toBe('failed')
        ->and($failed->capture_attempts)->toBe(1);

    // 4. Expired -> failed
    $expired = DB::table('guest_report_purchases')->find($expiredId);
    expect($expired->capture_status)->toBe('failed')
        ->and($expired->capture_attempts)->toBe(1)
        ->and($expired->capture_failure_reason)->toBe('Purchase expired before capture.');

    // 5. Refunded -> completed
    $refunded = DB::table('guest_report_purchases')->find($refundedId);
    expect($refunded->capture_status)->toBe('completed')
        ->and($refunded->capture_attempts)->toBe(1);

    // 6. Pending -> legacy_untracked, zero synthetic timestamps
    $pending = DB::table('guest_report_purchases')->find($pendingId);
    expect($pending->capture_status)->toBe('legacy_untracked')
        ->and($pending->capture_attempts)->toBe(0)
        ->and($pending->capture_request_started_at)->toBeNull()
        ->and($pending->last_reconciled_at)->toBeNull()
        ->and($pending->last_reconciliation_dispatched_at)->toBeNull();
});
