<?php

use App\Contracts\VehicleDataProviderInterface;
use App\Jobs\ProcessVehicleCheck;
use App\Jobs\SendGuestReportAccessEmail;
use App\Models\GuestReportFulfillmentAttempt;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\CreditService;
use App\Services\GuestReportFulfillmentService;
use App\Services\Reports\NormalizedReportBuilder;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Queue::fake();
});

/**
 * @return array{0: GuestReportPurchase, 1: VinCheck, 2: Report, 3: string}
 */
function m3bJobPurchase(): array
{
    $registration = 'M3BJOB'.Str::upper(Str::random(6));

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => $registration,
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['level' => 'basic'],
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest-'.Str::random(6).'@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'provider_order_id' => 'ORDER-'.Str::random(10),
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);

    return [$purchase, $vinCheck, $report, $registration];
}

/**
 * @return array<string, mixed>
 */
function m3bJobNormalized(): array
{
    return [
        'vehicle' => [
            'vin' => 'VIN-M3B-JOB-001',
            'make' => 'Ford',
            'model' => 'Focus',
            'year' => 2020,
            'colour' => 'Blue',
            'fuel_type' => 'Petrol',
            'engine_capacity_cc' => 1000,
            'image_url' => null,
        ],
        'history' => [
            'finance' => ['count' => 0],
            'write_off' => ['records' => []],
        ],
        'mot' => [
            'tests' => [
                [
                    'date' => '2025-01-01',
                    'expiry_date' => '2026-01-01',
                ],
            ],
            'tax_status' => 'Taxed',
        ],
        'specifications' => [
            'co2_gkm' => 100,
        ],
    ];
}

function m3bPushedJob(): ProcessVehicleCheck
{
    $job = null;

    Queue::assertPushed(ProcessVehicleCheck::class, function (ProcessVehicleCheck $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    expect($job)->not->toBeNull();

    return $job;
}

/**
 * @return array{0: ProcessVehicleCheck, 1: GuestReportPurchase, 2: string}
 */
function m3bReserveJob(): array
{
    [$purchase, , , $registration] = m3bJobPurchase();

    $outcome = app(GuestReportFulfillmentService::class)
        ->reserveAndDispatch($purchase, 'normal');

    expect($outcome->isEnqueued())->toBeTrue();

    return [m3bPushedJob(), $purchase, $registration];
}

test('a Slice 2A job carries its dispatch context and claims exactly once', function () {
    [$job, $purchase, $registration] = m3bReserveJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('payload-success-1');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->once()
        ->with($registration)
        ->andReturn([
            'normalized' => m3bJobNormalized(),
            'raw' => ['source' => 'm3b-job'],
        ]);

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job->handle($provider, $reportBuilder, $creditService);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_COMPLETED)
        ->and($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(1)
        ->and($purchase->fulfilled_at)->not->toBeNull()
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull();

    $rows = GuestReportFulfillmentAttempt::query()
        ->where('guest_report_purchase_id', $purchase->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->kind)->toBe(GuestReportFulfillmentAttempt::KIND_CLAIM)
        ->and($rows->first()->status)->toBe(GuestReportFulfillmentAttempt::STATUS_COMPLETED)
        ->and($rows->first()->completed_at)->not->toBeNull()
        ->and($rows->first()->queue_job_id)->toBe('payload-success-1');
});

test('a guest job with an invalid dispatch context is rejected before any provider call', function () {
    [$purchase, $vinCheck, $report, $registration] = m3bJobPurchase();

    $job = new ProcessVehicleCheck(
        $vinCheck->id,
        $report->id,
        $purchase->id,
        (string) Str::uuid(),
        1,
        'normal',
    );

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('payload-invalid-1');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldNotReceive('build');

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job->handle($provider, $reportBuilder, $creditService);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING)
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and($purchase->fulfillment_generation_attempts)->toBe(0)
        ->and($vinCheck->fresh()->check_type)->toBe('free');

    expect(GuestReportFulfillmentAttempt::query()
        ->where('guest_report_purchase_id', $purchase->id)
        ->count())->toBe(0);
});

test('a retryable failure releases the claim and the same payload acquires a new one', function () {
    [$job, $purchase, $registration] = m3bReserveJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('payload-retry-1');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $buildAttempts = 0;

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->twice()
        ->with($registration)
        ->andReturnUsing(function () use (&$buildAttempts): array {
            $buildAttempts++;

            if ($buildAttempts === 1) {
                throw new RuntimeException('Simulated first attempt failure');
            }

            return [
                'normalized' => m3bJobNormalized(),
                'raw' => ['source' => 'm3b-retry'],
            ];
        });

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    expect(fn () => $job->handle($provider, $reportBuilder, $creditService))
        ->toThrow(RuntimeException::class, 'Simulated first attempt failure');

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull()
        ->and($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(1);

    $released = GuestReportFulfillmentAttempt::query()
        ->where('guest_report_purchase_id', $purchase->id)
        ->where('status', GuestReportFulfillmentAttempt::STATUS_RELEASED)
        ->first();

    expect($released)->not->toBeNull()
        ->and($released->released_at)->not->toBeNull()
        ->and($released->failure_reason)->toContain('Simulated first attempt failure');

    $job->handle($provider, $reportBuilder, $creditService);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_COMPLETED)
        ->and($purchase->fulfillment_attempts)->toBe(2)
        ->and($purchase->fulfillment_generation_attempts)->toBe(2)
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($buildAttempts)->toBe(2);

    $rows = GuestReportFulfillmentAttempt::query()
        ->where('guest_report_purchase_id', $purchase->id)
        ->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->where('status', GuestReportFulfillmentAttempt::STATUS_RELEASED))->toHaveCount(1)
        ->and($rows->where('status', GuestReportFulfillmentAttempt::STATUS_COMPLETED))->toHaveCount(1);
});

test('a different queue payload is rejected while another worker holds the claim', function () {
    [$job, $purchase, $registration] = m3bReserveJob();

    $purchase->refresh();

    $service = app(GuestReportFulfillmentService::class);

    $claim = $service->claim(
        purchaseId: (int) $purchase->id,
        reportId: (int) $purchase->report_id,
        dispatchUuid: (string) $purchase->fulfillment_dispatch_uuid,
        generation: (int) $purchase->fulfillment_generation,
        origin: 'normal',
        queueJobUuid: 'payload-worker-a',
    );

    expect($claim['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('payload-worker-b');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldNotReceive('build');

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job->handle($provider, $reportBuilder, $creditService);

    $purchase->refresh();

    expect($purchase->fulfillment_claim_uuid)->toBe($claim['claim_uuid'])
        ->and($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(1);

    expect(GuestReportFulfillmentAttempt::query()
        ->where('guest_report_purchase_id', $purchase->id)
        ->count())->toBe(1);
});

test('a retryable failure releases the claim and preserves the original exception', function () {
    [$job, $purchase, $registration] = m3bReserveJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('payload-release-1');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->once()
        ->with($registration)
        ->andThrow(new RuntimeException('Transient provider outage'));

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    expect(fn () => $job->handle($provider, $reportBuilder, $creditService))
        ->toThrow(RuntimeException::class, 'Transient provider outage');

    $purchase->refresh();

    $row = GuestReportFulfillmentAttempt::query()
        ->where('guest_report_purchase_id', $purchase->id)
        ->where('status', GuestReportFulfillmentAttempt::STATUS_RELEASED)
        ->first();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull()
        ->and($row)->not->toBeNull()
        ->and($row->released_at)->not->toBeNull()
        ->and($row->failure_reason)->toContain('Transient provider outage');
});

test('a terminal attempt waits for failed() and records a fenced terminal failure', function () {
    [$job, $purchase, $registration] = m3bReserveJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('payload-terminal-1');
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->once()
        ->with($registration)
        ->andThrow(new RuntimeException('Provider permanently failed'));

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    expect(fn () => $job->handle($provider, $reportBuilder, $creditService))
        ->toThrow(RuntimeException::class, 'Provider permanently failed');

    $purchase->refresh();

    // Laravel has not declared the job terminal yet: the claim is still held.
    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_claim_uuid)->not->toBeNull();

    $claimUuid = $purchase->fulfillment_claim_uuid;
    $report = $purchase->report()->firstOrFail();

    // Laravel re-instantiates the job from the serialized payload for failed().
    $fresh = (new ProcessVehicleCheck(
        (int) $report->vin_check_id,
        (int) $report->id,
        (int) $purchase->id,
    ))->withGuestFulfillmentContext(
        (string) $purchase->fulfillment_dispatch_uuid,
        (int) $purchase->fulfillment_generation,
        GuestReportFulfillmentAttempt::ORIGIN_NORMAL,
    );

    $fresh->setJob($queueJob);
    $fresh->failed(new RuntimeException('Provider permanently failed'));

    $purchase->refresh();

    $failedRow = GuestReportFulfillmentAttempt::query()
        ->where('guest_report_purchase_id', $purchase->id)
        ->where('status', GuestReportFulfillmentAttempt::STATUS_FAILED)
        ->first();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_FAILED)
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and($purchase->fulfillment_claim_expires_at)->toBeNull()
        ->and($failedRow)->not->toBeNull()
        ->and($failedRow->claim_uuid)->toBe($claimUuid)
        ->and($failedRow->failed_at)->not->toBeNull()
        ->and($failedRow->failure_reason)->toContain('Provider permanently failed');
});

test('a stale terminal failure does not overwrite a newer claim', function () {
    [$job, $purchase, $registration] = m3bReserveJob();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('payload-stale-terminal');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->once()
        ->with($registration)
        ->andThrow(new RuntimeException('Retryable failure'));

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    expect(fn () => $job->handle($provider, $reportBuilder, $creditService))
        ->toThrow(RuntimeException::class, 'Retryable failure');

    $purchase->refresh();

    // Another worker acquires a new claim while the old payload is stale.
    $purchase->fulfillment_claim_uuid === null
        ? null
        : throw new RuntimeException('Expected the old claim to be released.');

    $newClaim = app(GuestReportFulfillmentService::class)->claim(
        purchaseId: (int) $purchase->id,
        reportId: (int) $purchase->report_id,
        dispatchUuid: (string) $purchase->fulfillment_dispatch_uuid,
        generation: (int) $purchase->fulfillment_generation,
        origin: 'normal',
        queueJobUuid: 'payload-new-owner',
    );

    expect($newClaim['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED);

    $report = $purchase->report()->firstOrFail();

    $stale = (new ProcessVehicleCheck(
        (int) $report->vin_check_id,
        (int) $report->id,
        (int) $purchase->id,
    ))->withGuestFulfillmentContext(
        (string) $purchase->fulfillment_dispatch_uuid,
        (int) $purchase->fulfillment_generation,
        GuestReportFulfillmentAttempt::ORIGIN_NORMAL,
    );

    $stale->setJob($queueJob);
    $stale->failed(new RuntimeException('Late terminal callback'));

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_claim_uuid)->toBe($newClaim['claim_uuid'])
        ->and(GuestReportFulfillmentAttempt::query()
            ->where('guest_report_purchase_id', $purchase->id)
            ->where('claim_uuid', $newClaim['claim_uuid'])
            ->firstOrFail()
            ->status)->toBe(GuestReportFulfillmentAttempt::STATUS_ACTIVE);
});

test('a stale completion does not overwrite a newer claim or mark the vin check complete', function () {
    [$job, $purchase, $registration] = m3bReserveJob();

    $purchase->refresh();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('payload-stale-completion');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->once()
        ->with($registration)
        ->andReturnUsing(function () use ($purchase): array {
            DB::table('guest_report_purchases')
                ->where('id', $purchase->id)
                ->update(['fulfillment_claim_expires_at' => now()->subMinute()]);

            $newClaim = app(GuestReportFulfillmentService::class)->claim(
                purchaseId: (int) $purchase->id,
                reportId: (int) $purchase->report_id,
                dispatchUuid: (string) $purchase->fulfillment_dispatch_uuid,
                generation: (int) $purchase->fulfillment_generation,
                origin: 'normal',
                queueJobUuid: 'payload-new-completion-owner',
            );

            expect($newClaim['status'])->toBe(GuestReportFulfillmentService::CLAIM_STATUS_CLAIMED);

            return [
                'normalized' => m3bJobNormalized(),
                'raw' => ['source' => 'm3b-stale-completion'],
            ];
        });

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job->handle($provider, $reportBuilder, $creditService);

    $purchase->refresh();
    $vinCheck = $purchase->report()->firstOrFail()->vinCheck;

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING)
        ->and($purchase->fulfillment_claim_uuid)->not->toBeNull()
        ->and($vinCheck->status)->not->toBe('success')
        ->and($vinCheck->stage)->not->toBe('completed');

    Queue::assertNotPushed(SendGuestReportAccessEmail::class);
});

test('a legacy guest job without dispatch context keeps the pre-Slice-2B path', function () {
    [$purchase, $vinCheck, $report, $registration] = m3bJobPurchase();

    $job = new ProcessVehicleCheck(
        (int) $vinCheck->id,
        (int) $report->id,
        (int) $purchase->id,
    );

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('legacy-payload');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->once()
        ->with($registration)
        ->andReturn([
            'normalized' => m3bJobNormalized(),
            'raw' => ['source' => 'legacy'],
        ]);

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job->handle($provider, $reportBuilder, $creditService);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_COMPLETED)
        ->and($purchase->fulfillment_attempts)->toBe(1)
        ->and($purchase->fulfillment_generation_attempts)->toBe(0)
        ->and($purchase->fulfillment_claim_uuid)->toBeNull()
        ->and(GuestReportFulfillmentAttempt::query()
            ->where('guest_report_purchase_id', $purchase->id)
            ->count())->toBe(0)
        ->and($vinCheck->fresh()->status)->toBe('success');
});

test('a legacy guest job still marks the purchase failed on its terminal attempt', function () {
    [$purchase, $vinCheck, $report, $registration] = m3bJobPurchase();

    $job = new ProcessVehicleCheck(
        (int) $vinCheck->id,
        (int) $report->id,
        (int) $purchase->id,
    );

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('legacy-terminal-payload');
    $queueJob->shouldReceive('attempts')->andReturn(3);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->once()
        ->with($registration)
        ->andThrow(new RuntimeException('Legacy terminal failure'));

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    expect(fn () => $job->handle($provider, $reportBuilder, $creditService))
        ->toThrow(RuntimeException::class, 'Legacy terminal failure');

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_FAILED)
        ->and(GuestReportFulfillmentAttempt::query()
            ->where('guest_report_purchase_id', $purchase->id)
            ->count())->toBe(0);
});

test('a non-guest premium job is unchanged by the guest lifecycle', function () {
    $registration = 'M3CNON'.Str::upper(Str::random(6));

    $vinCheck = VinCheck::create([
        'registration_number' => $registration,
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    $job = new ProcessVehicleCheck((int) $vinCheck->id);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('uuid')->andReturn('non-guest-payload');
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $job->setJob($queueJob);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')
        ->once()
        ->with($registration)
        ->andReturn([
            'normalized' => m3bJobNormalized(),
            'raw' => ['source' => 'non-guest'],
        ]);

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job->handle($provider, $reportBuilder, $creditService);

    expect($vinCheck->fresh()->status)->toBe('success')
        ->and($vinCheck->fresh()->stage)->toBe('completed')
        ->and(GuestReportPurchase::query()->count())->toBe(0);
});
