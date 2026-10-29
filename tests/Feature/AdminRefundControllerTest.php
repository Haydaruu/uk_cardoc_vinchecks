<?php

use App\Models\CreditTransaction;
use App\Models\GuestReportPurchase;
use App\Models\RefundRecord;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);

    $this->superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);

    $this->customer = User::factory()->create([
        'role' => 'user',
        'credits' => 5,
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);
});

test('unauthenticated user cannot access refund endpoints', function () {
    $uuid = (string) Str::uuid();

    $this->post(route('admin.transactions.refund', 1), [
        'reason' => 'Valid refund reason',
        'idempotency_key' => $uuid,
    ])->assertRedirect(route('login'));

    $this->post(route('admin.guest-reports.refund', 1), [
        'reason' => 'Valid refund reason',
        'idempotency_key' => $uuid,
    ])->assertRedirect(route('login'));
});

test('regular customer receives 403 on refund endpoints', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-FORBID-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'status' => 'success',
    ]);

    $vehicle = Vehicle::create(['registration_number' => 'FORBID-01']);
    $report = Report::create(['vehicle_id' => $vehicle->id, 'report_type' => 'basic']);
    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Guest',
        'email' => 'guest@example.com',
        'amount_minor' => 1499,
        'currency' => 'gbp',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
    ]);

    $uuid = (string) Str::uuid();

    $this->actingAs($this->customer)
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'Valid refund reason',
            'idempotency_key' => $uuid,
        ])->assertForbidden();

    $this->actingAs($this->customer)
        ->post(route('admin.guest-reports.refund', $purchase), [
            'reason' => 'Valid refund reason',
            'idempotency_key' => $uuid,
        ])->assertForbidden();
});

test('refund validation rejects invalid reason and non-uuid idempotency keys', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-VAL-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_val_001',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    // 1. Missing reason & invalid UUID
    $response = $this->actingAs($this->admin)
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'shrt', // < 5 chars
            'idempotency_key' => 'not-a-valid-uuid',
        ]);

    $response->assertSessionHasErrors(['reason', 'idempotency_key']);

    // 2. Missing idempotency_key
    $response2 = $this->actingAs($this->admin)
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'Valid refund reason',
        ]);

    $response2->assertSessionHasErrors(['idempotency_key']);
});

test('refund returns 404 for non-existent transaction or guest purchase', function () {
    $uuid = (string) Str::uuid();

    $this->actingAs($this->admin)
        ->post(route('admin.transactions.refund', 999999), [
            'reason' => 'Valid refund reason',
            'idempotency_key' => $uuid,
        ])->assertNotFound();

    $this->actingAs($this->admin)
        ->post(route('admin.guest-reports.refund', 999999), [
            'reason' => 'Valid refund reason',
            'idempotency_key' => $uuid,
        ])->assertNotFound();
});

test('admin can refund registered transaction with credit deduction and audit log', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-REF-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_mock_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_mock_001',
        'created_at' => now()->subDay(),
    ]);

    $uuid = (string) Str::uuid();

    $mockRefunds = Mockery::mock();
    $mockRefunds->shouldReceive('create')
        ->once()
        ->with(
            Mockery::subset([
                'payment_intent' => 'pi_mock_001',
                'reason' => 'requested_by_customer',
            ]),
            Mockery::on(fn ($opts) => ($opts['idempotency_key'] ?? null) === $uuid)
        )
        ->andReturn((object) ['id' => 're_mock_success_001']);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->refunds = $mockRefunds;
    $this->app->instance(StripeClient::class, $mockStripe);

    $response = $this->actingAs($this->admin)
        ->from(route('admin.transactions.index'))
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'Customer requested refund within cooling-off period',
            'idempotency_key' => $uuid,
        ]);

    $response->assertRedirect(route('admin.transactions.index'));
    $response->assertSessionHas('success');

    // Customer balance updated
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(0);

    // Transaction updated
    $tx->refresh();
    expect($tx->status)->toBe('refunded')
        ->and($tx->refunded_at)->not->toBeNull();

    // RefundRecord created
    $record = RefundRecord::where('idempotency_key', $uuid)->first();
    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('success')
        ->and($record->refund_gateway_ref)->toBe('re_mock_success_001');

    // Audit log created
    $this->assertDatabaseHas('admin_activity_logs', [
        'action' => 'transaction.refunded',
        'target_type' => 'transaction',
        'target_id' => $tx->id,
        'outcome' => 'success',
    ]);
});

test('refund rejects ineligible transaction fail-closed', function () {
    $this->customer->update(['credits' => 10]);

    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-INEL-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_inel_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 10,
        'reference_id' => 'pi_inel_001',
        'created_at' => now()->subDay(),
    ]);

    // Record credit usage after purchase, balance remains 9 (>= 5)
    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'usage',
        'amount' => -1,
        'balance_after' => 9,
        'created_at' => now()->subHours(2),
    ]);
    $this->customer->update(['credits' => 9]);

    $uuid = (string) Str::uuid();

    $response = $this->actingAs($this->admin)
        ->from(route('admin.transactions.index'))
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'Customer tried to refund after consuming credits',
            'idempotency_key' => $uuid,
        ]);

    $response->assertSessionHasErrors(['refund']);
    $errors = session('errors')->get('refund');
    expect($errors[0])->toContain('Credits from this purchase have already been utilized');

    // Customer balance unchanged
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(9);
});

test('admin can refund failed guest report purchase', function () {
    $vehicle = Vehicle::create(['registration_number' => 'GST-REF-01']);
    $report = Report::create(['vehicle_id' => $vehicle->id, 'report_type' => 'basic']);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Guest Jane',
        'email' => 'jane@example.com',
        'amount_minor' => 1499,
        'currency' => 'gbp',
        'payment_provider' => 'stripe',
        'provider_payment_id' => 'pi_guest_001',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
        'paid_at' => now()->subHour(),
    ]);

    $uuid = (string) Str::uuid();

    $mockRefunds = Mockery::mock();
    $mockRefunds->shouldReceive('create')
        ->once()
        ->andReturn((object) ['id' => 're_guest_success_001']);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->refunds = $mockRefunds;
    $this->app->instance(StripeClient::class, $mockStripe);

    $response = $this->actingAs($this->superAdmin)
        ->from(route('admin.transactions.index'))
        ->post(route('admin.guest-reports.refund', $purchase), [
            'reason' => 'Permanent API failure for guest lookup',
            'idempotency_key' => $uuid,
        ]);

    $response->assertRedirect(route('admin.transactions.index'));
    $response->assertSessionHas('success');

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_REFUNDED)
        ->and($purchase->refunded_at)->not->toBeNull();
});

test('idempotency replay returns success without duplicate ledger mutation', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-IDEM-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_idem_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_idem_001',
        'created_at' => now()->subDay(),
    ]);

    $uuid = (string) Str::uuid();

    $mockRefunds = Mockery::mock();
    $mockRefunds->shouldReceive('create')
        ->once() // ONLY called once!
        ->andReturn((object) ['id' => 're_idem_001']);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->refunds = $mockRefunds;
    $this->app->instance(StripeClient::class, $mockStripe);

    // First call
    $this->actingAs($this->admin)
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'First attempt',
            'idempotency_key' => $uuid,
        ])->assertSessionHas('success');

    // Second call: replay
    $this->actingAs($this->admin)
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'First attempt',
            'idempotency_key' => $uuid,
        ])->assertSessionHas('success');

    // Ledgers check: exactly one hold row
    expect(CreditTransaction::where('type', 'refund')->count())->toBe(1);
    expect(RefundRecord::where('idempotency_key', $uuid)->count())->toBe(1);
});

test('idempotency key collision on different transaction returns 409', function () {
    $uuid = (string) Str::uuid();

    RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => 101,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_coll_101',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => 'success',
        'idempotency_key' => $uuid,
        'reason' => 'Previous refund on different transaction',
    ]);

    $tx2 = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-COLL-002',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_coll_002',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.transactions.refund', $tx2), [
            'reason' => 'Collision attempt',
            'idempotency_key' => $uuid,
        ])->assertStatus(409);
});

test('gateway failure returns error response and reverses hold', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-FAIL-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_fail_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_fail_001',
        'created_at' => now()->subDay(),
    ]);

    $uuid = (string) Str::uuid();

    $mockRefunds = Mockery::mock();
    $exception = InvalidRequestException::factory(
        'Charge has already been refunded.',
        400,
        null,
        null,
        'charge_already_refunded'
    );
    $mockRefunds->shouldReceive('create')->once()->andThrow($exception);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->refunds = $mockRefunds;
    $this->app->instance(StripeClient::class, $mockStripe);

    $response = $this->actingAs($this->admin)
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'Will fail at gateway',
            'idempotency_key' => $uuid,
        ]);

    $response->assertSessionHasErrors(['refund']);
    $response->assertSessionHas('refund_status', 'failed');

    // Customer balance restored
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(5);

    // Reversal row created
    expect(CreditTransaction::where('reference_id', 'like', 'refund_reversal:%')->count())->toBe(1);
});

test('gateway timeout returns warning response and maintains hold', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-TIME-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_time_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_time_001',
        'created_at' => now()->subDay(),
    ]);

    $uuid = (string) Str::uuid();

    $mockRefunds = Mockery::mock();
    // Simulate network timeout (RuntimeException, code 0)
    $mockRefunds->shouldReceive('create')->once()->andThrow(new RuntimeException('Connection timed out'));

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->refunds = $mockRefunds;
    $this->app->instance(StripeClient::class, $mockStripe);

    $response = $this->actingAs($this->admin)
        ->post(route('admin.transactions.refund', $tx), [
            'reason' => 'Network timeout attempt',
            'idempotency_key' => $uuid,
        ]);

    $response->assertSessionHas('warning');
    $response->assertSessionHas('refund_status', 'unknown');

    // Hold is MAINTAINED: customer credits remain decremented to 0
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(0);

    // No reversal row
    expect(CreditTransaction::where('reference_id', 'like', 'refund_reversal:%')->count())->toBe(0);

    // Status is unknown
    $record = RefundRecord::where('idempotency_key', $uuid)->first();
    expect($record->status)->toBe('unknown');
});
