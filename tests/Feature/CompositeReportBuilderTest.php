<?php

use App\Services\Reports\CompositeReportBuilder;
use App\Services\Reports\NormalizedReportBuilder;

function compositeBuilderTestResult(
    string $provider,
    string $vrm,
    string $vin = 'VIN-TEST-001',
    string $make = 'FORD',
): array {
    $normalized = [
        'meta' => [],
        'vehicle' => [
            'vrm' => $vrm,
            'vin' => $vin,
            'make' => $make,
            'model' => 'TEST MODEL',
        ],
        'status' => [
            'scrapped' => false,
        ],
        'v5c' => [
            'count' => 1,
            'history' => [],
        ],
        'history' => [
            'finance' => [
                'count' => $provider === 'oneauto' ? 3 : 1,
                'records' => [],
            ],
            'stolen' => ['count' => 0, 'records' => []],
            'write_off' => ['count' => 0, 'records' => []],
            'high_risk' => ['count' => 0, 'records' => []],
            'keepers' => ['count' => 0, 'records' => []],
            'plate_changes' => ['count' => 0, 'records' => []],
            'colour_changes' => ['count' => 0, 'records' => []],
        ],
        'salvage' => ['records' => []],
        'specifications' => [
            'power_bhp' => $provider === 'checkcardetails' ? 150 : 120,
        ],
        'dimensions' => ['height_mm' => 1000],
        'valuation' => ['retail' => 15000],
        'recalls' => ['records' => []],
        'emissions_compliance' => [
            'meets_minimum_standard' => true,
        ],
        'running_costs' => [
            'road_tax' => ['amount' => 200],
            'insurance' => ['amount' => 500],
            'fuel' => ['combined_mpg' => 40],
        ],
        'mot' => ['tests' => []],
    ];

    return [
        'provider' => $provider,
        'vrm' => $vrm,
        'meta' => [],
        'normalized' => $normalized,
        'raw' => ['sample_from' => $provider],
    ];
}

test('composite builder combines data from both providers for the same vehicle', function () {
    $vrm = 'EA65AMX';

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn(
            compositeBuilderTestResult('checkcardetails', $vrm)
        );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andReturn(
            compositeBuilderTestResult('oneauto', $vrm)
        );

    $result = (new CompositeReportBuilder($reportBuilder))
        ->build($vrm);

    expect($result['provider'])->toBe('hybrid')
        ->and($result['meta']['identity_verified'])->toBeTrue()
        ->and($result['meta']['providers'])
            ->toBe(['checkcardetails', 'oneauto'])
        ->and($result['normalized']['history']['finance']['count'])
            ->toBe(3)
        ->and($result['normalized']['specifications']['power_bhp'])
            ->toBe(150);
});

test('composite builder rejects data from a different vehicle', function () {
    $vrm = 'EA65AMX';

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn(
            compositeBuilderTestResult('checkcardetails', $vrm)
        );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andReturn(
            compositeBuilderTestResult('oneauto', 'AB21ABC')
        );

    expect(
        fn () => (new CompositeReportBuilder($reportBuilder))->build($vrm)
    )->toThrow(
        RuntimeException::class,
        'Provider vehicle mismatch'
    );
});

test('composite builder falls back when one provider fails', function () {
    $vrm = 'EA65AMX';

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn(
            compositeBuilderTestResult(
                'checkcardetails',
                $vrm
            )
        );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andThrow(
            new RuntimeException(
                'OneAuto provider unavailable'
            )
        );

    $result = (new CompositeReportBuilder($reportBuilder))
        ->build($vrm);

    expect($result['provider'])->toBe('hybrid')
        ->and($result['normalized']['vehicle']['vrm'])
            ->toBe($vrm)
        ->and($result['normalized']['meta']['strategy'])
            ->toBe('hybrid_fallback')
        ->and($result['normalized']['meta']['providers'])
            ->toBe(['checkcardetails'])
        ->and($result['normalized']['meta']['identity_verified'])
            ->toBeTrue();
});

test('composite builder uses fallback when the preferred section is unavailable', function () {
    $vrm = 'EA65AMX';

    $ccd = compositeBuilderTestResult(
        'checkcardetails',
        $vrm
    );

    $oneAuto = compositeBuilderTestResult(
        'oneauto',
        $vrm
    );

    // CheckCarDetails punya data salvage yang valid.
    $ccd['normalized']['salvage'] = [
        'available' => true,
        'record_found' => true,
        'records' => [
            [
                'salvage_auction_record_id' => 'CCD-SALVAGE-001',
                'primary_damage_desc' => 'Front damage',
            ],
        ],
    ];

    // OneAuto secara eksplisit menyatakan bagian ini tidak tersedia.
    $oneAuto['normalized']['salvage'] = [
        'available' => false,
        'record_found' => null,
        'records' => [],
    ];

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn($ccd);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andReturn($oneAuto);

    $result = (new CompositeReportBuilder($reportBuilder))
        ->build($vrm);

    expect($result['normalized']['salvage']['available'])
        ->toBeTrue()
        ->and($result['normalized']['salvage']['records'][0]['salvage_auction_record_id'])
        ->toBe('CCD-SALVAGE-001')
        ->and($result['normalized']['meta']['sources']['salvage'])
        ->toBe('checkcardetails');
});

test('composite builder preserves valid empty results from the preferred provider', function () {
    $vrm = 'EA65AMX';

    $ccd = compositeBuilderTestResult(
        'checkcardetails',
        $vrm
    );

    $oneAuto = compositeBuilderTestResult(
        'oneauto',
        $vrm
    );

    // CCD memiliki catatan salvage.
    $ccd['normalized']['salvage'] = [
        'available' => true,
        'record_found' => true,
        'records' => [
            [
                'salvage_auction_record_id' => 'CCD-SALVAGE-001',
                'primary_damage_desc' => 'Front damage',
            ],
        ],
    ];

    // OneAuto berhasil memeriksa, tetapi tidak menemukan catatan.
    $oneAuto['normalized']['salvage'] = [
        'available' => true,
        'record_found' => false,
        'records' => [],
    ];

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn($ccd);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andReturn($oneAuto);

    $result = (new CompositeReportBuilder($reportBuilder))
        ->build($vrm);

    expect($result['normalized']['salvage']['available'])
        ->toBeTrue()
        ->and($result['normalized']['salvage']['record_found'])
        ->toBeFalse()
        ->and($result['normalized']['salvage']['records'])
        ->toBe([])
        ->and($result['normalized']['meta']['sources']['salvage'])
        ->toBe('oneauto');
});

test('composite builder rejects different VIN values for the same VRM', function () {
    $vrm = 'EA65AMX';

    $ccd = compositeBuilderTestResult(
        'checkcardetails',
        $vrm
    );

    $oneAuto = compositeBuilderTestResult(
        'oneauto',
        $vrm
    );

    // VRM sama, tetapi identitas VIN berbeda.
    $oneAuto['normalized']['vehicle']['vin'] =
        'VIN-DIFFERENT-999';

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn($ccd);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andReturn($oneAuto);

    expect(
        fn () => (new CompositeReportBuilder($reportBuilder))
            ->build($vrm)
    )->toThrow(
        RuntimeException::class,
        'Provider VIN mismatch'
    );
});

test('composite builder fills missing fields from fallback provider and records field source', function () {
    $vrm = 'EA65AMX';

    $ccd = compositeBuilderTestResult(
        'checkcardetails',
        $vrm
    );

    $oneAuto = compositeBuilderTestResult(
        'oneauto',
        $vrm
    );

    // Bagian spesifikasi CCD tersedia, tetapi CO2 kosong.
    $ccd['normalized']['specifications'] = [
        'co2_gkm' => null,
        'power_kw' => 150,
    ];

    // OneAuto punya CO2, tetapi nilai power berbeda.
    $oneAuto['normalized']['specifications'] = [
        'co2_gkm' => 120,
        'power_kw' => 140,
    ];

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn($ccd);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andReturn($oneAuto);

    $result = (new CompositeReportBuilder($reportBuilder))
        ->build($vrm);

    expect($result['normalized']['specifications']['co2_gkm'])
        ->toBe(120)
        ->and($result['normalized']['specifications']['power_kw'])
        ->toBe(150)
        ->and($result['normalized']['meta']['sources']['specifications'])
        ->toBe('checkcardetails')
        ->and($result['normalized']['meta']['source_overrides']['specifications.co2_gkm'])
        ->toBe('oneauto');
});

test('composite builder marks section unavailable when both providers lack data', function () {
    $vrm = 'EA65AMX';

    $ccd = compositeBuilderTestResult(
        'checkcardetails',
        $vrm
    );

    $oneAuto = compositeBuilderTestResult(
        'oneauto',
        $vrm
    );

    $ccd['normalized']['salvage'] = [
        'available' => false,
        'record_found' => null,
        'records' => [],
    ];

    $oneAuto['normalized']['salvage'] = [
        'available' => false,
        'record_found' => null,
        'records' => [],
    ];

    $reportBuilder = Mockery::mock(
        NormalizedReportBuilder::class
    );

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn($ccd);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andReturn($oneAuto);

    $result = (new CompositeReportBuilder($reportBuilder))
        ->build($vrm);

    expect($result['normalized']['salvage']['available'])
        ->toBeFalse()
        ->and($result['normalized']['salvage']['record_found'])
        ->toBeNull()
        ->and($result['normalized']['salvage']['records'])
        ->toBe([])
        ->and($result['normalized']['meta']['sources']['salvage'])
        ->toBeNull();
});

test('composite builder preserves zero and false values from preferred provider', function () {
    $vrm = 'EA65AMX';

    $ccd = compositeBuilderTestResult('checkcardetails', $vrm);
    $oneAuto = compositeBuilderTestResult('oneauto', $vrm);

    $ccd['normalized']['specifications'] = [
        'co2_gkm' => 0,
        'is_electric' => false,
    ];

    $oneAuto['normalized']['specifications'] = [
        'co2_gkm' => 120,
        'is_electric' => true,
    ];

    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'checkcardetails')
        ->andReturn($ccd);

    $reportBuilder
        ->shouldReceive('build')
        ->once()
        ->with($vrm, 'oneauto')
        ->andReturn($oneAuto);

    $result = (new CompositeReportBuilder($reportBuilder))->build($vrm);

    expect($result['normalized']['specifications']['co2_gkm'])
        ->toBe(0)
        ->and($result['normalized']['specifications']['is_electric'])
        ->toBeFalse()
        ->and($result['normalized']['meta']['source_overrides']['specifications.co2_gkm'] ?? null)
        ->toBeNull()
        ->and($result['normalized']['meta']['source_overrides']['specifications.is_electric'] ?? null)
        ->toBeNull();
});