<?php

use App\Contracts\VehicleDataProviderInterface;
use App\Exceptions\VehicleNotFoundException;
use App\Jobs\ProcessVehicleCheck;
use App\Jobs\SendTelegramGuestActivityNotification;
use App\Models\GuestLead;
use App\Models\Report;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\CreditService;
use App\Services\Reports\NormalizedReportBuilder;
use App\Services\VehicleProviders\OneAutoProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
});

test('dispatches auth.preview.viewed when an authenticated user views their report preview', function () {
    Queue::fake();

    $user = User::factory()->create([
        'email' => 'auth-preview@example.com',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'AUTH-PREVIEW-01',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => 'AUTH-PREVIEW-01',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => [
            'make' => 'BMW',
            'model' => '320i',
        ],
    ]);

    $this->actingAs($user)
        ->get(route('page.my-report.show', $report->id))
        ->assertOk();

    Queue::assertPushed(
        SendTelegramGuestActivityNotification::class,
        function (SendTelegramGuestActivityNotification $job) use ($user, $report, $vinCheck) {
            return $job->eventType === 'auth.preview.viewed'
                && $job->dedupeKey === (string) $report->id
                && str_contains($job->message, 'UKCARDOC - AUTH PREVIEW VIEWED')
                && str_contains($job->message, 'User ID: '.$user->id)
                && str_contains($job->message, 'Email: auth-preview@example.com')
                && str_contains($job->message, 'Report ID: '.$report->id)
                && str_contains($job->message, 'Report Type: basic')
                && str_contains($job->message, 'Registration: AUTH-PREVIEW-01');
        }
    );

    // Ensure it uses the activity bot, not the guest event type
    Queue::assertNotPushed(
        SendTelegramGuestActivityNotification::class,
        fn ($job) => $job->eventType === 'guest.preview.viewed'
    );
});

test('deduplicates auth.preview.viewed within the same session', function () {
    Queue::fake();

    $user = User::factory()->create();

    $vehicle = Vehicle::create([
        'registration_number' => 'AUTH-DEDUP-01',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => 'AUTH-DEDUP-01',
        'check_type' => 'premium',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'premium',
        'report_data' => ['level' => 'premium'],
    ]);

    // First visit: notification should be dispatched
    $this->actingAs($user)
        ->get(route('page.my-report.show', $report->id))
        ->assertOk();

    Queue::assertPushed(SendTelegramGuestActivityNotification::class, 1);

    // Second visit in the same session: should NOT dispatch again
    $this->actingAs($user)
        ->get(route('page.my-report.show', $report->id))
        ->assertOk();

    Queue::assertPushed(SendTelegramGuestActivityNotification::class, 1);
});

test('does not dispatch auth.preview.viewed when user tries to view another users report', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $intruder = User::factory()->create();

    $vehicle = Vehicle::create([
        'registration_number' => 'AUTH-INTRUDE-01',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $owner->id,
        'registration_number' => 'AUTH-INTRUDE-01',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'user_id' => $owner->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['make' => 'Audi'],
    ]);

    $this->actingAs($intruder)
        ->get(route('page.my-report.show', $report->id))
        ->assertForbidden();

    Queue::assertNotPushed(SendTelegramGuestActivityNotification::class);
});

test('does not dispatch auth.preview.viewed when an authenticated user opens a guest report', function () {
    Queue::fake();

    $user = User::factory()->create();

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-VRM-99',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => null,
        'registration_number' => 'GUEST-VRM-99',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'user_id' => null,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['make' => 'Toyota'],
    ]);

    GuestLead::create([
        'vin_check_id' => $vinCheck->id,
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'phone' => '07123456789',
    ]);

    $this->actingAs($user)
        ->withSession([
            "guest_vehicle_check_access.{$vinCheck->id}" => true,
            "guest_lead_contact.{$report->id}" => [
                'email' => 'guest@example.com',
                'phone' => '07123456789',
            ],
        ])
        ->get(route('page.my-report.show', $report->id))
        ->assertOk();

    // Must NOT dispatch auth.preview.viewed because the report is a guest report (user_id is null)
    Queue::assertNotPushed(
        SendTelegramGuestActivityNotification::class,
        fn ($job) => $job->eventType === 'auth.preview.viewed'
    );
});

test('dispatches auth.vehicle_check.failed on permanent terminal failure', function () {
    Queue::fake();

    config([
        'services.vehicle_reports.provider' => 'oneauto',
    ]);

    $user = User::factory()->create([
        'email' => 'auth-failed@example.com',
    ]);

    $vrm = 'AUTH-FAIL-01';

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $vrm,
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);
    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')->andThrow(new VehicleNotFoundException('Vehicle not found.'));

    $oneAuto = Mockery::mock(OneAutoProvider::class);
    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    $job = new ProcessVehicleCheck($vinCheck->id);

    expect(fn () => $job->handle(
        $provider,
        $reportBuilder,
        $creditService,
        null,
        $oneAuto,
    ))->toThrow(VehicleNotFoundException::class);

    Queue::assertPushed(
        SendTelegramGuestActivityNotification::class,
        function (SendTelegramGuestActivityNotification $job) use ($user, $vinCheck, $vrm) {
            return $job->eventType === 'auth.vehicle_check.failed'
                && $job->dedupeKey === (string) $vinCheck->id
                && str_contains($job->message, 'UKCARDOC - AUTH VEHICLE CHECK FAILED')
                && str_contains($job->message, 'User ID: '.$user->id)
                && str_contains($job->message, 'Email: auth-failed@example.com')
                && str_contains($job->message, 'VinCheck ID: '.$vinCheck->id)
                && str_contains($job->message, 'Registration: '.$vrm)
                && str_contains($job->message, 'Check Type: premium')
                && str_contains($job->message, 'Failure: Vehicle not found')
                && ! str_contains($job->message, 'Exception')
                && ! str_contains($job->message, 'Stack trace');
        }
    );

    // Ensure it does not dispatch guest event
    Queue::assertNotPushed(
        SendTelegramGuestActivityNotification::class,
        fn ($job) => $job->eventType === 'guest.vehicle_check.failed'
    );

    expect($vinCheck->fresh()->status)->toBe('failed');
});

test('dispatches auth.vehicle_check.failed when maximum retries are exhausted', function () {
    Queue::fake();

    config([
        'services.vehicle_reports.provider' => 'checkcardetails',
        'services.vehicle_reports.strategy' => 'single',
    ]);

    $user = User::factory()->create([
        'email' => 'auth-retry-exhausted@example.com',
    ]);

    $vrm = 'AUTH-RETRY-01';

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $vrm,
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);
    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')->andThrow(new RuntimeException('Sensitive internal provider connection timeout'));

    $creditService = Mockery::mock(CreditService::class);
    $creditService->shouldNotReceive('consume');

    // Simulate 3rd and final attempt (attempts >= tries)
    $fakeJob = new \Illuminate\Queue\Jobs\FakeJob;
    $fakeJob->attempts = 3;

    $job = new ProcessVehicleCheck($vinCheck->id);
    $job->setJob($fakeJob);

    expect(fn () => $job->handle(
        $provider,
        $reportBuilder,
        $creditService,
    ))->toThrow(RuntimeException::class);

    Queue::assertPushed(
        SendTelegramGuestActivityNotification::class,
        function (SendTelegramGuestActivityNotification $job) use ($user, $vinCheck) {
            return $job->eventType === 'auth.vehicle_check.failed'
                && $job->dedupeKey === (string) $vinCheck->id
                && str_contains($job->message, 'UKCARDOC - AUTH VEHICLE CHECK FAILED')
                && str_contains($job->message, 'Failure: Vehicle check failed after retries')
                // Confirm no sensitive provider internal exception trace is leaked
                && ! str_contains($job->message, 'Sensitive internal provider')
                && ! str_contains($job->message, 'RuntimeException');
        }
    );
});

test('does not dispatch auth.vehicle_check.failed on intermediate retries', function () {
    Queue::fake();

    config([
        'services.vehicle_reports.provider' => 'checkcardetails',
        'services.vehicle_reports.strategy' => 'single',
    ]);

    $user = User::factory()->create();

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => 'AUTH-INTERMEDIATE-01',
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    $provider = Mockery::mock(VehicleDataProviderInterface::class);
    $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
    $reportBuilder->shouldReceive('build')->andThrow(new RuntimeException('Transient network error'));

    $creditService = Mockery::mock(CreditService::class);

    // Simulate 1st attempt (attempts = 1, tries = 3)
    $fakeJob = new \Illuminate\Queue\Jobs\FakeJob;
    $fakeJob->attempts = 1;

    $job = new ProcessVehicleCheck($vinCheck->id);
    $job->setJob($fakeJob);

    expect(fn () => $job->handle(
        $provider,
        $reportBuilder,
        $creditService,
    ))->toThrow(RuntimeException::class);

    // Should NOT notify on non-terminal attempt
    Queue::assertNotPushed(SendTelegramGuestActivityNotification::class);
});
