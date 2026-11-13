<?php

use App\Models\CreditTransaction;
use App\Models\Report;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\CreditService;

/**
 * Regression tests for idempotent credit consumption.
 *
 * The logical billing unit is one premium vehicle check. Every consumption is
 * keyed by that unit so a retried or redispatched job never deducts a second
 * credit for the same logical charge.
 */

/**
 * Create a user with a known credit balance.
 */
function creditIdempotencyUser(int $credits = 3): User
{
    return User::factory()->create([
        'credits' => $credits,
    ]);
}

/**
 * Create a premium vehicle check with a report attached.
 */
function creditIdempotencyVehicleCheck(User $user, string $registration): VinCheck
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

    $vinCheck->setAttribute('report_id', $report->id);

    return $vinCheck;
}

test('1. first consumption deducts exactly one credit and writes one usage ledger row', function () {
    $user = creditIdempotencyUser(3);
    $vinCheck = creditIdempotencyVehicleCheck($user, 'IDEM-FIRST-001');

    $transaction = app(CreditService::class)->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        description: 'Vehicle check: IDEM-FIRST-001',
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    expect($user->fresh()->credits)->toBe(2)
        ->and($transaction->type)->toBe('usage')
        ->and($transaction->amount)->toBe(-1)
        ->and($transaction->balance_after)->toBe(2)
        ->and($transaction->reference_id)->toBe((string) $vinCheck->getAttribute('report_id'))
        ->and($transaction->idempotency_key)->toBe('usage:vin_check:'.$vinCheck->id);

    expect(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});

test('2. repeated consumption with the same key returns the existing usage transaction', function () {
    $user = creditIdempotencyUser(3);
    $vinCheck = creditIdempotencyVehicleCheck($user, 'IDEM-REPEAT-001');

    $service = app(CreditService::class);

    $first = $service->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    $second = $service->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    expect($second->id)->toBe($first->id)
        ->and($second->idempotency_key)->toBe($first->idempotency_key)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});

test('3. the balance remains unchanged when a consumption is replayed', function () {
    $user = creditIdempotencyUser(5);
    $vinCheck = creditIdempotencyVehicleCheck($user, 'IDEM-BALANCE-001');

    $service = app(CreditService::class);

    $service->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    expect($user->fresh()->credits)->toBe(4);

    foreach (range(1, 3) as $ignored) {
        $service->consume(
            user: $user,
            referenceId: (string) $vinCheck->getAttribute('report_id'),
            idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
        );
    }

    expect($user->fresh()->credits)->toBe(4)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});

test('4. two different vehicle checks deduct two separate credits', function () {
    $user = creditIdempotencyUser(5);

    $first = creditIdempotencyVehicleCheck($user, 'IDEM-DIFF-001');
    $second = creditIdempotencyVehicleCheck($user, 'IDEM-DIFF-002');

    $service = app(CreditService::class);

    $service->consume(
        user: $user,
        referenceId: (string) $first->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$first->id,
    );

    $service->consume(
        user: $user,
        referenceId: (string) $second->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$second->id,
    );

    expect($user->fresh()->credits)->toBe(3)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(2)
        ->and(CreditTransaction::query()->where('type', 'usage')->pluck('idempotency_key')->all())
        ->toContain('usage:vin_check:'.$first->id)
        ->toContain('usage:vin_check:'.$second->id);
});

test('5. an insufficient balance still throws the existing exception and writes no ledger row', function () {
    $user = creditIdempotencyUser(0);
    $vinCheck = creditIdempotencyVehicleCheck($user, 'IDEM-NO-CREDIT-001');

    expect(fn () => app(CreditService::class)->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    ))->toThrow(RuntimeException::class, 'Insufficient credits.');

    expect($user->fresh()->credits)->toBe(0)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(0);
});

test('6. replaying an already consumed key at zero balance neither throws nor deducts again', function () {
    $user = creditIdempotencyUser(1);
    $vinCheck = creditIdempotencyVehicleCheck($user, 'IDEM-ZERO-REPLAY-001');

    $service = app(CreditService::class);

    $first = $service->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    expect($user->fresh()->credits)->toBe(0);

    $replay = $service->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    expect($replay->id)->toBe($first->id)
        ->and($user->fresh()->credits)->toBe(0)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});

test('7. historical usage rows without an idempotency key remain untouched', function () {
    $user = creditIdempotencyUser(4);
    $vinCheck = creditIdempotencyVehicleCheck($user, 'IDEM-LEGACY-001');

    // A ledger row written before the idempotency key existed.
    $legacy = CreditTransaction::create([
        'user_id' => $user->id,
        'type' => 'usage',
        'amount' => -1,
        'balance_after' => 4,
        'reference_id' => '999',
        'idempotency_key' => null,
        'description' => 'Legacy usage row without an idempotency key',
    ]);

    $legacyUpdatedAt = $legacy->updated_at;

    $transaction = app(CreditService::class)->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    $legacy->refresh();

    expect($legacy->idempotency_key)->toBeNull()
        ->and($legacy->amount)->toBe(-1)
        ->and($legacy->reference_id)->toBe('999')
        ->and($legacy->updated_at->timestamp)->toBe($legacyUpdatedAt->timestamp)
        ->and($transaction->id)->not->toBe($legacy->id)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(2);
});

test('8. an explicit idempotency key takes precedence over the reference id fallback', function () {
    $user = creditIdempotencyUser(2);
    $vinCheck = creditIdempotencyVehicleCheck($user, 'IDEM-KEY-PRECEDENCE-001');

    $service = app(CreditService::class);

    $first = $service->consume(
        user: $user,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    // A different reference id with the same explicit key is still the same
    // logical charge and must not deduct again.
    $second = $service->consume(
        user: $user,
        referenceId: '4242',
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    expect($second->id)->toBe($first->id)
        ->and($user->fresh()->credits)->toBe(1)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});

test('9. a consumption without an explicit key falls back to a reference scoped key', function () {
    $user = creditIdempotencyUser(2);
    $vinCheck = creditIdempotencyVehicleCheck($user, 'IDEM-FALLBACK-001');

    $service = app(CreditService::class);

    $reportId = (string) $vinCheck->getAttribute('report_id');

    $first = $service->consume(
        user: $user,
        referenceId: $reportId,
    );

    $second = $service->consume(
        user: $user,
        referenceId: $reportId,
    );

    expect($first->idempotency_key)->toBe('usage:reference:'.$reportId)
        ->and($second->id)->toBe($first->id)
        ->and($user->fresh()->credits)->toBe(1)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});

test('10. an idempotency key owned by another user is rejected', function () {
    $owner = creditIdempotencyUser(3);
    $other = creditIdempotencyUser(3);

    $vinCheck = creditIdempotencyVehicleCheck($owner, 'IDEM-OWNER-001');

    $service = app(CreditService::class);

    $service->consume(
        user: $owner,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    );

    expect(fn () => $service->consume(
        user: $other,
        referenceId: (string) $vinCheck->getAttribute('report_id'),
        idempotencyKey: 'usage:vin_check:'.$vinCheck->id,
    ))->toThrow(RuntimeException::class);

    expect($other->fresh()->credits)->toBe(3)
        ->and(CreditTransaction::query()->where('type', 'usage')->count())->toBe(1);
});
