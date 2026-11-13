<?php

use App\Contracts\VehicleDataProviderInterface;
use App\Jobs\ProcessVehicleCheck;
use App\Models\CreditTransaction;
use App\Models\Report;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\CreditService;
use App\Services\Reports\NormalizedReportBuilder;
use Illuminate\Support\Str;

/**
 * Regression tests proving that a retried or redispatched ProcessVehicleCheck
 * never deducts a second credit for the same logical premium vehicle check.
 */
beforeEach(function () {
    config([
        'services.vehicle_reports.strategy' => 'single',
    ]);
});

/**
 * Normalized premium report payload used by the mocked report builder.
 */
function creditIdempotencyNormalizedReport(): array
{
    return [
        'vehicle' => [
            'vin' => 'VIN-IDEMPOTENCY-001',
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

/**
 * Mocked provider: the premium path never calls the basic provider.
 */
function creditIdempotencyProvider(?array $basicPayload = null): VehicleDataProviderInterface
{
    $provider = Mockery::mock(VehicleDataProviderInterface::class);
    $provider->shouldReceive('getRegistrationDetails')->andReturn($basicPayload ?? []);

    return $provider;
}

/**
 * Mocked premium report builder.
 */
function creditIdempotencyReportBuilder(): NormalizedReportBuilder
{
    $builder = Mockery::mock(NormalizedReportBuilder::class);
    $builder->shouldReceive('build')->andReturn([
        'normalized' => creditIdempotencyNormalizedReport(),
        'raw' => ['source' => 'idempotency-test'],
    ]);

    return $builder;
}

/**
 * Run the job synchronously with the real credit service.
 */
function creditIdempotencyRunJob(ProcessVehicleCheck $job, ?array $basicPayload = null): void
{
    $job->handle(
        creditIdempotencyProvider($basicPayload),
        creditIdempotencyReportBuilder(),
        app(CreditService::class),
    );
}

/**
 * Create an authenticated premium vehicle check with an existing basic report.
 *
 * @return array{0: User, 1: VinCheck, 2: Report}
 */
function creditIdempotencyExistingReport(User $user, string $registration): array
{
    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $registration,
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    $report = Report::create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['level' => 'basic'],
    ]);

    return [$user, $vinCheck, $report];
}

test('8. an existing report job executed twice charges only once', function () {
    $user = User::factory()->create(['credits' => 3]);
    [$user, $vinCheck, $report] = creditIdempotencyExistingReport(
        $user,
        'IDEM-JOB-EXISTING-'.Str::upper(Str::random(6))
    );

    creditIdempotencyRunJob(new ProcessVehicleCheck($vinCheck->id, $report->id));

    expect($user->fresh()->credits)->toBe(2)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1)
        ->and($report->fresh()->report_type)->toBe('premium');

    // The identical payload is executed again, as a queue retry or a
    // redispatch of the same job would do.
    creditIdempotencyRunJob(new ProcessVehicleCheck($vinCheck->id, $report->id));

    expect($user->fresh()->credits)->toBe(2)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1)
        ->and(CreditTransaction::query()->where('type', 'usage')->first()->reference_id)
        ->toBe((string) $report->id)
        ->and(CreditTransaction::query()->where('type', 'usage')->first()->idempotency_key)
        ->toBe('usage:vin_check:'.$vinCheck->id);
});

test('9. a fresh report job replay charges only once', function () {
    $user = User::factory()->create(['credits' => 3]);

    $registration = 'IDEM-JOB-FRESH-'.Str::upper(Str::random(6));

    Vehicle::create(['registration_number' => $registration]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $registration,
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    creditIdempotencyRunJob(new ProcessVehicleCheck($vinCheck->id));

    expect($user->fresh()->credits)->toBe(2)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);

    // Replay of the same payload: the report is regenerated, but the credit is
    // not deducted a second time.
    creditIdempotencyRunJob(new ProcessVehicleCheck($vinCheck->id));

    expect($user->fresh()->credits)->toBe(2)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1)
        ->and(CreditTransaction::query()->where('type', 'usage')->first()->idempotency_key)
        ->toBe('usage:vin_check:'.$vinCheck->id);
});

test('9b. two different vehicle checks each charge their own credit', function () {
    $user = User::factory()->create(['credits' => 5]);

    [$firstUser, $firstVinCheck, $firstReport] = creditIdempotencyExistingReport(
        $user,
        'IDEM-JOB-DIFF-1-'.Str::upper(Str::random(6))
    );

    [$secondUser, $secondVinCheck, $secondReport] = creditIdempotencyExistingReport(
        $user,
        'IDEM-JOB-DIFF-2-'.Str::upper(Str::random(6))
    );

    creditIdempotencyRunJob(new ProcessVehicleCheck($firstVinCheck->id, $firstReport->id));
    creditIdempotencyRunJob(new ProcessVehicleCheck($secondVinCheck->id, $secondReport->id));

    expect($user->fresh()->credits)->toBe(3)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(2);
});

test('9c. a job replay for an already charged vehicle check does not fail on an empty balance', function () {
    $user = User::factory()->create(['credits' => 1]);
    [$user, $vinCheck, $report] = creditIdempotencyExistingReport(
        $user,
        'IDEM-JOB-ZERO-'.Str::upper(Str::random(6))
    );

    creditIdempotencyRunJob(new ProcessVehicleCheck($vinCheck->id, $report->id));

    expect($user->fresh()->credits)->toBe(0);

    creditIdempotencyRunJob(new ProcessVehicleCheck($vinCheck->id, $report->id));

    expect($user->fresh()->credits)->toBe(0)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});

test('9d. a free vehicle check never consumes a credit', function () {
    config([
        'services.vehicle_reports.provider' => 'checkcardetails',
    ]);

    $user = User::factory()->create(['credits' => 3]);

    $registration = 'IDEM-JOB-FREE-'.Str::upper(Str::random(6));

    Vehicle::create(['registration_number' => $registration]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $registration,
        'check_type' => 'free',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    creditIdempotencyRunJob(new ProcessVehicleCheck($vinCheck->id), [
        'vin' => 'VIN-IDEMPOTENCY-FREE',
        'make' => 'Ford',
        'model' => 'Focus',
        'yearOfManufacture' => 2020,
    ]);

    expect($user->fresh()->credits)->toBe(3)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(0);
});
