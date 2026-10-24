<?php

use App\Contracts\VehicleDataProviderInterface;
use App\Jobs\ProcessVehicleCheck;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\CreditService;
use App\Services\Reports\CompositeReportBuilder;
use App\Services\Reports\NormalizedReportBuilder;
use App\Services\VehicleProviders\OneAutoProvider;

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

test('basic vehicle check uses OneAuto when configured', function () {
    config([
        'services.vehicle_reports.provider' => 'oneauto',
        'services.vehicle_reports.strategy' => 'single',
    ]);

    $vrm = 'AB21ABC';

    $vinCheck = VinCheck::create([
        'registration_number' => $vrm,
        'check_type' => 'free',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    $provider = Mockery::mock(
        VehicleDataProviderInterface::class
    );

    // Basic report tidak boleh mengambil data dari CheckCarDetails.
    $provider->shouldNotReceive('getRegistrationDetails');

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    // Basic report tidak boleh memanggil builder premium.
    $reportBuilder->shouldNotReceive('build');

    $oneAuto = Mockery::mock(OneAutoProvider::class);

    $oneAuto->shouldReceive('getVehicleDetails')
        ->once()
        ->with($vrm)
        ->andReturn([
            'success' => true,
            'result' => [
                'vehicle_identification' => [
                    'vehicle_registration_mark' => $vrm,
                    'vehicle_identification_number' => 'ABCDE123456F78910',
                    'dvla_manufacturer_desc' => 'NISSAN',
                    'dvla_model_desc' => 'QASHQAI ACENTA PREMIUM DCI',
                    'manufactured_year' => 2020,
                    'dvla_fuel_desc' => 'DIESEL',
                ],
                'vehicle_status_details' => [],
                'vehicle_excise_duty_details' => [
                    'co2_gkm' => 120,
                ],
                'colour_details' => [
                    'colour' => 'BLACK',
                ],
            ],
        ]);

    $oneAuto->shouldReceive('getMotHistory')
        ->once()
        ->with($vrm)
        ->andReturn([
            'success' => true,
            'result' => [
                'dvla_data' => [
                    'tax_status' => 'Taxed',
                    'tax_expiry_date' => '2027-01-01',
                ],
                'dvsa_data' => [
                    'dvsa_vehicle_Data' => [
                        'vehicle_registration_mark' => $vrm,
                    ],
                    'mot_tests' => [
                        [
                            'mot_test_date' => '2026-01-01',
                            'mot_expiry_date' => '2027-01-01',
                            'mot_test_result' => 'PASSED',
                        ],
                    ],
                ],
            ],
        ]);

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job = new ProcessVehicleCheck($vinCheck->id);

    $job->handle(
        $provider,
        $reportBuilder,
        $creditService,
        null,
        $oneAuto,
    );

    $report = Report::query()
        ->where('vin_check_id', $vinCheck->id)
        ->firstOrFail();

    expect($report->report_type)
        ->toBe('basic')
        ->and(data_get($report->report_data, 'registrationNumber'))
        ->toBe($vrm)
        ->and(data_get($report->report_data, 'make'))
        ->toBe('NISSAN')
        ->and(data_get($report->report_data, 'model'))
        ->toBe('QASHQAI ACENTA PREMIUM DCI')
        ->and($vinCheck->fresh()->status)
        ->toBe('success');
});

test('basic OneAuto check rejects a mismatched vehicle without saving a report', function () {
    config([
        'services.vehicle_reports.provider' => 'oneauto',
        'services.vehicle_reports.strategy' => 'single',
    ]);

    $vrm = 'EA65AMX';

    $vinCheck = VinCheck::create([
        'registration_number' => $vrm,
        'check_type' => 'free',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    $provider = Mockery::mock(
        VehicleDataProviderInterface::class
    );

    $provider->shouldNotReceive('getRegistrationDetails');

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    $reportBuilder->shouldNotReceive('build');

    $oneAuto = Mockery::mock(OneAutoProvider::class);

    // Sandbox mengembalikan kendaraan berbeda dari yang diminta.
    $oneAuto->shouldReceive('getVehicleDetails')
        ->once()
        ->with($vrm)
        ->andReturn([
            'success' => true,
            'result' => [
                'vehicle_identification' => [
                    'vehicle_registration_mark' => 'AB21ABC',
                    'vehicle_identification_number' => 'ABCDE123456F78910',
                    'dvla_manufacturer_desc' => 'NISSAN',
                    'dvla_model_desc' => 'QASHQAI ACENTA PREMIUM DCI',
                ],
            ],
        ]);

    // Identitas sudah gagal diverifikasi, jadi MOT tidak boleh diminta.
    $oneAuto->shouldNotReceive('getMotHistory');

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job = new ProcessVehicleCheck($vinCheck->id);

    expect(fn () => $job->handle(
        $provider,
        $reportBuilder,
        $creditService,
        null,
        $oneAuto,
    ))->toThrow(
        RuntimeException::class,
        'OneAuto vehicle mismatch'
    );

    expect($vinCheck->fresh()->status)
        ->toBe('failed')
        ->and($vinCheck->fresh()->stage)
        ->toBe('failed')
        ->and(
            Report::query()
                ->where('vin_check_id', $vinCheck->id)
                ->exists()
        )
        ->toBeFalse();
});