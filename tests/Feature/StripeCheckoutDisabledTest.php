<?php

use App\Models\Report;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.stripe.checkout_enabled' => false,
    ]);
});

test('Stripe one-time checkout is disabled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('checkout.create-intent'), [
            'plan' => '1-credit',
        ])
        ->assertStatus(503)
        ->assertJson([
            'message' => 'Card payments are temporarily unavailable. Please use PayPal.',
        ]);
});

test('Stripe subscription checkout is disabled', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('checkout.create-subscription-intent'), [
            'plan' => 'premium',
        ])
        ->assertStatus(503)
        ->assertJson([
            'message' => 'Card payments are temporarily unavailable. Please use PayPal.',
        ]);
});

test('Stripe guest report checkout is disabled', function () {
    $vehicle = Vehicle::create([
        'registration_number' => 'STRIPEOFF001',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    $this->from('/')
        ->post(route('guest-report.checkout', [
            'report' => $report->id,
        ]))
        ->assertRedirect('/')
        ->assertSessionHasErrors('checkout');
});
