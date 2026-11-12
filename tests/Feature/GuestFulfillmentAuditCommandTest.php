<?php

use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Create a guest purchase fixture.
 *
 * M3 reliability fields are intentionally not mass-assignable, so they are
 * applied with forceFill() in test setup only. The command under test never
 * writes them.
 */
function createGuestFulfillmentAuditPurchase(array $attributes = []): GuestReportPurchase
{
    $vehicle = Vehicle::create([
        'registration_number' => 'AUD'.Str::upper(Str::random(6)),
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    $createdAt = $attributes['created_at'] ?? null;
    unset($attributes['created_at']);

    $m3Fields = [
        'fulfillment_generation',
        'fulfillment_dispatch_uuid',
        'fulfillment_dispatch_expires_at',
        'fulfillment_claim_uuid',
        'fulfillment_claim_expires_at',
        'fulfillment_generation_attempts',
        'fulfillment_recovery_attempts',
        'fulfillment_recovery_claim_entitlement_uuid',
        'fulfillment_recovery_exhausted_at',
        'fulfillment_manual_review_at',
        'fulfillment_failure_reason',
        'fulfillment_dispatch_push_slots_allocated',
    ];

    $m3Attributes = array_intersect_key($attributes, array_flip($m3Fields));
    $attributes = array_diff_key($attributes, $m3Attributes);

    $purchase = GuestReportPurchase::create(array_merge([
        'report_id' => $report->id,
        'name' => 'Audit Test User',
        'email' => 'audit-'.Str::random(6).'@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'provider_order_id' => 'ORDER-AUD-'.Str::upper(Str::random(10)),
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
        'fulfillment_attempts' => 0,
    ], $attributes));

    if ($m3Attributes !== []) {
        $purchase->forceFill($m3Attributes)->save();
    }

    if ($createdAt !== null) {
        $purchase->timestamps = false;
        $purchase->created_at = $createdAt;
        $purchase->save();
        $purchase->timestamps = true;
    }

    return $purchase->refresh();
}

/**
 * @return array{0: int, 1: string}
 */
function runGuestFulfillmentAuditCommand(array $options = []): array
{
    $exitCode = Artisan::call('guest-fulfillment:audit', $options);

    return [$exitCode, Artisan::output()];
}

/**
 * @return array<string, mixed>
 */
function decodeGuestFulfillmentAuditJson(string $output): array
{
    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('it audits locked, pending, processing, and failed paid purchases', function () {
    $locked = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_LOCKED,
    ]);
    $pending = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);
    $processing = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
    ]);
    $failed = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
    ]);

    [$exitCode, $output] = runGuestFulfillmentAuditCommand(['--json' => true]);

    expect($exitCode)->toBe(Command::SUCCESS);

    $payload = decodeGuestFulfillmentAuditJson($output);

    expect($payload['count'])->toBe(4)
        ->and(array_column($payload['rows'], 'id'))->toBe([
            $locked->id,
            $pending->id,
            $processing->id,
            $failed->id,
        ]);
});

test('it excludes completed purchases and purchases that are not paid', function () {
    $pending = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);

    createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
    ]);
    createGuestFulfillmentAuditPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);
    createGuestFulfillmentAuditPurchase([
        'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
    ]);

    [$exitCode, $output] = runGuestFulfillmentAuditCommand(['--json' => true]);

    expect($exitCode)->toBe(Command::SUCCESS);

    $payload = decodeGuestFulfillmentAuditJson($output);

    expect($payload['count'])->toBe(1)
        ->and(array_column($payload['rows'], 'id'))->toBe([$pending->id])
        ->and(array_column($payload['rows'], 'fulfillment_status'))->toBe([
            GuestReportPurchase::FULFILLMENT_PENDING,
        ]);
});

test('it does not modify the database or dispatch a job', function () {
    Queue::fake();

    createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
        'fulfillment_dispatch_expires_at' => now()->subMinutes(30),
        'fulfillment_claim_expires_at' => now()->subMinutes(10),
        'fulfillment_generation_attempts' => 2,
        'fulfillment_recovery_attempts' => 1,
    ]);
    createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
        'fulfillment_manual_review_at' => now()->subDay(),
    ]);

    $snapshot = fn (): array => DB::table('guest_report_purchases')
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    $before = $snapshot();
    $ledgerBefore = DB::table('guest_report_fulfillment_attempts')->count();

    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        $sql = strtolower(ltrim($query->sql));

        if (str_starts_with($sql, 'insert')
            || str_starts_with($sql, 'update')
            || str_starts_with($sql, 'delete')) {
            $writes[] = $query->sql;
        }
    });

    [$exitCode] = runGuestFulfillmentAuditCommand();

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($snapshot())->toBe($before)
        ->and(DB::table('guest_report_fulfillment_attempts')->count())->toBe($ledgerBefore)
        ->and($writes)->toBe([]);

    Queue::assertNothingPushed();
});

test('it filters rows by status', function () {
    createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);
    $failed = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
    ]);
    createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
    ]);

    [$exitCode, $output] = runGuestFulfillmentAuditCommand([
        '--status' => GuestReportPurchase::FULFILLMENT_FAILED,
        '--json' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS);

    $payload = decodeGuestFulfillmentAuditJson($output);

    expect($payload['filters'])->toBe([
        'status' => GuestReportPurchase::FULFILLMENT_FAILED,
        'older_than_hours' => null,
        'limit' => null,
    ])
        ->and($payload['count'])->toBe(1)
        ->and($payload['rows'][0]['id'])->toBe($failed->id);
});

test('it rejects a status that is not auditable', function () {
    $this->artisan('guest-fulfillment:audit', ['--status' => 'completed'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid --status');

    $this->artisan('guest-fulfillment:audit', ['--status' => 'refunded'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid --status');
});

test('it filters rows by age in hours', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));

    $old = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
        'created_at' => now()->subHours(48),
    ]);
    $exactBoundary = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
        'created_at' => now()->subHours(24),
    ]);
    createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
        'created_at' => now()->subHours(2),
    ]);

    [$exitCode, $output] = runGuestFulfillmentAuditCommand([
        '--older-than' => '24',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS);

    $payload = decodeGuestFulfillmentAuditJson($output);

    expect($payload['filters']['older_than_hours'])->toBe(24)
        ->and(array_column($payload['rows'], 'id'))->toBe([
            $old->id,
            $exactBoundary->id,
        ]);

    Carbon::setTestNow();
});

test('it applies the limit after ordering by id', function () {
    $first = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);
    $second = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
    ]);
    createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
    ]);

    [$exitCode, $output] = runGuestFulfillmentAuditCommand([
        '--limit' => '2',
        '--json' => true,
    ]);

    expect($exitCode)->toBe(Command::SUCCESS);

    $payload = decodeGuestFulfillmentAuditJson($output);

    expect($payload['filters']['limit'])->toBe(2)
        ->and(array_column($payload['rows'], 'id'))->toBe([$first->id, $second->id]);
});

test('it rejects invalid numeric options', function () {
    $this->artisan('guest-fulfillment:audit', ['--limit' => '0'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid --limit');

    $this->artisan('guest-fulfillment:audit', ['--limit' => 'abc'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid --limit');

    $this->artisan('guest-fulfillment:audit', ['--older-than' => '-1'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid --older-than');

    $this->artisan('guest-fulfillment:audit', ['--older-than' => '1.5'])
        ->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid --older-than');
});

test('it emits deterministic json with a stable schema', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));

    createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
        'created_at' => now()->subHours(2),
        'fulfillment_attempts' => 3,
        'fulfillment_generation' => 2,
        'fulfillment_generation_attempts' => 1,
        'fulfillment_recovery_attempts' => 0,
    ]);

    [$exitCode, $firstOutput] = runGuestFulfillmentAuditCommand(['--json' => true]);
    [, $secondOutput] = runGuestFulfillmentAuditCommand(['--json' => true]);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($secondOutput)->toBe($firstOutput);

    $payload = decodeGuestFulfillmentAuditJson($firstOutput);

    expect($payload)->toHaveKeys(['filters', 'count', 'rows'])
        ->and($payload['filters'])->toBe([
            'status' => null,
            'older_than_hours' => null,
            'limit' => null,
        ])
        ->and($payload['count'])->toBe(1)
        ->and(array_keys($payload['rows'][0]))->toBe([
            'id',
            'report_id',
            'fulfillment_status',
            'fulfillment_attempts',
            'fulfillment_generation',
            'fulfillment_generation_attempts',
            'fulfillment_recovery_attempts',
            'fulfillment_dispatch_expires_at',
            'fulfillment_claim_expires_at',
            'fulfillment_manual_review_at',
            'created_at',
            'age_seconds',
        ])
        ->and($payload['rows'][0]['fulfillment_attempts'])->toBe(3)
        ->and($payload['rows'][0]['fulfillment_generation'])->toBe(2)
        ->and($payload['rows'][0]['fulfillment_generation_attempts'])->toBe(1)
        ->and($payload['rows'][0]['fulfillment_recovery_attempts'])->toBe(0)
        ->and($payload['rows'][0]['created_at'])->toBe('2026-11-11T10:00:00+00:00')
        ->and($payload['rows'][0]['age_seconds'])->toBe(7200);

    Carbon::setTestNow();
});

test('it renders nullable M3 fields as null in json and as placeholders in the table', function () {
    $purchase = createGuestFulfillmentAuditPurchase([
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
    ]);

    [$exitCode, $output] = runGuestFulfillmentAuditCommand(['--json' => true]);

    expect($exitCode)->toBe(Command::SUCCESS);

    $row = decodeGuestFulfillmentAuditJson($output)['rows'][0];

    expect($row['id'])->toBe($purchase->id)
        ->and($row['fulfillment_dispatch_expires_at'])->toBeNull()
        ->and($row['fulfillment_claim_expires_at'])->toBeNull()
        ->and($row['fulfillment_manual_review_at'])->toBeNull();

    [$humanExitCode, $humanOutput] = runGuestFulfillmentAuditCommand();

    expect($humanExitCode)->toBe(Command::SUCCESS)
        ->and($humanOutput)->toContain('| -')
        ->and($humanOutput)->toContain('fulfillment_status=processing is not assumed to be orphaned');
});

test('it handles an empty result without error', function () {
    [$jsonExitCode, $jsonOutput] = runGuestFulfillmentAuditCommand(['--json' => true]);

    expect($jsonExitCode)->toBe(Command::SUCCESS);

    $payload = decodeGuestFulfillmentAuditJson($jsonOutput);

    expect($payload['count'])->toBe(0)
        ->and($payload['rows'])->toBe([]);

    $this->artisan('guest-fulfillment:audit')
        ->assertExitCode(Command::SUCCESS)
        ->expectsOutputToContain('No matching guest fulfillment rows.');
});
