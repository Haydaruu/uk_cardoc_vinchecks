<?php

use App\Contracts\VehicleDataProviderInterface;
use App\Jobs\ProcessVehicleCheck;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\CreditService;
use App\Services\Reports\CompositeReportBuilder;
use App\Services\Reports\NormalizedReportBuilder;

test('premium vehicle check uses composite builder in hybrid mode', function () {
    config([
        'services.vehicle_reports.strategy' => 'hybrid',
    ]);

    $registrationNumber = 'HYBRID-STRATEGY-001';

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
        'report_type' => 'basic',
        'report_data' => ['level' => 'basic'],
    ]);

    $normalized = [
        'vehicle' => [
            'vin' => 'VIN-HYBRID-001',
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

    $provider = Mockery::mock(
        VehicleDataProviderInterface::class
    );

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    // Mode hybrid tidak boleh memakai builder single.
    $reportBuilder->shouldNotReceive('build');

    $compositeBuilder = Mockery::mock(
        CompositeReportBuilder::class
    );

    $compositeBuilder
        ->shouldReceive('build')
        ->once()
        ->with($registrationNumber)
        ->andReturn([
            'normalized' => $normalized,
            'raw' => [
                'source' => 'composite-test',
            ],
        ]);

    $creditService = Mockery::mock(
        CreditService::class
    );

    $creditService->shouldNotReceive('consume');

    $job = new ProcessVehicleCheck(
        $vinCheck->id,
        $report->id,
    );

    $job->handle(
        $provider,
        $reportBuilder,
        $creditService,
        $compositeBuilder,
    );

    expect($report->fresh()->report_type)
        ->toBe('premium')
        ->and($report->fresh()->report_data)
        ->toBe($normalized)
        ->and($vinCheck->fresh()->status)
        ->toBe('success')
        ->and($vinCheck->fresh()->stage)
        ->toBe('completed');
});