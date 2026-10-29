<?php

use App\Models\AdminActivityLog;
use App\Models\Report;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->regularUser = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
    ]);

    $this->admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'email_verified_at' => now(),
    ]);
});

test('unauthenticated guest is redirected to login for system configuration', function () {
    $this->get(route('admin.system-configuration.index'))->assertRedirect(route('login'));
    $this->post(route('admin.system-configuration.update'), [])->assertRedirect(route('login'));
});

test('regular customer cannot view or update system configuration', function () {
    $this->actingAs($this->regularUser)
        ->get(route('admin.system-configuration.index'))
        ->assertForbidden();

    $this->actingAs($this->regularUser)
        ->post(route('admin.system-configuration.update'), [
            'settings' => ['company_name' => 'Hacked Name'],
            'reason' => 'Unauthorized attempt',
        ])
        ->assertForbidden();
});

test('operational admin without super_admin role cannot access system configuration', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.system-configuration.index'))
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post(route('admin.system-configuration.update'), [
            'settings' => ['company_name' => 'Unauthorized Update'],
            'reason' => 'Unauthorized operational admin attempt',
        ])
        ->assertForbidden();
});

test('super admin can view system configuration with safe non-secret data', function () {
    $response = $this->actingAs($this->superAdmin)
        ->get(route('admin.system-configuration.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/system-configuration')
            ->has('settings')
            ->has('infrastructure')
            ->where('settings.company_name.value', 'VinCheck UK')
            ->where('settings.maintenance_mode.value', false)
            ->where('settings.default_report_credits.value', 1)
        );

    // Verify infrastructure payload contains no credentials or secrets
    $infra = $response->viewData('page')['props']['infrastructure'];
    expect($infra)->toHaveKeys(['gateways', 'mail', 'queue', 'storage', 'providers', 'plans']);
    expect($infra['gateways']['stripe'])->not->toHaveKeys(['secret', 'api_key', 'webhook_secret']);
    expect($infra['gateways']['paypal'])->not->toHaveKeys(['secret', 'client_secret']);
    expect($infra['mail'])->not->toHaveKey('password');
});

test('updating configuration requires an audit reason of at least 5 characters', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.system-configuration.update'), [
            'settings' => ['company_name' => 'New Name'],
            'reason' => '123', // too short
        ])
        ->assertSessionHasErrors(['reason']);
});

test('updating non-allowlisted configuration key is strictly rejected', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.system-configuration.update'), [
            'settings' => ['stripe_api_secret' => 'malicious_secret_leak'],
            'reason' => 'Testing forbidden key update attempt',
        ])
        ->assertSessionHasErrors(['settings.stripe_api_secret']);
});

test('super admin can update allowlisted settings with audit logging', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.system-configuration.update'), [
            'settings' => [
                'company_name' => 'UK Vehicle Check Pro',
                'maintenance_mode' => true,
                'default_report_credits' => 2,
            ],
            'reason' => 'Annual brand refresh and credit schedule update',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    // Verify persisted database settings
    expect(SystemSetting::where('key', 'company_name')->value('value'))->toBe('UK Vehicle Check Pro');
    expect(SystemSetting::where('key', 'maintenance_mode')->value('value'))->toBe('true');
    expect(SystemSetting::where('key', 'default_report_credits')->value('value'))->toBe('2');

    // Verify audit logs were created
    $logs = AdminActivityLog::where('action', 'system_configuration.updated')
        ->where('admin_id', $this->superAdmin->id)
        ->get();

    expect($logs->count())->toBe(3);
    expect($logs->first()->reason)->toBe('Annual brand refresh and credit schedule update');
    expect($logs->first()->outcome)->toBe('success');
});

test('updating allowlisted configuration validates data types, format, and ranges', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('admin.system-configuration.update'), [
            'settings' => [
                'support_email' => 'not-a-valid-email-address',
                'default_report_credits' => 999, // exceeds max 10
            ],
            'reason' => 'Testing invalid data types and out of range validation',
        ])
        ->assertSessionHasErrors([
            'settings.support_email',
            'settings.default_report_credits',
        ]);
});

test('runtime enforcement blocks guest checkout when disabled in system settings', function () {
    $vehicle = Vehicle::create(['registration_number' => 'GUEST-CONF']);
    $report = Report::create(['vehicle_id' => $vehicle->id, 'report_type' => 'basic']);

    // Enable guest checkout (default)
    SystemSetting::updateOrCreate(
        ['key' => 'enable_guest_checkout'],
        ['value' => 'true', 'type' => 'boolean', 'group' => 'features']
    );

    // Prepare endpoint responds when enabled
    $this->post(route('guest-report.checkout.prepare', $report), [
        'name' => 'John Guest',
        'email' => 'john.guest@example.com',
        'phone' => '+44 7911 123456',
    ])->assertRedirect(route('guest-report.checkout.show', $report));

    // Disable guest checkout via SystemSetting
    SystemSetting::updateOrCreate(
        ['key' => 'enable_guest_checkout'],
        ['value' => 'false', 'type' => 'boolean', 'group' => 'features']
    );

    // Prepare and store endpoints now block guest checkout
    $this->post(route('guest-report.checkout.prepare', $report), [
        'name' => 'John Guest',
        'email' => 'john.guest@example.com',
        'phone' => '+44 7911 123456',
    ])->assertRedirect(route('page.my-report.show', $report))
        ->assertSessionHasErrors(['checkout']);
});
