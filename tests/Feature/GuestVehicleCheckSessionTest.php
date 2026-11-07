
<?php

use App\Jobs\ProcessVehicleCheck;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    RateLimiter::clear('free-check:ip127.0.0.1');
});

it('creates guest session access when a visitor starts a vehicle check', function () {
    Queue::fake();

    $response = $this->post(route('vehicle-check.store'), [
        'registration_number' => 'AB12CDE',
    ]);

    $vinCheck = VinCheck::query()
        ->where('registration_number', 'AB12CDE')
        ->firstOrFail();

    $sessionKey = "guest_vehicle_check_access.{$vinCheck->id}";

    $response->assertRedirect(
        route('vehicle-check.loading', $vinCheck->id)
    )->assertSessionHas($sessionKey, true);

    expect($vinCheck->user_id)->toBeNull()
        ->and($vinCheck->status)->toBe('pending');

    // The same browser that initiated the check can access loading.
    $this->get(route('vehicle-check.loading', $vinCheck->id))
        ->assertOk();

    Queue::assertPushed(ProcessVehicleCheck::class);
});

it('denies loading access after the guest browser session is cleared', function () {
    $vinCheck = VinCheck::create([
        'registration_number' => 'AB12XYZ',
        'check_type' => 'free',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    // Simulate a different browser with no guest vehicle-check session.
    $this->get(route('vehicle-check.loading', $vinCheck->id))
        ->assertNotFound();
});

it('redirects a successful guest vehicle check to contact capture', function () {
    $registration = 'GUEST-SUCCESS-101';

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
        'user_id' => null,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => [
            'make' => 'Ford',
            'model' => 'Focus',
        ],
    ]);

    $this->withSession([
        "guest_vehicle_check_access.{$vinCheck->id}" => true,
    ]);

    $this->get(route('vehicle-check.loading', $vinCheck->id))
        ->assertRedirect(
            route('guest-lead.show', $report->id)
        );
});
