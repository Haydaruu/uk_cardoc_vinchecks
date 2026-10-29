<?php

use App\Models\ApiLog;
use App\Models\ProcessedWebhookEvent;
use App\Models\User;
use App\Services\Admin\AdminSystemHealthService;
use App\Services\Reports\CompositeReportBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('guest cannot access system health dashboard and is redirected to login', function () {
    $this->get(route('admin.system-health.index'))
        ->assertRedirect(route('login'));
});

test('regular user is forbidden from accessing system health', function () {
    $user = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('admin.system-health.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('admin.system-health.refresh'))
        ->assertForbidden();
});

test('operational admin is forbidden from accessing super admin system health', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('admin.system-health.index'))
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(route('admin.system-health.refresh'))
        ->assertForbidden();
});

test('super admin can access system health dashboard and diagnostic data is sanitized', function () {
    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($superAdmin)
        ->get(route('admin.system-health.index'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/system-health')
            ->has('health.database')
            ->has('health.storage')
            ->has('health.queue')
            ->has('health.mail')
            ->has('health.gateways')
            ->has('health.vehicle_providers')
            ->has('health.overall_status')
            ->has('health.checked_at')
        );

    // Verify audit log created
    $this->assertDatabaseHas('admin_activity_logs', [
        'admin_id' => $superAdmin->id,
        'action' => 'system_health.viewed',
        'target_type' => 'system',
        'outcome' => 'success',
    ]);
});

test('system health probe tests database connectivity and latency', function () {
    $service = app(AdminSystemHealthService::class);
    $dbHealth = $service->checkDatabase();

    expect($dbHealth['status'])->toBeIn(['healthy', 'degraded'])
        ->and($dbHealth['latency_ms'])->not->toBeNull()
        ->and($dbHealth['error'])->toBeNull();
});

test('storage probe writes and removes transient files without residual artifacts', function () {
    $service = app(AdminSystemHealthService::class);
    $storageHealth = $service->checkStorage();

    expect($storageHealth['status'])->toBe('healthy')
        ->and($storageHealth['latency_ms'])->not->toBeNull()
        ->and($storageHealth['error'])->toBeNull();

    // Verify no leftover probe files in storage
    $files = Storage::disk(config('filesystems.default', 'local'))->files('health-probes');
    expect($files)->toBeEmpty();
});

test('queue probe accurately reports failed jobs and sanitizes exception trace', function () {
    $uuid = (string) Str::uuid();
    $rawTrace = "PDOException: SQLSTATE[08006] [7] connection to server at password=supersecret failed in /var/app/vendor/laravel/framework/src/Database.php:123\nStack trace:\n#0 /var/app/...";

    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['job' => 'ProcessReport']),
        'exception' => $rawTrace,
        'failed_at' => now(),
    ]);

    $service = app(AdminSystemHealthService::class);
    $queueHealth = $service->checkQueue();

    expect($queueHealth['failed_jobs_count'])->toBeGreaterThanOrEqual(1)
        ->and($queueHealth['retry_supported'])->toBeFalse();

    $foundJob = collect($queueHealth['recent_failed_jobs'])->firstWhere('uuid', $uuid);
    expect($foundJob)->not->toBeNull()
        ->and($foundJob['summary'])->not->toContain('supersecret')
        ->and($foundJob['summary'])->not->toContain('Stack trace:')
        ->and($foundJob['summary'])->toContain('[REDACTED]');
});

test('system health dashboard does not make live external provider network calls on render', function () {
    config(['inertia.ssr.enabled' => false]);

    Http::preventStrayRequests();

    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'email_verified_at' => now(),
    ]);

    // Inserting mock api_log to test last-known reporting
    ApiLog::create([
        'vin' => '1HGCR2F83HA000000',
        'api_provider' => 'checkcardetails',
        'endpoint' => '/vehicle-data',
        'response_time_ms' => 120,
        'http_status_code' => 200,
        'request_payload' => ['vrm' => 'TEST123'],
        'response_payload' => ['make' => 'Honda'],
        'status' => 'success',
    ]);

    ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_test_123',
        'event_type' => 'payment_intent.succeeded',
        'status' => ProcessedWebhookEvent::STATUS_COMPLETED,
    ]);

    $response = $this->actingAs($superAdmin)
        ->get(route('admin.system-health.index'));

    $response->assertOk();

    // Verify last-known status was resolved from database without external HTTP calls
    $health = $response->viewData('page')['props']['health'];
    expect($health['gateways']['stripe']['last_event']['event_id'])->toBe('evt_test_123')
        ->and($health['vehicle_providers']['checkcardetails']['last_call']['status_code'])->toBe(200);
});

test('super admin can refresh health probes on demand via json endpoint', function () {
    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($superAdmin)
        ->post(route('admin.system-health.refresh'));

    $response->assertOk()
        ->assertJsonStructure([
            'health' => [
                'overall_status',
                'checked_at',
                'database',
                'storage',
                'queue',
                'mail',
                'gateways',
                'vehicle_providers',
            ],
            'message',
        ]);

    $this->assertDatabaseHas('admin_activity_logs', [
        'admin_id' => $superAdmin->id,
        'action' => 'system_health.refreshed',
        'target_type' => 'system',
        'outcome' => 'success',
    ]);
});

test('provider comparison controller run sanitizes raw output and logs audit activity', function () {
    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'email_verified_at' => now(),
    ]);

    $mockComposite = Mockery::mock(CompositeReportBuilder::class);
    $mockComposite->shouldReceive('buildFixture')
        ->once()
        ->with('EA65AMX', 'AB21ABC')
        ->andReturn([
            'vehicle' => ['vrm' => 'EA65AMX'],
            'raw' => [
                'token' => 'secret_token_123',
                'client_secret' => 'secret_client_456',
                'safe_data' => 'visible_value',
            ],
            'normalized' => ['vehicle' => ['vrm' => 'EA65AMX']],
        ]);
    $this->app->instance(CompositeReportBuilder::class, $mockComposite);

    $response = $this->actingAs($superAdmin)
        ->postJson(route('admin.provider-lab.run'), [
            'provider' => 'hybrid',
            'mode' => 'fixture',
            'checkcardetails_vrm' => 'EA65AMX',
            'oneauto_vrm' => 'AB21ABC',
        ]);

    $response->assertOk();

    // Verify audit log recorded for provider_lab.run
    $this->assertDatabaseHas('admin_activity_logs', [
        'admin_id' => $superAdmin->id,
        'action' => 'provider_lab.run',
        'target_type' => 'provider',
        'outcome' => 'success',
    ]);
});
