<?php

use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\GuestReportCheckoutService;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('PayPal guest order accepts an empty optional phone number', function () {
    $registration = 'PPLOPT'.strtoupper(bin2hex(random_bytes(4)));

    $vinCheck = VinCheck::create([
        'registration_number' => $registration,
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Jane Guest',
        'email' => 'jane@example.com',
        'phone' => '',
        'payment_provider' => 'paypal',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2199,
        'currency' => 'gbp',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_LOCKED,
        'fulfillment_attempts' => 0,
    ]);

    $checkoutService = Mockery::mock(GuestReportCheckoutService::class);

    $checkoutService->shouldReceive('preparePayPalPurchase')
        ->once()
        ->with(
            Mockery::on(
                fn ($actualReport) => $actualReport instanceof Report
                    && $actualReport->id === $report->id
            ),
            'Jane Guest',
            'jane@example.com',
            '',
        )
        ->andReturn([
            'purchase' => $purchase,
            'guest_token' => str_repeat('a', 64),
        ]);

    $payPalService = Mockery::mock(PayPalService::class);

    $payPalService->shouldReceive('createGuestReportOrder')
        ->once()
        ->with($purchase)
        ->andReturn([
            'id' => 'ORDER-OPTIONAL-PHONE-001',
            'status' => 'CREATED',
        ]);

    $this->instance(GuestReportCheckoutService::class, $checkoutService);
    $this->instance(PayPalService::class, $payPalService);

    $this->withSession([
        "guest_report_checkout.{$report->id}" => [
            'name' => 'Jane Guest',
            'email' => 'jane@example.com',
            'phone' => '',
        ],
    ]);

    $response = $this->postJson(
        route('guest-report.paypal.create-order', [
            'report' => $report->id,
        ]),
    );

    $response->assertOk()
        ->assertExactJson([
            'id' => 'ORDER-OPTIONAL-PHONE-001',
        ]);

    $this->assertDatabaseHas('guest_report_purchases', [
        'id' => $purchase->id,
        'report_id' => $report->id,
        'phone' => '',
        'provider_order_id' => 'ORDER-OPTIONAL-PHONE-001',
    ]);
});
