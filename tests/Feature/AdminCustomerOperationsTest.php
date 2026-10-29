<?php

use App\Models\AdminActivityLog;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\CreditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->customer = User::factory()->create([
        'role' => 'user',
        'credits' => 10,
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);
});

test('sqlite migration supports admin_adjustment type and enforces idempotency unique constraint', function () {
    // 1. Verify admin_adjustment type is accepted
    $tx = CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'admin_adjustment',
        'amount' => 5,
        'balance_after' => 15,
        'idempotency_key' => 'unique-migration-test-key-1',
        'description' => 'Testing migration support for admin_adjustment',
    ]);

    expect($tx->type)->toBe('admin_adjustment');
    expect($tx->id)->toBeGreaterThan(0);

    // 2. Verify unique constraint on idempotency_key is active
    expect(function () {
        CreditTransaction::create([
            'user_id' => $this->customer->id,
            'type' => 'bonus',
            'amount' => 1,
            'balance_after' => 16,
            'idempotency_key' => 'unique-migration-test-key-1', // duplicate key
            'description' => 'Should fail due to unique constraint',
        ]);
    })->toThrow(UniqueConstraintViolationException::class);
});

test('migration down guard prevents rolling back when admin_adjustment records exist', function () {
    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'admin_adjustment',
        'amount' => 2,
        'balance_after' => 12,
        'idempotency_key' => 'rollback-guard-test-key',
        'description' => 'Guard test',
    ]);

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_10_26_080000_extend_credit_transactions_type_enum.php');

    expect(fn () => $migration->down())->toThrow(
        RuntimeException::class,
        'Cannot rollback migration: credit_transactions contains admin_adjustment records.'
    );
});

test('admin can grant positive credits to customer with ledger and audit log', function () {
    $idempotencyKey = 'adj-positive-'.uniqid();

    $response = $this->actingAs($this->admin)
        ->from(route('admin.customers.show', $this->customer))
        ->post(route('admin.customers.credits.adjust', $this->customer), [
            'delta' => 5,
            'reason' => 'Compensation for service outage incident #102',
            'idempotency_key' => $idempotencyKey,
        ]);

    $response->assertRedirect(route('admin.customers.show', $this->customer));
    $response->assertSessionHas('success');

    // Balance updated
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(15);

    // Ledger entry recorded
    $ledger = CreditTransaction::where('idempotency_key', $idempotencyKey)->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->user_id)->toBe($this->customer->id);
    expect($ledger->type)->toBe('admin_adjustment');
    expect($ledger->amount)->toBe(5);
    expect($ledger->balance_after)->toBe(15);
    expect($ledger->description)->toBe('Compensation for service outage incident #102');

    // Audit log entry recorded
    $audit = AdminActivityLog::where('action', 'credits.adjusted')
        ->where('target_id', $this->customer->id)
        ->latest('id')
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->admin_id)->toBe($this->admin->id);
    expect($audit->outcome)->toBe('success');
    expect($audit->metadata['delta'])->toBe(5);
    expect($audit->metadata['balance_before'])->toBe(10);
    expect($audit->metadata['balance_after'])->toBe(15);
    expect($audit->metadata['idempotency_key'])->toBe($idempotencyKey);
});

test('admin can deduct credits from customer', function () {
    $idempotencyKey = 'adj-deduct-'.uniqid();

    $response = $this->actingAs($this->admin)
        ->from(route('admin.customers.show', $this->customer))
        ->post(route('admin.customers.credits.adjust', $this->customer), [
            'delta' => -4,
            'reason' => 'Administrative reversal of accidental duplicate grant',
            'idempotency_key' => $idempotencyKey,
        ]);

    $response->assertRedirect(route('admin.customers.show', $this->customer));

    $this->customer->refresh();
    expect($this->customer->credits)->toBe(6);

    $ledger = CreditTransaction::where('idempotency_key', $idempotencyKey)->first();
    expect($ledger)->not->toBeNull();
    expect($ledger->amount)->toBe(-4);
    expect($ledger->balance_after)->toBe(6);

    $audit = AdminActivityLog::where('action', 'credits.adjusted')
        ->where('target_id', $this->customer->id)
        ->latest('id')
        ->first();

    expect($audit->metadata['balance_before'])->toBe(10);
    expect($audit->metadata['balance_after'])->toBe(6);
});

test('credit deduction exceeding balance is rejected and balance is unchanged', function () {
    $idempotencyKey = 'adj-overdraw-'.uniqid();

    $response = $this->actingAs($this->admin)
        ->from(route('admin.customers.show', $this->customer))
        ->post(route('admin.customers.credits.adjust', $this->customer), [
            'delta' => -15, // customer only has 10 credits
            'reason' => 'Overdraft attempt should be rejected',
            'idempotency_key' => $idempotencyKey,
        ]);

    $response->assertSessionHasErrors(['delta']);

    $this->customer->refresh();
    expect($this->customer->credits)->toBe(10);

    // No ledger or audit entry
    expect(CreditTransaction::where('idempotency_key', $idempotencyKey)->count())->toBe(0);
    expect(AdminActivityLog::where('action', 'credits.adjusted')->where('target_id', $this->customer->id)->count())->toBe(0);
});

test('zero delta and invalid reason are rejected by validation', function () {
    // Delta = 0
    $this->actingAs($this->admin)
        ->post(route('admin.customers.credits.adjust', $this->customer), [
            'delta' => 0,
            'reason' => 'Valid reason here',
            'idempotency_key' => 'zero-delta-key',
        ])
        ->assertSessionHasErrors(['delta']);

    // Reason too short (< 5 chars)
    $this->actingAs($this->admin)
        ->post(route('admin.customers.credits.adjust', $this->customer), [
            'delta' => 2,
            'reason' => 'abc',
            'idempotency_key' => 'short-reason-key',
        ])
        ->assertSessionHasErrors(['reason']);

    // Missing idempotency key
    $this->actingAs($this->admin)
        ->post(route('admin.customers.credits.adjust', $this->customer), [
            'delta' => 2,
            'reason' => 'Valid reason here',
        ])
        ->assertSessionHasErrors(['idempotency_key']);
});

test('sequential identical request returns existing transaction without duplicate balance mutation or audit log', function () {
    $idempotencyKey = 'seq-idemp-'.uniqid();
    $payload = [
        'delta' => 3,
        'reason' => 'Identical sequential request test',
        'idempotency_key' => $idempotencyKey,
    ];

    // First request: grants +3
    $firstResponse = $this->actingAs($this->admin)
        ->from(route('admin.customers.show', $this->customer))
        ->post(route('admin.customers.credits.adjust', $this->customer), $payload);

    $firstResponse->assertRedirect(route('admin.customers.show', $this->customer));

    $this->customer->refresh();
    expect($this->customer->credits)->toBe(13);
    expect(CreditTransaction::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
    expect(AdminActivityLog::where('action', 'credits.adjusted')->where('target_id', $this->customer->id)->count())->toBe(1);

    // Second request: identical payload with same idempotency key
    $secondResponse = $this->actingAs($this->admin)
        ->from(route('admin.customers.show', $this->customer))
        ->post(route('admin.customers.credits.adjust', $this->customer), $payload);

    $secondResponse->assertRedirect(route('admin.customers.show', $this->customer));

    // Balance MUST still be 13, not 16
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(13);

    // Still exactly 1 ledger record and 1 audit log
    expect(CreditTransaction::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
    expect(AdminActivityLog::where('action', 'credits.adjusted')->where('target_id', $this->customer->id)->count())->toBe(1);
});

test('reusing idempotency key with conflicting payload aborts with 409 conflict', function () {
    $idempotencyKey = 'conflict-key-'.uniqid();

    // 1. Initial successful adjustment (+2)
    $this->actingAs($this->admin)->post(route('admin.customers.credits.adjust', $this->customer), [
        'delta' => 2,
        'reason' => 'Initial credit adjustment',
        'idempotency_key' => $idempotencyKey,
    ])->assertSessionHasNoErrors();

    $this->customer->refresh();
    expect($this->customer->credits)->toBe(12);

    // 2. Reusing same idempotency key with different delta (+4)
    $responseConflictDelta = $this->actingAs($this->admin)->post(route('admin.customers.credits.adjust', $this->customer), [
        'delta' => 4, // different delta!
        'reason' => 'Initial credit adjustment',
        'idempotency_key' => $idempotencyKey,
    ]);

    $responseConflictDelta->assertStatus(409);

    // 3. Reusing same idempotency key with different reason
    $responseConflictReason = $this->actingAs($this->admin)->post(route('admin.customers.credits.adjust', $this->customer), [
        'delta' => 2,
        'reason' => 'Different reason provided', // different reason!
        'idempotency_key' => $idempotencyKey,
    ]);

    $responseConflictReason->assertStatus(409);

    // 4. Reusing same idempotency key on a different customer
    $otherCustomer = User::factory()->create(['role' => 'user', 'credits' => 5]);
    $responseConflictUser = $this->actingAs($this->admin)->post(route('admin.customers.credits.adjust', $otherCustomer), [
        'delta' => 2,
        'reason' => 'Initial credit adjustment',
        'idempotency_key' => $idempotencyKey,
    ]);

    $responseConflictUser->assertStatus(409);

    // Balances remain intact
    $this->customer->refresh();
    $otherCustomer->refresh();
    expect($this->customer->credits)->toBe(12);
    expect($otherCustomer->credits)->toBe(5);
});

test('adjustment is atomic: failure in audit step rolls back user balance and ledger', function () {
    $mockAudit = Mockery::mock(AdminAuditService::class);
    $mockAudit->shouldReceive('log')
        ->andThrow(new RuntimeException('Simulated audit service crash during transaction'));

    $creditService = new CreditService($mockAudit);

    $idempotencyKey = 'atomicity-fail-key-'.uniqid();

    expect(function () use ($creditService, $idempotencyKey) {
        $creditService->adjustCredits(
            admin: $this->admin,
            customer: $this->customer,
            delta: 5,
            reason: 'Atomicity failure verification',
            idempotencyKey: $idempotencyKey,
        );
    })->toThrow(RuntimeException::class, 'Simulated audit service crash during transaction');

    // User credits must NOT have changed
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(10);

    // Credit ledger must NOT have any record
    expect(CreditTransaction::where('idempotency_key', $idempotencyKey)->count())->toBe(0);
});

test('admin can suspend an active customer with required reason and audit trail', function () {
    $response = $this->actingAs($this->admin)
        ->from(route('admin.customers.show', $this->customer))
        ->post(route('admin.customers.suspend', $this->customer), [
            'reason' => 'Chargeback dispute filed with payment processor',
        ]);

    $response->assertRedirect(route('admin.customers.show', $this->customer));
    $response->assertSessionHas('success');

    $this->customer->refresh();
    expect($this->customer->is_suspended)->toBeTrue();
    expect($this->customer->suspended_reason)->toBe('Chargeback dispute filed with payment processor');
    expect($this->customer->suspended_by)->toBe($this->admin->id);
    expect($this->customer->suspended_at)->not->toBeNull();

    // Audit log
    $audit = AdminActivityLog::where('action', 'customer.suspended')
        ->where('target_id', $this->customer->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->admin_id)->toBe($this->admin->id);
    expect($audit->reason)->toBe('Chargeback dispute filed with payment processor');
    expect($audit->outcome)->toBe('success');
});

test('admin can reactivate a suspended customer with audit trail', function () {
    $this->customer->update([
        'is_suspended' => true,
        'suspended_at' => now(),
        'suspended_reason' => 'Temporary suspension pending KYC verification',
        'suspended_by' => $this->admin->id,
    ]);

    $response = $this->actingAs($this->admin)
        ->from(route('admin.customers.show', $this->customer))
        ->post(route('admin.customers.reactivate', $this->customer), [
            'reason' => 'Identity documentation confirmed and verified',
        ]);

    $response->assertRedirect(route('admin.customers.show', $this->customer));
    $response->assertSessionHas('success');

    $this->customer->refresh();
    expect($this->customer->is_suspended)->toBeFalse();
    expect($this->customer->suspended_reason)->toBeNull();
    expect($this->customer->suspended_by)->toBeNull();
    expect($this->customer->suspended_at)->toBeNull();

    // Audit log
    $audit = AdminActivityLog::where('action', 'customer.reactivated')
        ->where('target_id', $this->customer->id)
        ->first();

    expect($audit)->not->toBeNull();
    expect($audit->admin_id)->toBe($this->admin->id);
    expect($audit->reason)->toBe('Identity documentation confirmed and verified');
    expect($audit->metadata['previous_suspended_reason'])->toBe('Temporary suspension pending KYC verification');
});

test('cannot suspend already suspended customer or reactivate active customer', function () {
    // 1. Suspend already suspended customer
    $this->customer->update(['is_suspended' => true, 'suspended_reason' => 'Already suspended']);

    $this->actingAs($this->admin)
        ->post(route('admin.customers.suspend', $this->customer), [
            'reason' => 'Attempting duplicate suspend',
        ])
        ->assertSessionHasErrors(['user']);

    // 2. Reactivate active customer
    $this->customer->update(['is_suspended' => false, 'suspended_reason' => null]);

    $this->actingAs($this->admin)
        ->post(route('admin.customers.reactivate', $this->customer), [
            'reason' => 'Attempting invalid reactivate',
        ])
        ->assertSessionHasErrors(['user']);

    // 3. Admin cannot suspend self
    $this->actingAs($this->admin)
        ->post(route('admin.customers.suspend', $this->admin), [
            'reason' => 'Attempting self-suspension',
        ])
        ->assertSessionHasErrors(['user']);
});

test('non-admin regular user cannot execute customer operations and gets 403 forbidden', function () {
    $regularUser = User::factory()->create([
        'role' => 'user',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($regularUser)
        ->post(route('admin.customers.credits.adjust', $this->customer), [
            'delta' => 5,
            'reason' => 'Unauthorized attempt',
            'idempotency_key' => 'unauth-key',
        ])
        ->assertForbidden();

    $this->actingAs($regularUser)
        ->post(route('admin.customers.suspend', $this->customer), [
            'reason' => 'Unauthorized attempt',
        ])
        ->assertForbidden();

    $this->actingAs($regularUser)
        ->post(route('admin.customers.reactivate', $this->customer), [
            'reason' => 'Unauthorized attempt',
        ])
        ->assertForbidden();
});

test('admin cannot suspend or reactivate another admin or super admin via customer endpoint', function () {
    $fellowAdmin = User::factory()->create([
        'role' => 'admin',
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);

    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);

    // 1. Admin cannot suspend fellow admin
    $this->actingAs($this->admin)
        ->post(route('admin.customers.suspend', $fellowAdmin), [
            'reason' => 'Attempting to suspend another admin',
        ])
        ->assertSessionHasErrors(['user']);

    $fellowAdmin->refresh();
    expect($fellowAdmin->is_suspended)->toBeFalse();

    // 2. Admin cannot suspend super admin
    $this->actingAs($this->admin)
        ->post(route('admin.customers.suspend', $superAdmin), [
            'reason' => 'Attempting to suspend super admin',
        ])
        ->assertSessionHasErrors(['user']);

    $superAdmin->refresh();
    expect($superAdmin->is_suspended)->toBeFalse();

    // 3. Admin cannot reactivate an admin or super admin
    $fellowAdmin->update(['is_suspended' => true, 'suspended_reason' => 'Testing']);
    $this->actingAs($this->admin)
        ->post(route('admin.customers.reactivate', $fellowAdmin), [
            'reason' => 'Attempting to reactivate admin',
        ])
        ->assertSessionHasErrors(['user']);

    $superAdmin->update(['is_suspended' => true, 'suspended_reason' => 'Testing']);
    $this->actingAs($this->admin)
        ->post(route('admin.customers.reactivate', $superAdmin), [
            'reason' => 'Attempting to reactivate super admin',
        ])
        ->assertSessionHasErrors(['user']);

    // 4. Admin cannot adjust credits on fellow admin or super admin
    $this->actingAs($this->admin)
        ->post(route('admin.customers.credits.adjust', $fellowAdmin), [
            'delta' => 5,
            'reason' => 'Attempting to adjust fellow admin credits',
            'idempotency_key' => 'admin-credit-adj-'.uniqid(),
        ])
        ->assertSessionHasErrors(['user']);

    $this->actingAs($this->admin)
        ->post(route('admin.customers.credits.adjust', $superAdmin), [
            'delta' => 5,
            'reason' => 'Attempting to adjust super admin credits',
            'idempotency_key' => 'superadmin-credit-adj-'.uniqid(),
        ])
        ->assertSessionHasErrors(['user']);
});

test('suspend is atomic: failure in audit step rolls back user suspension', function () {
    $mockAudit = Mockery::mock(AdminAuditService::class);
    $mockAudit->shouldReceive('log')
        ->andThrow(new RuntimeException('Simulated audit crash on suspend'));

    $this->app->instance(AdminAuditService::class, $mockAudit);

    $this->withoutExceptionHandling();

    expect(function () {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.suspend', $this->customer), [
                'reason' => 'Audit failure rollback test',
            ]);
    })->toThrow(RuntimeException::class, 'Simulated audit crash on suspend');

    $this->customer->refresh();
    expect($this->customer->is_suspended)->toBeFalse();
    expect($this->customer->suspended_reason)->toBeNull();
    expect($this->customer->suspended_by)->toBeNull();
    expect($this->customer->suspended_at)->toBeNull();
});

test('reactivate is atomic: failure in audit step rolls back user reactivation', function () {
    $this->customer->update([
        'is_suspended' => true,
        'suspended_at' => now(),
        'suspended_reason' => 'Pre-existing reason',
        'suspended_by' => $this->admin->id,
    ]);

    $mockAudit = Mockery::mock(AdminAuditService::class);
    $mockAudit->shouldReceive('log')
        ->andThrow(new RuntimeException('Simulated audit crash on reactivate'));

    $this->app->instance(AdminAuditService::class, $mockAudit);

    $this->withoutExceptionHandling();

    expect(function () {
        $this->actingAs($this->admin)
            ->post(route('admin.customers.reactivate', $this->customer), [
                'reason' => 'Audit failure rollback test',
            ]);
    })->toThrow(RuntimeException::class, 'Simulated audit crash on reactivate');

    $this->customer->refresh();
    expect($this->customer->is_suspended)->toBeTrue();
    expect($this->customer->suspended_reason)->toBe('Pre-existing reason');
    expect($this->customer->suspended_by)->toBe($this->admin->id);
    expect($this->customer->suspended_at)->not->toBeNull();
});
