<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VinCheck;

test('User::canPerformCheck returns false when user is suspended even with credits', function () {
    $activeUserWithCredits = User::factory()->create([
        'credits' => 5,
        'is_suspended' => false,
    ]);
    expect($activeUserWithCredits->canPerformCheck())->toBeTrue();

    $activeUserNoCredits = User::factory()->create([
        'credits' => 0,
        'is_suspended' => false,
    ]);
    expect($activeUserNoCredits->canPerformCheck())->toBeFalse();

    $suspendedUserWithCredits = User::factory()->create([
        'credits' => 5,
        'is_suspended' => true,
        'suspended_reason' => 'Fraud investigation',
    ]);
    expect($suspendedUserWithCredits->canPerformCheck())->toBeFalse();

    $suspendedUserNoCredits = User::factory()->create([
        'credits' => 0,
        'is_suspended' => true,
    ]);
    expect($suspendedUserNoCredits->canPerformCheck())->toBeFalse();
});

test('suspended customer can log in and view historical reports and purchase history', function () {
    $suspendedUser = User::factory()->create([
        'is_suspended' => true,
        'suspended_reason' => 'Account under review',
        'credits' => 3,
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'SUSP-01',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $suspendedUser->id,
        'registration_number' => 'SUSP-01',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'user_id' => $suspendedUser->id,
        'vin_check_id' => $vinCheck->id,
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
        'report_data' => ['make' => 'BMW', 'model' => '3 Series'],
    ]);

    $transaction = Transaction::create([
        'user_id' => $suspendedUser->id,
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'status' => 'success',
    ]);

    // Can access read-only dashboard
    $this->actingAs($suspendedUser)
        ->get(route('user.dashboard'))
        ->assertOk();

    // Can access my-report listing
    $this->actingAs($suspendedUser)
        ->get(route('page.my-report'))
        ->assertOk();

    // Can access historical report show page
    $this->actingAs($suspendedUser)
        ->get(route('page.my-report.show', $report->id))
        ->assertOk();

    // Can access purchase history
    $this->actingAs($suspendedUser)
        ->get(route('settings.purchase-history'))
        ->assertOk();

    // Can view past invoice
    $this->actingAs($suspendedUser)
        ->get(route('invoice.show', $transaction->id))
        ->assertOk();
});

test('suspended customer is blocked from checkout and payment mutation endpoints', function () {
    $suspendedUser = User::factory()->create([
        'is_suspended' => true,
        'suspended_reason' => 'Account suspended by admin',
    ]);

    // Blocked from checkout page
    $this->actingAs($suspendedUser)
        ->get(route('checkout.show'))
        ->assertForbidden();

    // Blocked from creating Stripe one-time payment intent
    $this->actingAs($suspendedUser)
        ->postJson(route('checkout.create-intent'), ['plan_key' => '1-credit'])
        ->assertForbidden();

    // Blocked from creating Stripe subscription intent
    $this->actingAs($suspendedUser)
        ->postJson(route('checkout.create-subscription-intent'), ['plan_key' => 'premium-monthly'])
        ->assertForbidden();

    // Blocked from creating PayPal order
    $this->actingAs($suspendedUser)
        ->postJson(route('checkout.paypal.create-order'), ['plan_key' => '1-credit'])
        ->assertForbidden();

    // Blocked from changing subscription plan
    $this->actingAs($suspendedUser)
        ->patchJson(route('settings.subscription.plan.change'), ['plan_key' => 'premium-plus-monthly'])
        ->assertForbidden();
});

test('suspended customer is blocked from performing vehicle checks and unlocking reports', function () {
    $suspendedUser = User::factory()->create([
        'is_suspended' => true,
        'credits' => 10,
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'CHECK-99',
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $suspendedUser->id,
        'registration_number' => 'CHECK-99',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'user_id' => $suspendedUser->id,
        'vin_check_id' => $vinCheck->id,
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
        'report_data' => ['make' => 'Audi', 'model' => 'A4'],
    ]);

    // Blocked from starting a new vehicle check
    $this->actingAs($suspendedUser)
        ->post(route('vehicle-check.store'), ['registration_number' => 'CHECK-99'])
        ->assertForbidden();

    // Blocked from unlocking report using credit
    $this->actingAs($suspendedUser)
        ->post(route('report.unlock', $report->id))
        ->assertForbidden();
});

test('EnsureUserNotSuspended middleware responds with 403 across Inertia, JSON, and standard HTTP requests', function () {
    $suspendedUser = User::factory()->create([
        'is_suspended' => true,
    ]);

    // 1. JSON Request
    $jsonResponse = $this->actingAs($suspendedUser)
        ->postJson(route('checkout.create-intent'), ['plan_key' => '1-credit']);
    $jsonResponse->assertStatus(403);
    $jsonResponse->assertJson([
        'message' => 'Your account is currently suspended. You cannot perform this action.',
    ]);

    // 2. Inertia POST mutation request (e.g. form submission from frontend)
    $inertiaPostResponse = $this->actingAs($suspendedUser)
        ->post(route('vehicle-check.store'), ['registration_number' => 'CHECK-99'], ['X-Inertia' => 'true']);
    $inertiaPostResponse->assertStatus(403);

    // 3. Inertia GET page visit with matching asset version
    $version = app(HandleInertiaRequests::class)->version(request());
    $inertiaGetResponse = $this->actingAs($suspendedUser)
        ->get(route('checkout.show'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version ?? '',
        ]);
    $inertiaGetResponse->assertStatus(403);

    // 4. Standard Web/HTML Request
    $webResponse = $this->actingAs($suspendedUser)
        ->get(route('checkout.show'));
    $webResponse->assertStatus(403);
});
