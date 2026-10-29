<?php

use App\Models\AdminActivityLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);
});

test('unauthenticated user and regular customer cannot cancel subscription', function () {
    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'monthly_standard',
        'price' => 19.99,
        'payment_method' => 'stripe',
        'stripe_subscription_id' => 'sub_stripe_001',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'current_period_end' => now()->addDays(15),
    ]);

    // Guest -> redirect login
    $this->post(route('admin.subscriptions.cancel', $sub), [
        'reason' => 'Customer request',
    ])->assertRedirect(route('login'));

    // Regular customer -> 403
    $this->actingAs($this->customer)
        ->post(route('admin.subscriptions.cancel', $sub), [
            'reason' => 'Customer request',
        ])->assertForbidden();
});

test('cancellation validation requires reason with at least 5 characters', function () {
    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'monthly_standard',
        'price' => 19.99,
        'payment_method' => 'stripe',
        'stripe_subscription_id' => 'sub_stripe_val',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'current_period_end' => now()->addDays(15),
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.subscriptions.cancel', $sub), [
            'reason' => 'shrt',
        ])->assertSessionHasErrors(['reason']);

    $this->actingAs($this->admin)
        ->post(route('admin.subscriptions.cancel', $sub), [])
        ->assertSessionHasErrors(['reason']);
});

test('cancellation returns 404 for non-existent subscription', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.subscriptions.cancel', 999999), [
            'reason' => 'Valid cancellation reason',
        ])->assertNotFound();
});

test('admin can cancel active stripe subscription at period end with audit log', function () {
    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'monthly_standard',
        'price' => 19.99,
        'payment_method' => 'stripe',
        'stripe_subscription_id' => 'sub_stripe_real_001',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'current_period_end' => now()->addDays(15),
        'cancel_at_period_end' => false,
    ]);

    $mockSubs = Mockery::mock();
    $mockSubs->shouldReceive('update')
        ->once()
        ->with('sub_stripe_real_001', ['cancel_at_period_end' => true])
        ->andReturn((object) [
            'id' => 'sub_stripe_real_001',
            'cancel_at_period_end' => true,
            'status' => 'active',
        ]);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->subscriptions = $mockSubs;
    $this->app->instance(StripeClient::class, $mockStripe);

    $response = $this->actingAs($this->admin)
        ->from(route('admin.subscriptions.index'))
        ->post(route('admin.subscriptions.cancel', $sub), [
            'reason' => 'Customer requested cancellation via support ticket #450',
        ]);

    $response->assertRedirect(route('admin.subscriptions.index'));
    $response->assertSessionHas('success');

    $sub->refresh();
    expect($sub->cancel_at_period_end)->toBeTrue()
        ->and($sub->cancelled_at)->not->toBeNull();

    // Check audit log
    $this->assertDatabaseHas('admin_activity_logs', [
        'action' => 'subscription.cancelled',
        'target_type' => 'subscription',
        'target_id' => $sub->id,
        'outcome' => 'success',
        'admin_id' => $this->admin->id,
    ]);
});

test('admin can cancel active paypal subscription with audit log', function () {
    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'monthly_pro',
        'price' => 29.99,
        'payment_method' => 'paypal',
        'paypal_subscription_id' => 'I-PPL-SUB-001',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'current_period_end' => now()->addDays(20),
        'cancel_at_period_end' => false,
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getSubscription')
        ->with('I-PPL-SUB-001')
        ->andReturn(['status' => 'ACTIVE']);
    $mockPayPal->shouldReceive('cancelSubscription')
        ->once()
        ->with('I-PPL-SUB-001');

    $this->app->instance(PayPalService::class, $mockPayPal);

    $response = $this->actingAs($this->superAdmin)
        ->from(route('admin.subscriptions.index'))
        ->post(route('admin.subscriptions.cancel', $sub), [
            'reason' => 'Customer decided to discontinue service',
        ]);

    $response->assertRedirect(route('admin.subscriptions.index'));
    $response->assertSessionHas('success');

    $sub->refresh();
    expect($sub->cancel_at_period_end)->toBeTrue()
        ->and($sub->cancelled_at)->not->toBeNull();

    $this->assertDatabaseHas('admin_activity_logs', [
        'action' => 'subscription.cancelled',
        'target_type' => 'subscription',
        'target_id' => $sub->id,
        'outcome' => 'success',
        'admin_id' => $this->superAdmin->id,
    ]);
});

test('retry on already cancelled subscription is idempotent and does not create duplicate audit log', function () {
    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'monthly_standard',
        'price' => 19.99,
        'payment_method' => 'stripe',
        'stripe_subscription_id' => 'sub_stripe_retry_001',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'current_period_end' => now()->addDays(15),
        'cancel_at_period_end' => false,
    ]);

    $mockSubs = Mockery::mock();
    $mockSubs->shouldReceive('update')
        ->once() // ONLY called once across both attempts!
        ->andReturn((object) [
            'id' => 'sub_stripe_retry_001',
            'cancel_at_period_end' => true,
            'status' => 'active',
        ]);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->subscriptions = $mockSubs;
    $this->app->instance(StripeClient::class, $mockStripe);

    // 1st call
    $this->actingAs($this->admin)
        ->post(route('admin.subscriptions.cancel', $sub), [
            'reason' => 'First attempt',
        ])->assertSessionHas('success');

    // Exactly 1 audit log created
    expect(AdminActivityLog::where('action', 'subscription.cancelled')->where('target_id', $sub->id)->count())->toBe(1);

    // 2nd call: retry
    $this->actingAs($this->admin)
        ->post(route('admin.subscriptions.cancel', $sub), [
            'reason' => 'First attempt retry',
        ])->assertSessionHas('success');

    // Still exactly 1 audit log!
    expect(AdminActivityLog::where('action', 'subscription.cancelled')->where('target_id', $sub->id)->count())->toBe(1);
});

test('reconciliation: if subscription is already cancelled on provider, local DB updates and logs audit', function () {
    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'monthly_pro',
        'price' => 29.99,
        'payment_method' => 'paypal',
        'paypal_subscription_id' => 'I-PPL-ALREADY-001',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'current_period_end' => now()->addDays(10),
        'cancel_at_period_end' => false,
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    // Remote is already CANCELLED
    $mockPayPal->shouldReceive('getSubscription')
        ->with('I-PPL-ALREADY-001')
        ->andReturn(['status' => 'CANCELLED']);
    // cancelSubscription should NOT be called because it's already cancelled
    $mockPayPal->shouldNotReceive('cancelSubscription');

    $this->app->instance(PayPalService::class, $mockPayPal);

    $this->actingAs($this->admin)
        ->post(route('admin.subscriptions.cancel', $sub), [
            'reason' => 'Syncing already cancelled subscription',
        ])->assertSessionHas('success');

    $sub->refresh();
    expect($sub->cancel_at_period_end)->toBeTrue()
        ->and($sub->cancelled_at)->not->toBeNull();

    expect(AdminActivityLog::where('action', 'subscription.cancelled')->where('target_id', $sub->id)->count())->toBe(1);
});

test('gateway failure does not update local subscription state', function () {
    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'monthly_standard',
        'price' => 19.99,
        'payment_method' => 'stripe',
        'stripe_subscription_id' => 'sub_stripe_fail_001',
        'status' => 'active',
        'start_date' => now()->subMonth(),
        'current_period_end' => now()->addDays(15),
        'cancel_at_period_end' => false,
    ]);

    $mockSubs = Mockery::mock();
    $exception = InvalidRequestException::factory('Card authentication required', 400);
    $mockSubs->shouldReceive('update')->once()->andThrow($exception);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->subscriptions = $mockSubs;
    $this->app->instance(StripeClient::class, $mockStripe);

    $this->actingAs($this->admin)
        ->post(route('admin.subscriptions.cancel', $sub), [
            'reason' => 'Will fail at Stripe',
        ])->assertSessionHasErrors(['subscription']);

    $sub->refresh();
    expect($sub->cancel_at_period_end)->toBeFalse()
        ->and($sub->cancelled_at)->toBeNull();

    expect(AdminActivityLog::where('action', 'subscription.cancelled')->count())->toBe(0);
});
