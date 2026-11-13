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
 * Regression tests proving the "one VinCheck = one Report" invariant.
 *
 * A fresh ProcessVehicleCheck execution must not create a second report for
 * the same vehicle check when the job is replayed, and the report id must
 * stay stable across retries.
 */
beforeEach(function () {
    config([
        'services.vehicle_reports.strategy' => 'single',
    ]);
});

/**
 * Normalized premium report payload used by the mocked report builder.
 */
function reportReuseNormalizedReport(string $vin): array
{
    return [
        'vehicle' => [
            'vin' => $vin,
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
function reportReuseProvider(): VehicleDataProviderInterface
{
    $provider = Mockery::mock(VehicleDataProviderInterface::class);
    $provider->shouldReceive('getRegistrationDetails')->andReturn([]);

    return $provider;
}

/**
 * Mocked premium report builder.
 */
function reportReuseReportBuilder(string $vin): NormalizedReportBuilder
{
    $builder = Mockery::mock(NormalizedReportBuilder::class);
    $builder->shouldReceive('build')->andReturn([
        'normalized' => reportReuseNormalizedReport($vin),
        'raw' => ['source' => 'report-reuse-test'],
    ]);

    return $builder;
}

/**
 * Run the vehicle check job synchronously with the real credit service.
 */
function reportReuseRunJob(ProcessVehicleCheck $job, string $vin): void
{
    $job->handle(
        reportReuseProvider(),
        reportReuseReportBuilder($vin),
        app(CreditService::class),
    );
}

/**
 * Create a premium vehicle check without any report (fresh job path).
 *
 * @return array{0: User, 1: VinCheck}
 */
function reportReuseFreshVehicleCheck(User $user, string $registration): array
{
    Vehicle::create(['registration_number' => $registration]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $registration,
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    return [$user, $vinCheck];
}

test('1. the first fresh job execution creates exactly one report', function () {
    $user = User::factory()->create(['credits' => 3]);

    $registration = 'REUSE-FIRST-'.Str::upper(Str::random(6));

    [$user, $vinCheck] = reportReuseFreshVehicleCheck($user, $registration);

    reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id), 'VIN-REUSE-FIRST');

    $reports = Report::query()->where('vin_check_id', $vinCheck->id)->get();

    expect($reports)->toHaveCount(1)
        ->and($reports->first()->report_type)->toBe('premium')
        ->and($reports->first()->user_id)->toBe($user->id)
        ->and($reports->first()->vehicle_id)->not->toBeNull()
        ->and($vinCheck->fresh()->status)->toBe('success')
        ->and($vinCheck->fresh()->stage)->toBe('completed');
});

test('2. replaying the same fresh job reuses the existing report and keeps its id stable', function () {
    $user = User::factory()->create(['credits' => 3]);

    $registration = 'REUSE-REPLAY-'.Str::upper(Str::random(6));

    [$user, $vinCheck] = reportReuseFreshVehicleCheck($user, $registration);

    reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id), 'VIN-REUSE-REPLAY');

    $firstReportId = Report::query()->where('vin_check_id', $vinCheck->id)->value('id');

    expect($firstReportId)->not->toBeNull();

    reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id), 'VIN-REUSE-REPLAY');

    $reports = Report::query()->where('vin_check_id', $vinCheck->id)->get();

    expect($reports)->toHaveCount(1)
        ->and($reports->first()->id)->toBe($firstReportId);
});

test('3. only one report exists for the same vin check after repeated executions', function () {
    $user = User::factory()->create(['credits' => 3]);

    $registration = 'REUSE-SINGLE-'.Str::upper(Str::random(6));

    [$user, $vinCheck] = reportReuseFreshVehicleCheck($user, $registration);

    foreach (range(1, 3) as $ignored) {
        reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id), 'VIN-REUSE-SINGLE');
    }

    expect(Report::query()->where('vin_check_id', $vinCheck->id)->count())->toBe(1)
        ->and(Report::query()->count())->toBe(1);
});

test('4. the credit balance is reduced only once when the fresh job is replayed', function () {
    $user = User::factory()->create(['credits' => 3]);

    $registration = 'REUSE-CREDIT-'.Str::upper(Str::random(6));

    [$user, $vinCheck] = reportReuseFreshVehicleCheck($user, $registration);

    reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id), 'VIN-REUSE-CREDIT');

    expect($user->fresh()->credits)->toBe(2);

    reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id), 'VIN-REUSE-CREDIT');

    expect($user->fresh()->credits)->toBe(2)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1)
        ->and(CreditTransaction::query()->where('type', 'usage')->first()->idempotency_key)
        ->toBe('usage:vin_check:'.$vinCheck->id);
});

test('5. different vin checks still create their own report', function () {
    $user = User::factory()->create(['credits' => 5]);

    [$firstUser, $firstVinCheck] = reportReuseFreshVehicleCheck(
        $user,
        'REUSE-DIFF-1-'.Str::upper(Str::random(6))
    );

    [$secondUser, $secondVinCheck] = reportReuseFreshVehicleCheck(
        $user,
        'REUSE-DIFF-2-'.Str::upper(Str::random(6))
    );

    reportReuseRunJob(new ProcessVehicleCheck($firstVinCheck->id), 'VIN-REUSE-DIFF-1');
    reportReuseRunJob(new ProcessVehicleCheck($secondVinCheck->id), 'VIN-REUSE-DIFF-2');

    $firstReport = Report::query()->where('vin_check_id', $firstVinCheck->id)->first();
    $secondReport = Report::query()->where('vin_check_id', $secondVinCheck->id)->first();

    expect($firstReport)->not->toBeNull()
        ->and($secondReport)->not->toBeNull()
        ->and($firstReport->id)->not->toBe($secondReport->id)
        ->and(Report::query()->count())->toBe(2)
        ->and($user->fresh()->credits)->toBe(3)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(2);
});

test('6. the existing report upgrade path is unchanged', function () {
    $user = User::factory()->create(['credits' => 3]);

    $registration = 'REUSE-UPGRADE-'.Str::upper(Str::random(6));

    $vehicle = Vehicle::create(['registration_number' => $registration]);

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

    reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id, $report->id), 'VIN-REUSE-UPGRADE');

    expect($report->fresh()->report_type)->toBe('premium')
        ->and($report->fresh()->id)->toBe($report->id)
        ->and(Report::query()->where('vin_check_id', $vinCheck->id)->count())->toBe(1)
        ->and($user->fresh()->credits)->toBe(2)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);

    // A replay of the upgrade keeps the same report and charges nothing more.
    reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id, $report->id), 'VIN-REUSE-UPGRADE');

    expect(Report::query()->where('vin_check_id', $vinCheck->id)->count())->toBe(1)
        ->and($report->fresh()->id)->toBe($report->id)
        ->and($user->fresh()->credits)->toBe(2)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});

test('7. a free vehicle check creates one report and a replay reuses it without charging', function () {
    config([
        'services.vehicle_reports.provider' => 'checkcardetails',
    ]);

    $user = User::factory()->create(['credits' => 3]);

    $registration = 'REUSE-FREE-'.Str::upper(Str::random(6));

    [$user, $vinCheck] = reportReuseFreshVehicleCheck($user, $registration);

    $vinCheck->update(['check_type' => 'free']);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);
    $provider->shouldReceive('getRegistrationDetails')->andReturn([
        'vin' => 'VIN-REUSE-FREE',
        'make' => 'Ford',
        'model' => 'Focus',
        'yearOfManufacture' => 2020,
    ]);

    $builder = Mockery::mock(NormalizedReportBuilder::class);

    (new ProcessVehicleCheck($vinCheck->id))->handle(
        $provider,
        $builder,
        app(CreditService::class),
    );

    $reportId = Report::query()->where('vin_check_id', $vinCheck->id)->value('id');

    expect($reportId)->not->toBeNull();

    (new ProcessVehicleCheck($vinCheck->id))->handle(
        $provider,
        $builder,
        app(CreditService::class),
    );

    expect(Report::query()->where('vin_check_id', $vinCheck->id)->count())->toBe(1)
        ->and(Report::query()->where('vin_check_id', $vinCheck->id)->value('id'))->toBe($reportId)
        ->and($user->fresh()->credits)->toBe(3)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(0);
});

test('8. a free report upgraded to premium later keeps a single report for the vin check', function () {
    $user = User::factory()->create(['credits' => 3]);

    $registration = 'REUSE-FREE-UPGRADE-'.Str::upper(Str::random(6));

    $vehicle = Vehicle::create(['registration_number' => $registration]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $registration,
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['level' => 'basic'],
    ]);

    // The unlock flow flips the vehicle check to premium and redispatches the
    // job with the existing report id.
    $vinCheck->update(['check_type' => 'premium', 'status' => 'pending', 'stage' => 'queued']);

    reportReuseRunJob(new ProcessVehicleCheck($vinCheck->id, $report->id), 'VIN-REUSE-FU');

    expect(Report::query()->where('vin_check_id', $vinCheck->id)->count())->toBe(1)
        ->and($report->fresh()->report_type)->toBe('premium')
        ->and($user->fresh()->credits)->toBe(2);
});
