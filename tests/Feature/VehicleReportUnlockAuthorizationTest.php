<?php

use App\Jobs\ProcessVehicleCheck;
use App\Models\Report;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VinCheck;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('an authenticated user cannot unlock another users report and request is forbidden', function () {
    Queue::fake();

    $victim = User::factory()->create([
        'credits' => 3,
    ]);

    $attacker = User::factory()->create([
        'credits' => 5,
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'VIC-100',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $victim->id,
        'registration_number' => 'VIC-100',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'finalizing',
    ]);

    $report = Report::create([
        'user_id' => $victim->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['make' => 'BMW', 'model' => '320i'],
    ]);

    $response = $this->actingAs($attacker)
        ->post(route('report.unlock', $report->id));

    $response->assertForbidden();

    // Verify denial occurs before job dispatch
    Queue::assertNotPushed(ProcessVehicleCheck::class);

    // Verify credits of both users remain completely unchanged
    expect($victim->fresh()->credits)->toBe(3);
    expect($attacker->fresh()->credits)->toBe(5);

    // Verify report and vinCheck states remain unchanged
    expect($report->fresh()->report_type)->toBe('basic');
    expect($vinCheck->fresh()->status)->toBe('success');
    expect($vinCheck->fresh()->user_id)->toBe($victim->id);
});

test('denial occurs before any state change when target report has mismatched vinCheck user', function () {
    Queue::fake();

    $user = User::factory()->create([
        'credits' => 2,
    ]);

    $otherUser = User::factory()->create([
        'credits' => 2,
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'MISMATCH-1',
    ]);

    // An anomalous state: report belongs to $user, but vinCheck belongs to $otherUser
    $vinCheck = VinCheck::create([
        'user_id' => $otherUser->id,
        'registration_number' => 'MISMATCH-1',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'finalizing',
    ]);

    $report = Report::create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    $response = $this->actingAs($user)
        ->post(route('report.unlock', $report->id));

    $response->assertForbidden();
    Queue::assertNotPushed(ProcessVehicleCheck::class);
    expect($user->fresh()->credits)->toBe(2);
    expect($otherUser->fresh()->credits)->toBe(2);
});

test('an unowned guest report cannot be claimed merely by supplying its id', function () {
    Queue::fake();

    $user = User::factory()->create([
        'credits' => 5,
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-FREE-1',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => null,
        'registration_number' => 'GUEST-FREE-1',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'finalizing',
    ]);

    $guestReport = Report::create([
        'user_id' => null,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['make' => 'Toyota', 'model' => 'Corolla'],
    ]);

    $response = $this->actingAs($user)
        ->post(route('report.unlock', $guestReport->id));

    $response->assertForbidden();

    Queue::assertNotPushed(ProcessVehicleCheck::class);
    expect($user->fresh()->credits)->toBe(5);
    expect($guestReport->fresh()->user_id)->toBeNull();
    expect($vinCheck->fresh()->user_id)->toBeNull();
    expect($vinCheck->fresh()->status)->toBe('success');
});

test('the legitimate report owner can unlock their basic report using credits', function () {
    Queue::fake();

    $owner = User::factory()->create([
        'credits' => 3,
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'OWNER-200',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $owner->id,
        'registration_number' => 'OWNER-200',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'finalizing',
    ]);

    $report = Report::create([
        'user_id' => $owner->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['make' => 'Honda', 'model' => 'Civic'],
    ]);

    $response = $this->actingAs($owner)
        ->post(route('report.unlock', $report->id));

    $response->assertRedirect(route('vehicle-check.loading', $vinCheck->id));

    Queue::assertPushed(ProcessVehicleCheck::class, function ($job) use ($vinCheck, $report) {
        $reflection = new ReflectionClass($job);
        $vinCheckIdProp = $reflection->getProperty('vinCheckId');
        $existingReportIdProp = $reflection->getProperty('existingReportId');

        return $vinCheckIdProp->getValue($job) === $vinCheck->id
            && $existingReportIdProp->getValue($job) === $report->id;
    });

    expect($vinCheck->fresh()->check_type)->toBe('premium');
    expect($vinCheck->fresh()->status)->toBe('pending');
    expect($vinCheck->fresh()->stage)->toBe('queued');
});

test('report owner is redirected to show page if report is already premium', function () {
    Queue::fake();

    $owner = User::factory()->create([
        'credits' => 3,
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'OWNER-300',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $owner->id,
        'registration_number' => 'OWNER-300',
        'check_type' => 'premium',
        'status' => 'success',
        'stage' => 'finalizing',
    ]);

    $report = Report::create([
        'user_id' => $owner->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'premium',
        'report_data' => ['make' => 'Ford', 'model' => 'Puma'],
    ]);

    $response = $this->actingAs($owner)
        ->post(route('report.unlock', $report->id));

    $response->assertRedirect(route('page.my-report.show', $report->id));
    Queue::assertNotPushed(ProcessVehicleCheck::class);
});

test('guest checkout prepare endpoint remains accessible and stores session contact details', function () {
    $this->withoutVite();

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-LEGIT-1',
    ]);

    $guestReport = Report::create([
        'user_id' => null,
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
        'report_data' => ['make' => 'Vauxhall', 'model' => 'Corsa'],
    ]);

    // Guest checkout prepare endpoint remains accessible and records session data
    $prepareResponse = $this->post(route('guest-report.checkout.prepare', $guestReport->id), [
        'name' => 'Jane Guest',
        'email' => 'jane@example.com',
        'phone' => '+447911123456',
    ]);

    $prepareResponse->assertRedirect(route('guest-report.checkout.show', $guestReport->id));
    $prepareResponse->assertSessionHas("guest_report_checkout.{$guestReport->id}", [
        'name' => 'Jane Guest',
        'email' => 'jane@example.com',
        'phone' => '+447911123456',
    ]);
});
