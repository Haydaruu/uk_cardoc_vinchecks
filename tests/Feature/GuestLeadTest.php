<?php

use App\Jobs\SendTelegramGuestActivityNotification;
use App\Models\GuestLead;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return array{vinCheck: VinCheck, vehicle: Vehicle, report: Report}
 */
function createGuestLeadFixtures(): array
{
    $vinCheck = VinCheck::create([
        'registration_number' => 'AB12CDE',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'AB12CDE',
    ]);

    $report = Report::create([
        'vin_check_id' => $vinCheck->id,
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    return compact('vinCheck', 'vehicle', 'report');
}

it('stores a guest lead with an optional phone number and resolves its relationships', function () {
    $fixtures = createGuestLeadFixtures();

    $lead = GuestLead::create([
        'vin_check_id' => $fixtures['vinCheck']->id,
        'report_id' => $fixtures['report']->id,
        'email' => 'guest@example.com',
        'phone' => null,
    ]);

    expect($lead->phone)->toBeNull();

    $this->assertTrue(
        $lead->vinCheck()->whereKey($fixtures['vinCheck']->id)->exists()
    );

    $this->assertTrue(
        $lead->report()->whereKey($fixtures['report']->id)->exists()
    );

    $this->assertTrue(
        $fixtures['vinCheck']->guestLeads()->whereKey($lead->id)->exists()
    );

    $this->assertTrue(
        $fixtures['report']->guestLead()->whereKey($lead->id)->exists()
    );
});

it('allows multiple guest leads for different reports from the same vehicle check', function () {
    $fixtures = createGuestLeadFixtures();

    $secondReport = Report::create([
        'vin_check_id' => $fixtures['vinCheck']->id,
        'vehicle_id' => $fixtures['vehicle']->id,
        'report_type' => 'basic',
    ]);

    GuestLead::create([
        'vin_check_id' => $fixtures['vinCheck']->id,
        'report_id' => $fixtures['report']->id,
        'email' => 'first@example.com',
    ]);

    GuestLead::create([
        'vin_check_id' => $fixtures['vinCheck']->id,
        'report_id' => $secondReport->id,
        'email' => 'second@example.com',
    ]);

    $this->assertDatabaseCount('guest_leads', 2);
});

it('prevents duplicate guest leads for the same report', function () {
    $fixtures = createGuestLeadFixtures();

    GuestLead::create([
        'vin_check_id' => $fixtures['vinCheck']->id,
        'report_id' => $fixtures['report']->id,
        'email' => 'first@example.com',
    ]);

    expect(fn () => GuestLead::create([
        'vin_check_id' => $fixtures['vinCheck']->id,
        'report_id' => $fixtures['report']->id,
        'email' => 'duplicate@example.com',
    ]))->toThrow(QueryException::class);
});

it('requires an email address at the database level', function () {
    $fixtures = createGuestLeadFixtures();

    expect(fn () => GuestLead::create([
        'vin_check_id' => $fixtures['vinCheck']->id,
        'report_id' => $fixtures['report']->id,
    ]))->toThrow(QueryException::class);
});

it('redirects a guest basic report to contact capture before showing the preview', function () {
    $fixtures = createGuestLeadFixtures();
    $report = $fixtures['report'];

    $this->withSession([
        "guest_vehicle_check_access.{$fixtures['vinCheck']->id}" => true,
    ])->get(route('page.my-report.show', ['report' => $report->id]))
        ->assertRedirect(route('guest-lead.show', ['report' => $report->id]));
});

it('stores guest contact details and allows access to the basic preview', function () {
    Queue::fake();

    $fixtures = createGuestLeadFixtures();
    $report = $fixtures['report'];

    $this->withSession([
        "guest_vehicle_check_access.{$fixtures['vinCheck']->id}" => true,
    ]);

    $this->post(route('guest-lead.store', ['report' => $report->id]), [
        'email' => 'guest@example.com',
        'phone' => '',
    ])->assertRedirect(route('page.my-report.show', ['report' => $report->id]))
        ->assertSessionHas(
            "guest_lead_contact.{$report->id}",
            [
                'email' => 'guest@example.com',
                'phone' => '',
            ],
        );

    $this->assertDatabaseHas('guest_leads', [
        'vin_check_id' => $fixtures['vinCheck']->id,
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'phone' => null,
    ]);

    $this->get(route('page.my-report.show', ['report' => $report->id]))
        ->assertOk();

    Queue::assertPushed(
        SendTelegramGuestActivityNotification::class,
        fn (SendTelegramGuestActivityNotification $job) => $job->eventType === 'guest.preview.viewed'
            && $job->dedupeKey === (string) $report->id
    );
});

it('rejects invalid email during guest contact capture', function () {
    $fixtures = createGuestLeadFixtures();
    $report = $fixtures['report'];

    $this->withSession([
        "guest_vehicle_check_access.{$fixtures['vinCheck']->id}" => true,
    ]);

    $this->post(route('guest-lead.store', ['report' => $report->id]), [
        'email' => 'not-an-email',
        'phone' => '',
    ])->assertSessionHasErrors('email');

    $this->assertDatabaseCount('guest_leads', 0);
});

it('prevents another browser session from accessing an existing guest lead and report', function () {
    $fixtures = createGuestLeadFixtures();
    $report = $fixtures['report'];

    $sessionKey = "guest_vehicle_check_access.{$fixtures['vinCheck']->id}";

    // Browser A initiates the vehicle check and captures contact details.
    $this->withSession([
        $sessionKey => true,
    ])->post(route('guest-lead.store', ['report' => $report->id]), [
        'email' => 'owner@example.com',
        'phone' => '',
    ])->assertRedirect(route('page.my-report.show', ['report' => $report->id]));

    // Simulate browser B with a separate session and no vehicle-check access.
    $this->flushSession();

    $this->get(route('guest-lead.show', ['report' => $report->id]))
        ->assertNotFound();

    $this->post(route('guest-lead.store', ['report' => $report->id]), [
        'email' => 'attacker@example.com',
        'phone' => '',
    ])->assertNotFound();

    $this->get(route('page.my-report.show', ['report' => $report->id]))
        ->assertNotFound();

    // The original lead must remain unchanged.
    $this->assertDatabaseHas('guest_leads', [
        'report_id' => $report->id,
        'email' => 'owner@example.com',
    ]);

    $this->assertDatabaseCount('guest_leads', 1);
});

it('restores saved guest contact details for the authorized browser session', function () {
    Queue::fake();

    $fixtures = createGuestLeadFixtures();
    $report = $fixtures['report'];

    $lead = GuestLead::create([
        'vin_check_id' => $fixtures['vinCheck']->id,
        'report_id' => $report->id,
        'email' => 'recovery@example.com',
        'phone' => null,
    ]);

    $this->withSession([
        "guest_vehicle_check_access.{$fixtures['vinCheck']->id}" => true,
    ]);

    // Successful vehicle check directs the visitor to the report.
    $this->get(route('vehicle-check.loading', $fixtures['vinCheck']->id))
        ->assertRedirect(route('page.my-report.show', $report->id));

    // Missing contact session sends the visitor to lead recovery.
    $this->get(route('page.my-report.show', $report->id))
        ->assertRedirect(route('guest-lead.show', $report->id));

    // Existing lead is restored into the authorized browser session.
    $this->get(route('guest-lead.show', $report->id))
        ->assertRedirect(route('page.my-report.show', $report->id))
        ->assertSessionHas(
            "guest_lead_contact.{$report->id}",
            [
                'email' => 'recovery@example.com',
                'phone' => '',
            ],
        );

    // The restored session can now access the basic preview.
    $this->get(route('page.my-report.show', $report->id))
        ->assertOk();

    expect($lead->fresh()->email)->toBe('recovery@example.com');

    Queue::assertPushed(
        SendTelegramGuestActivityNotification::class,
        fn (SendTelegramGuestActivityNotification $job) => $job->eventType === 'guest.preview.viewed'
            && $job->dedupeKey === (string) $report->id
    );
});

it('blocks a guest basic report without a vehicle check', function () {
    $vehicle = Vehicle::create([
        'registration_number' => 'AB12CDE',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    $this->get(route('page.my-report.show', [
        'report' => $report->id,
    ]))->assertForbidden();
});
