<?php

use App\Contracts\VehicleDataProviderInterface;
use App\Jobs\ProcessVehicleCheck;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\CreditService;
use App\Services\Reports\NormalizedReportBuilder;
use Illuminate\Contracts\Queue\Job as QueueJob;

test('paid guest purchase upgrades its existing report to premium', function () {
    $registrationNumber = 'GUEST-FULFILLMENT-001';

    $vehicle = Vehicle::create([
        'registration_number' => $registrationNumber,
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => $registrationNumber,
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
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'provider_order_id' => 'cs_test_fulfillment_001',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);

    $normalized = [
        'vehicle' => [
            'vin' => 'VIN-GUEST-001',
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

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($registrationNumber)
        ->andReturn([
            'normalized' => $normalized,
            'raw' => ['source' => 'test'],
        ]);

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job = new ProcessVehicleCheck(
        $vinCheck->id,
        $report->id,
        $purchase->id,
    );

    $job->handle($provider, $reportBuilder, $creditService);

    expect($report->fresh()->report_type)->toBe('premium');

    expect($report->fresh()->report_data)
        ->toBe($normalized);

    expect($report->fresh()->vin_check_id)
        ->toBe($vinCheck->id);

    expect($vinCheck->fresh()->check_type)
        ->toBe('premium');

    expect($vinCheck->fresh()->status)
        ->toBe('success');

    expect($vinCheck->fresh()->stage)
        ->toBe('completed');

    expect($purchase->fresh()->payment_status)
        ->toBe(GuestReportPurchase::STATUS_PAID);

    expect($purchase->fresh()->fulfillment_status)
        ->toBe(GuestReportPurchase::FULFILLMENT_COMPLETED);

    expect($purchase->fresh()->fulfillment_attempts)
        ->toBe(1);

    expect($purchase->fresh()->fulfilled_at)
        ->not->toBeNull();
});

test('completed guest fulfillment does not process the report again', function () {
    $registrationNumber = 'GUEST-FULFILLMENT-002';

    $vehicle = Vehicle::create([
        'registration_number' => $registrationNumber,
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => $registrationNumber,
        'check_type' => 'premium',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'premium',
        'report_data' => ['level' => 'premium'],
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'provider_order_id' => 'cs_test_fulfillment_002',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
        'fulfillment_attempts' => 1,
        'fulfilled_at' => now(),
    ]);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldNotReceive('build');

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job = new ProcessVehicleCheck(
        $vinCheck->id,
        $report->id,
        $purchase->id,
    );

    $job->handle($provider, $reportBuilder, $creditService);

    expect($report->fresh()->report_type)->toBe('premium');

    expect($purchase->fresh()->fulfillment_status)
        ->toBe(GuestReportPurchase::FULFILLMENT_COMPLETED);

    expect($purchase->fresh()->fulfillment_attempts)->toBe(1);
});

test('terminal guest fulfillment failure is rethrown after marking purchase failed', function () {
    $registrationNumber = 'GUEST-FULFILLMENT-FAIL-001';

    $vehicle = Vehicle::create([
        'registration_number' => $registrationNumber,
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => $registrationNumber,
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'provider_order_id' => 'cs_test_fulfillment_fail_001',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($registrationNumber)
        ->andThrow(new RuntimeException('Simulated provider failure'));

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job = new ProcessVehicleCheck($vinCheck->id, $report->id, $purchase->id);

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(3);

    $job->setJob($queueJob);

    expect(fn () => $job->handle(
        $provider,
        $reportBuilder,
        $creditService,
    ))->toThrow(
        RuntimeException::class,
        'Simulated provider failure',
    );

    expect($purchase->fresh()->fulfillment_status)
        ->toBe(GuestReportPurchase::FULFILLMENT_FAILED);

    expect($vinCheck->fresh()->status)->toBe('failed');
});
