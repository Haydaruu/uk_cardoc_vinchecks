<?php

use App\Models\GuestReportPurchase;
use App\Services\GuestReportCheckoutService;
use App\Services\GuestReportPaymentService;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Jobs\ProcessVehicleCheck;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

test('guest report purchases table has the expected columns', function () {
    expect(Schema::hasTable('guest_report_purchases'))->toBeTrue();

    expect(Schema::hasColumns('guest_report_purchases', [
        'id',
        'report_id',
        'email',
        'payment_provider',
        'payment_status',
        'amount_minor',
        'currency',
        'stripe_price_id',
        'provider_order_id',
        'provider_payment_id',
        'paid_at',
        'failed_at',
        'expired_at',
        'refunded_at',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

test('guest purchase and report are related in both directions', function () {
    $vehicle = Vehicle::create([
        'registration_number' => 'TEST-123',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
    ]);

    expect($purchase->report->is($report))->toBeTrue();

    expect($report->guestReportPurchases)->toHaveCount(1);

    expect(
        $report->guestReportPurchases->first()->is($purchase)
    )->toBeTrue();
});

test('guest checkout rejects a report that is not ready', function (){
    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-TEST-001',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    expect(fn () => app(GuestReportCheckoutService::class)
        ->startCheckout(
            report: $report,
            email: 'guest@example.com',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ))
        ->toThrow(RuntimeException::class, 'This report is not ready for a guest full-report purchase.');

    $reportUrl = route('page.my-report.show',[
        'report' => $report->id,
    ]);

    $response = $this->from($reportUrl)->post(
        route('guest-report.checkout', [
            'report' => $report->id,
        ]),
        [
            'email' => 'guest@example.com',
        ],
    );

    $response->assertRedirect($reportUrl);
    $response->assertSessionHasErrors('checkout');

    $this->assertDatabaseCount('guest_report_purchases', 0);
});

test('guest checkout rejects a report with an existing paid purchase', function() {
    $vinCheck = VinCheck::create([
        'registration_number' => 'GUEST-TEST-002',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-TEST-002',
    ]);

    $report = Report::create([
        'vin_check_id' => $vinCheck->id,
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
    ]);

    expect(fn () => app(GuestReportCheckoutService::class)
        ->startCheckout(
            report: $report,
            email: 'guest@example.com',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ))
        ->toThrow(RuntimeException::class, 'A successful purchase already exists for this report.');

    $reportUrl = route('page.my-report.show',[
        'report' => $report->id,
    ]);

    $response = $this->from($reportUrl)->post(
        route('guest-report.checkout', [
            'report' => $report->id,
        ]),
        [
            'email' => 'guest@example.com',
        ]
    );

    $response->assertRedirect($reportUrl);
    $response->assertSessionHasErrors('checkout');

    $this->assertDatabaseCount('guest_report_purchases', 1);
});


test('guest purchase becomes paid only when the Stripe amount and currency match', function () {
    Queue::fake();

    $vehicle = Vehicle::create([
        'registration_number' => 'PAYMENT-TEST-001',
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => 'PAYMENT-TEST-001',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
    ]);

    $session = (object) [
        'id' => 'cs_test_guest_001',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'amount_total' => 2000,
        'currency' => 'gbp',
        'payment_intent' => 'pi_test_guest_001',
        'metadata' => (object) [
            'purpose' => 'guest_report_purchase',
            'guest_report_purchase_id' => (string) $purchase->id,
            'report_id' => (string) $report->id,
        ],
    ];

    $becamePaid = app(GuestReportPaymentService::class)
        ->handleCheckoutSession(
            $session,
            'checkout.session.completed',
        );

    expect($becamePaid)->toBeTrue();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID);
    expect($purchase->provider_order_id)->toBe('cs_test_guest_001');
    expect($purchase->provider_payment_id)->toBe('pi_test_guest_001');
    expect($purchase->paid_at)->not->toBeNull();
    Queue::assertPushed(ProcessVehicleCheck::class);
    expect($purchase->fresh()->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
    
    
});

test('duplicate successful guest payment webhook does not reset paid time', function () {
    Queue::fake();
    $vehicle = Vehicle::create([
        'registration_number' => 'PAYMENT-TEST-002',
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => 'PAYMENT-TEST-002',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
    ]);

    $session = (object) [
        'id' => 'cs_test_guest_002',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'amount_total' => 2000,
        'currency' => 'gbp',
        'payment_intent' => 'pi_test_guest_002',
        'metadata' => (object) [
            'purpose' => 'guest_report_purchase',
            'guest_report_purchase_id' => (string) $purchase->id,
            'report_id' => (string) $report->id,
        ],
    ];

    $service = app(GuestReportPaymentService::class);

    expect($service->handleCheckoutSession(
        $session,
        'checkout.session.completed',
    ))->toBeTrue();

    $originalPaidAt = $purchase->fresh()->paid_at;

    expect($service->handleCheckoutSession(
        $session,
        'checkout.session.completed',
    ))->toBeFalse();

    expect($purchase->fresh()->payment_status)->toBe(GuestReportPurchase::STATUS_PAID);
    expect($purchase->fresh()->paid_at->equalTo($originalPaidAt))->toBeTrue();
    Queue::assertPushed(ProcessVehicleCheck::class);

    $this->assertDatabaseCount('guest_report_purchases', 1);
    Queue::assertPushedTimes(ProcessVehicleCheck::class, 1);
});

test('guest purchase rejects a successful Stripe event with a mismatched amount', function () {
    $vehicle = Vehicle::create([
        'registration_number' => 'PAYMENT-TEST-003',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
    ]);

    $session = (object) [
        'id' => 'cs_test_guest_003',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'amount_total' => 500,
        'currency' => 'gbp',
        'payment_intent' => 'pi_test_guest_003',
        'metadata' => (object) [
            'purpose' => 'guest_report_purchase',
            'guest_report_purchase_id' => (string) $purchase->id,
            'report_id' => (string) $report->id,
        ],
    ];

    expect(fn () => app(GuestReportPaymentService::class)
        ->handleCheckoutSession(
            $session,
            'checkout.session.completed',
        ))
        ->toThrow(
            RuntimeException::class,
            'Stripe amount or currency does not match the purchase.'
        );

    expect($purchase->fresh()->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING);
});