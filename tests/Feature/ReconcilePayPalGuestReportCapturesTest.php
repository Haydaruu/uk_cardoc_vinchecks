<?php

use App\Jobs\ProcessVehicleCheck;
use App\Jobs\ReconcileGuestReportPurchaseJob;
use App\Jobs\SendTelegramSaleNotification;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\GuestCaptureResult;
use App\Services\GuestReportCaptureService;
use App\Services\PayPalService;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

function createReconcileTestPurchase(array $attributes = []): GuestReportPurchase
{
    $registration = 'REC'.strtoupper(Str::random(6));

    $vinCheck = VinCheck::create([
        'registration_number' => $registration,
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['level' => 'basic'],
    ]);

    $createdAt = $attributes['created_at'] ?? null;
    unset($attributes['created_at']);

    $purchase = GuestReportPurchase::create(array_merge([
        'report_id' => $report->id,
        'name' => 'Reconcile Test User',
        'email' => 'rec-'.Str::random(6).'@example.com',
        'phone' => '+447000000000',
        'payment_provider' => 'paypal',
        'provider_order_id' => 'ORDER-REC-'.strtoupper(Str::random(10)),
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_LOCKED,
        'fulfillment_attempts' => 0,
    ], $attributes));

    if ($createdAt !== null) {
        $purchase->timestamps = false;
        $purchase->created_at = $createdAt;
        $purchase->save();
        $purchase->timestamps = true;
    }

    return $purchase;
}

// ─────────────────────────────────────────────────────────────
// Candidate Eligibility & Starvation Tests
// ─────────────────────────────────────────────────────────────

test('it selects eligible candidates across all required pending capture statuses', function () {
    Queue::fake();

    // Routine bulk reconciliation includes active in-flight capture, bank clearance pending,
    // funding declined recovery, flagged reconciliation, legacy untracked, and aged NULL records.
    $eligibleStatuses = [
        GuestReportPurchase::CAPTURE_CAPTURING,
        GuestReportPurchase::CAPTURE_PENDING,
        GuestReportPurchase::CAPTURE_FUNDING_DECLINED,
        GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
        GuestReportPurchase::CAPTURE_LEGACY_UNTRACKED,
    ];

    $purchases = [];
    foreach ($eligibleStatuses as $status) {
        $purchases[] = createReconcileTestPurchase([
            'capture_status' => $status,
            'payment_status' => GuestReportPurchase::STATUS_PENDING,
        ]);
    }

    // Aged NULL record (created 20 minutes ago)
    $purchases[] = createReconcileTestPurchase([
        'capture_status' => null,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'created_at' => now()->subMinutes(20),
    ]);

    $this->artisan('paypal:reconcile-guest-captures', ['--limit' => 10])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 6 guest report purchase(s)');

    foreach ($purchases as $purchase) {
        Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($purchase) {
            return $job->orderId === $purchase->provider_order_id;
        });
    }
});

test('it strictly excludes pending purchases with null capture_status created less than 15 minutes ago', function () {
    Queue::fake();

    // In-flight checkout session created 5 minutes ago
    createReconcileTestPurchase([
        'capture_status' => null,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'created_at' => now()->subMinutes(5),
    ]);

    // Another created 14 minutes ago
    createReconcileTestPurchase([
        'capture_status' => null,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'created_at' => now()->subMinutes(14),
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No eligible purchases require reconciliation');

    Queue::assertNothingPushed();
});

test('it strictly excludes pending purchases with null capture_status at the exact 15-minute boundary', function () {
    Queue::fake();

    $now = Carbon::parse('2026-11-08 15:00:00');
    Carbon::setTestNow($now);

    // Created exactly 15 minutes ago (created_at == fifteenMinutesAgo); strictly < requires older
    createReconcileTestPurchase([
        'capture_status' => null,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'created_at' => $now->copy()->subMinutes(15),
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No eligible purchases require reconciliation');

    Queue::assertNothingPushed();

    Carbon::setTestNow();
});

test('it selects pending purchases with null capture_status strictly older than 15 minutes', function () {
    Queue::fake();

    $now = Carbon::parse('2026-11-08 15:00:00');
    Carbon::setTestNow($now);

    $agedPurchase = createReconcileTestPurchase([
        'capture_status' => null,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'created_at' => $now->copy()->subMinutes(15)->subSecond(),
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 1 guest report purchase(s)');

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($agedPurchase) {
        return $job->orderId === $agedPurchase->provider_order_id;
    });

    Carbon::setTestNow();
});

test('it selects legacy_untracked purchases regardless of age', function () {
    Queue::fake();

    $legacy = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_LEGACY_UNTRACKED,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'created_at' => now()->subMinute(), // Recent, but legacy_untracked is always eligible
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 1 guest report purchase(s)');

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($legacy) {
        return $job->orderId === $legacy->provider_order_id;
    });
});

test('it strictly excludes payer_action_required purchases from routine bulk reconciliation', function () {
    Queue::fake();

    createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_PAYER_ACTION_REQUIRED,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No eligible purchases require reconciliation');

    Queue::assertNothingPushed();
});

test('it includes funding_declined purchases in routine reconciliation', function () {
    Queue::fake();

    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_FUNDING_DECLINED,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 1 guest report purchase(s)');

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($purchase) {
        return $job->orderId === $purchase->provider_order_id;
    });
});

test('it selects paid records requiring proof verification', function () {
    Queue::fake();

    // Paid record with non-completed capture status
    $p1 = createReconcileTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
        'provider_payment_id' => null,
    ]);

    // Paid record with completed capture status but missing provider_payment_id proof
    $p2 = createReconcileTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => null,
    ]);

    $this->artisan('paypal:reconcile-guest-captures', ['--limit' => 10])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 2 guest report purchase(s)');

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($p1) {
        return $job->orderId === $p1->provider_order_id;
    });

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($p2) {
        return $job->orderId === $p2->provider_order_id;
    });
});

test('it strictly excludes fully settled completed purchases with proof', function () {
    Queue::fake();

    createReconcileTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-PROVEN-123',
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No eligible purchases require reconciliation');

    Queue::assertNothingPushed();
});

test('it strictly excludes terminal failed, refunded, and expired purchases', function () {
    Queue::fake();

    createReconcileTestPurchase([
        'payment_status' => GuestReportPurchase::PAYMENT_STATUS_FAILED,
        'capture_status' => GuestReportPurchase::CAPTURE_STATUS_FAILED,
    ]);

    createReconcileTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
    ]);

    createReconcileTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_EXPIRED,
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No eligible purchases require reconciliation');

    Queue::assertNothingPushed();
});

test('it respects the limit option', function () {
    Queue::fake();

    for ($i = 0; $i < 5; $i++) {
        createReconcileTestPurchase([
            'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        ]);
    }

    $this->artisan('paypal:reconcile-guest-captures', ['--limit' => 3])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 3 guest report purchase(s)');

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, 3);
});

test('it throttles purchases dispatched less than 15 minutes ago to prevent starvation', function () {
    Queue::fake();

    // Dispatched 5 minutes ago (within 15m cooldown) -> should be skipped
    createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciliation_dispatched_at' => now()->subMinutes(5),
    ]);

    // Dispatched 20 minutes ago (beyond 15m cooldown) -> should be eligible
    $eligible = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciliation_dispatched_at' => now()->subMinutes(20),
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 1 guest report purchase(s)');

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, 1);
    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($eligible) {
        return $job->orderId === $eligible->provider_order_id;
    });
});

test('it atomically updates last_reconciliation_dispatched_at upon selection', function () {
    Queue::fake();

    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciliation_dispatched_at' => null,
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS);

    $purchase->refresh();
    expect($purchase->last_reconciliation_dispatched_at)->not->toBeNull()
        ->and($purchase->last_reconciliation_dispatched_at->diffInSeconds(now()))->toBeLessThan(5);
});

// ─────────────────────────────────────────────────────────────
// Dry-Run Mode Tests
// ─────────────────────────────────────────────────────────────

test('dry-run displays candidates in table without mutating database or dispatching jobs', function () {
    Queue::fake();

    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciliation_dispatched_at' => null,
    ]);

    $this->artisan('paypal:reconcile-guest-captures', ['--dry-run' => true])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dry-run: 1 candidate(s) would be dispatched')
        ->expectsOutputToContain($purchase->provider_order_id)
        ->expectsOutputToContain('Summary — Scanned: 1 | Eligible: 1 | Dispatched: 0 | Skipped: 0');

    // No jobs dispatched
    Queue::assertNothingPushed();

    // No dispatch timestamp stamped
    $purchase->refresh();
    expect($purchase->last_reconciliation_dispatched_at)->toBeNull();
});

test('dry-run displays empty notice when no candidates eligible', function () {
    Queue::fake();

    $this->artisan('paypal:reconcile-guest-captures', ['--dry-run' => true])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No eligible purchases require reconciliation at this time.');

    Queue::assertNothingPushed();
});

// ─────────────────────────────────────────────────────────────
// Dispatch Failure Isolation & Claim Release Tests
// ─────────────────────────────────────────────────────────────

test('dispatch failure isolates candidate and safely releases claim without blocking subsequent candidates', function () {
    $p1 = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'provider_order_id' => 'ORDER-FAIL-1',
    ]);
    $p2 = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'provider_order_id' => 'ORDER-FAIL-2',
    ]);
    $p3 = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'provider_order_id' => 'ORDER-FAIL-3',
    ]);

    $dispatchedOrders = [];

    Queue::fake()->beforePushing(function ($job) use (&$dispatchedOrders) {
        if ($job instanceof ReconcileGuestReportPurchaseJob) {
            if ($job->orderId === 'ORDER-FAIL-2') {
                throw new RuntimeException('Queue connection failed for candidate 2');
            }
            $dispatchedOrders[] = $job->orderId;
        }
    });

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 2 guest report purchase(s)')
        ->expectsOutputToContain('Dispatch failed for 1 candidate(s); claims safely released.');

    // p1 and p3 were dispatched; p2 failed
    expect($dispatchedOrders)->toBe(['ORDER-FAIL-1', 'ORDER-FAIL-3']);

    // p1 and p3 retain their dispatch claim timestamp
    $p1->refresh();
    $p3->refresh();
    expect($p1->last_reconciliation_dispatched_at)->not->toBeNull();
    expect($p3->last_reconciliation_dispatched_at)->not->toBeNull();

    // p2 had its claim safely released back to null
    $p2->refresh();
    expect($p2->last_reconciliation_dispatched_at)->toBeNull();
});

test('concurrent claim protection prevents release if timestamp changed during failure', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'provider_order_id' => 'ORDER-CONCURRENT',
    ]);

    Queue::fake()->beforePushing(function ($job) use ($purchase) {
        if ($job instanceof ReconcileGuestReportPurchaseJob && $job->orderId === 'ORDER-CONCURRENT') {
            // Simulate a concurrent runner updating the record right before failure handler executes
            GuestReportPurchase::query()
                ->where('id', $purchase->id)
                ->update(['last_reconciliation_dispatched_at' => now()->addMinutes(5)]);

            throw new RuntimeException('Simulated queue crash');
        }
    });

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS);

    $purchase->refresh();
    // The timestamp was modified concurrently and must NOT be reset to null!
    expect($purchase->last_reconciliation_dispatched_at)->not->toBeNull()
        ->and($purchase->last_reconciliation_dispatched_at->isFuture())->toBeTrue();
});

test('expired claim recovers naturally after 15 minute cooldown', function () {
    Queue::fake();

    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciliation_dispatched_at' => now()->subMinutes(16), // Expired claim
    ]);

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Dispatched 1 guest report purchase(s)');

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($purchase) {
        return $job->orderId === $purchase->provider_order_id;
    });
});

// ─────────────────────────────────────────────────────────────
// Queue Uniqueness & Cooldown Tests
// ─────────────────────────────────────────────────────────────

test('it detects already_queued jobs and does not count them as new dispatches', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'provider_order_id' => 'ORDER-ALREADY-QUEUED',
    ]);

    // Manually hold the ShouldBeUnique lock for this order
    $job = new ReconcileGuestReportPurchaseJob($purchase->provider_order_id);
    $uniqueKey = UniqueLock::getKey($job);
    $lock = Cache::lock($uniqueKey, $job->uniqueFor);
    $acquired = $lock->get();
    expect($acquired)->toBeTrue();

    Queue::fake();

    $this->artisan('paypal:reconcile-guest-captures')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Skipped 1 candidate(s) already active in queue')
        ->expectsOutputToContain('Summary — Scanned: 1 | Eligible: 1 | Dispatched: 0 | Skipped: 0 | Already queued: 1 | Dispatch failed: 0');

    // No new job should have been pushed
    Queue::assertNothingPushed();

    $lock->forceRelease();
});

test('unique lock duration is 900 seconds aligning with scheduler cadence', function () {
    $job = new ReconcileGuestReportPurchaseJob('ORDER-UNIQUE-TIME');

    expect($job->uniqueFor)->toBe(900)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 30])
        ->and($job->uniqueId())->toBe('paypal-guest-reconcile:ORDER-UNIQUE-TIME');
});

// ─────────────────────────────────────────────────────────────
// Execution & GET-Only Provider Verification Tests
// ─────────────────────────────────────────────────────────────

test('reconcile job executes reconcileGetOnly and updates last_reconciled_at on success', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
    ]);
    $orderId = $purchase->provider_order_id;

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('reconcileGetOnly')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(status: 'completed', reconciled: true, httpStatus: 200));

    $job = new ReconcileGuestReportPurchaseJob($orderId);
    $result = $job->handle($captureServiceMock);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->reconciled)->toBeTrue();

    $purchase->refresh();
    expect($purchase->last_reconciled_at)->not->toBeNull()
        ->and($purchase->last_reconciled_at->diffInSeconds(now()))->toBeLessThan(5);
});

test('reconcile job does not update last_reconciled_at when service fails or throws exception', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciled_at' => null,
    ]);
    $orderId = $purchase->provider_order_id;

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('reconcileGetOnly')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(
            status: 'failed',
            errorMessage: 'Provider network communication failed.',
            reconciled: false,
            httpStatus: 500,
        ));

    $job = new ReconcileGuestReportPurchaseJob($orderId);
    $result = $job->handle($captureServiceMock);

    expect($result->isFailed())->toBeTrue()
        ->and($result->reconciled)->toBeFalse();

    $purchase->refresh();
    expect($purchase->last_reconciled_at)->toBeNull();
});

test('reconcile job does not update last_reconciled_at on lock timeout', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciled_at' => null,
    ]);
    $orderId = $purchase->provider_order_id;

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('reconcileGetOnly')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(
            status: 'processing',
            errorMessage: 'Payment capture is currently being processed by another worker.',
            reconciled: false,
            httpStatus: 202,
        ));

    $job = new ReconcileGuestReportPurchaseJob($orderId);
    $result = $job->handle($captureServiceMock);

    expect($result->isProcessing())->toBeTrue()
        ->and($result->reconciled)->toBeFalse();

    $purchase->refresh();
    expect($purchase->last_reconciled_at)->toBeNull();
});

test('reconcile job updates last_reconciled_at on valid APPROVED provider response', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciled_at' => null,
    ]);
    $orderId = $purchase->provider_order_id;

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('reconcileGetOnly')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(
            status: 'processing',
            errorMessage: 'Order is approved and awaiting capture.',
            reconciled: true,
            httpStatus: 202,
        ));

    $job = new ReconcileGuestReportPurchaseJob($orderId);
    $result = $job->handle($captureServiceMock);

    expect($result->isProcessing())->toBeTrue()
        ->and($result->reconciled)->toBeTrue();

    $purchase->refresh();
    expect($purchase->last_reconciled_at)->not->toBeNull();
});

test('reconciliation of funding_declined purchase performs GET-only without POST capture', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_FUNDING_DECLINED,
    ]);
    $orderId = $purchase->provider_order_id;

    $payPalMock = Mockery::mock(PayPalService::class);
    // GET order returns still APPROVED (buyer has not yet used another funding source)
    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andReturn([
            'id' => $orderId,
            'status' => 'APPROVED',
            'purchase_units' => [
                [
                    'reference_id' => (string) $purchase->id,
                    'custom_id' => "guest-report|{$purchase->checkout_reference}|{$purchase->report_id}",
                    'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
                ],
            ],
        ]);

    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturnArg(1);

    // POST capture must NEVER be issued during reconciliation
    $payPalMock->shouldNotReceive('captureOrder');

    app()->instance(PayPalService::class, $payPalMock);

    $job = new ReconcileGuestReportPurchaseJob($orderId);
    $result = $job->handle(app(GuestReportCaptureService::class));

    // Must return 202 processing without error, preserving GET-only guarantee
    expect($result->isProcessing())->toBeTrue()
        ->and($result->reconciled)->toBeTrue();

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_FUNDING_DECLINED)
        ->and($purchase->last_reconciled_at)->not->toBeNull();
});

test('reconciliation settles order and triggers fulfillment when provider confirms status is COMPLETED', function () {
    Queue::fake();

    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
    ]);
    $orderId = $purchase->provider_order_id;
    $captureId = 'CAP-RECON-FOUND-123';

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andReturn([
            'id' => $orderId,
            'status' => 'COMPLETED',
            'purchase_units' => [
                [
                    'reference_id' => (string) $purchase->id,
                    'custom_id' => "guest-report|{$purchase->checkout_reference}|{$purchase->report_id}",
                    'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
                    'payments' => [
                        'captures' => [
                            [
                                'id' => $captureId,
                                'status' => 'COMPLETED',
                                'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturnArg(1);

    $payPalMock->shouldNotReceive('captureOrder');
    app()->instance(PayPalService::class, $payPalMock);

    $job = new ReconcileGuestReportPurchaseJob($orderId);
    $result = $job->handle(app(GuestReportCaptureService::class));

    expect($result->isCompleted())->toBeTrue()
        ->and($result->reconciled)->toBeTrue();

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe($captureId)
        ->and($purchase->last_reconciled_at)->not->toBeNull();

    Queue::assertPushed(ProcessVehicleCheck::class);
});

test('reconciliation with empty captures array rejects settlement and preserves safe state', function () {
    Queue::fake();

    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'provider_payment_id' => null,
        'last_reconciled_at' => null,
    ]);
    $orderId = $purchase->provider_order_id;

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andReturn([
            'id' => $orderId,
            'status' => 'COMPLETED',
            'purchase_units' => [
                [
                    'reference_id' => (string) $purchase->id,
                    'custom_id' => "guest-report|{$purchase->checkout_reference}|{$purchase->report_id}",
                    'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
                    'payments' => [
                        'captures' => [],
                    ],
                ],
            ],
        ]);

    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturnArg(1);

    // Verify GET-only constraint: captureOrder (POST) must never be called during background reconciliation
    $payPalMock->shouldNotReceive('captureOrder');
    app()->instance(PayPalService::class, $payPalMock);

    $job = new ReconcileGuestReportPurchaseJob($orderId);

    // Verify that empty captures array cannot produce successful settlement and throws exception
    expect(fn () => $job->handle(app(GuestReportCaptureService::class)))
        ->toThrow(RuntimeException::class, 'PayPal capture is not completed.');

    $purchase->refresh();

    // 1. Purchase is NOT marked paid
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING)
        ->and($purchase->payment_status)->not->toBe(GuestReportPurchase::STATUS_PAID);

    // 2. No provider capture ID is fabricated or stored
    expect($purchase->provider_payment_id)->toBeNull();

    // 3. Purchase remains in safe reconciliation state
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED);

    // 4. last_reconciled_at follows proof-verification policy and is not advanced
    expect($purchase->last_reconciled_at)->toBeNull();

    // 5. No fulfillment job is dispatched
    Queue::assertNotPushed(ProcessVehicleCheck::class);

    // 6. No Telegram sales notification is triggered
    Queue::assertNotPushed(SendTelegramSaleNotification::class);
});

// ─────────────────────────────────────────────────────────────
// Single Order Mode & Console Schedule Registration Tests
// ─────────────────────────────────────────────────────────────

test('single order mode via --order-id reconciles specific purchase directly', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
    ]);
    $orderId = $purchase->provider_order_id;

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('reconcileGetOnly')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(status: 'completed', reconciled: true, httpStatus: 200));

    app()->instance(GuestReportCaptureService::class, $captureServiceMock);

    $this->artisan('paypal:reconcile-guest-captures', ['--order-id' => $orderId])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain("Reconciling single purchase #{$purchase->id}")
        ->expectsOutputToContain('Status: completed');

    $purchase->refresh();
    expect($purchase->last_reconciliation_dispatched_at)->not->toBeNull()
        ->and($purchase->last_reconciled_at)->not->toBeNull();
});

test('single order mode with --queue dispatches job instead of executing synchronously', function () {
    Queue::fake();

    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
    ]);
    $orderId = $purchase->provider_order_id;

    $this->artisan('paypal:reconcile-guest-captures', ['--order-id' => $orderId, '--queue' => true])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain("Dispatched order [{$orderId}] to reconciliation queue.");

    Queue::assertPushed(ReconcileGuestReportPurchaseJob::class, function ($job) use ($orderId) {
        return $job->orderId === $orderId;
    });
});

test('single order mode does not update last_reconciled_at when reconcileGetOnly reports unreconciled', function () {
    $purchase = createReconcileTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciled_at' => null,
    ]);
    $orderId = $purchase->provider_order_id;

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('reconcileGetOnly')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(status: 'failed', reconciled: false, httpStatus: 500));

    app()->instance(GuestReportCaptureService::class, $captureServiceMock);

    $this->artisan('paypal:reconcile-guest-captures', ['--order-id' => $orderId])
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('Reconciled: no');

    $purchase->refresh();
    expect($purchase->last_reconciliation_dispatched_at)->not->toBeNull()
        ->and($purchase->last_reconciled_at)->toBeNull();
});

test('single order mode returns failure when order id does not exist', function () {
    $this->artisan('paypal:reconcile-guest-captures', ['--order-id' => 'NON-EXISTENT-ORDER-999'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('not found');
});

test('scheduled command is registered in the console schedule to run every fifteen minutes', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(function ($event) {
        return str_contains((string) $event->command, 'paypal:reconcile-guest-captures');
    });

    expect($events)->not->toBeEmpty();

    $event = $events->first();
    expect($event->expression)->toBe('*/15 * * * *');
});
