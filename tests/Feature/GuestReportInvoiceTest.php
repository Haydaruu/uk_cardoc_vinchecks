<?php

use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

function createGuestInvoiceSecurityTestPurchase(
    string $paymentStatus = GuestReportPurchase::STATUS_PAID,
): GuestReportPurchase {
    $registration = 'INV-'.Str::upper(Str::random(8));

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'premium',
        'report_data' => [
            'make' => 'Ford',
            'model' => 'Focus',
            'registrationNumber' => $registration,
        ],
    ]);

    return GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Invoice Test',
        'email' => 'guest@example.com',
        'phone' => '07000000000',
        'payment_provider' => 'stripe',
        'provider_order_id' => 'cs_test_'.Str::uuid(),
        'payment_status' => $paymentStatus,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);
}

it('denies invoice access when the purchase is not paid', function () {
    $purchase = createGuestInvoiceSecurityTestPurchase(
        GuestReportPurchase::STATUS_PENDING,
    );

    $url = URL::temporarySignedRoute(
        'guest-report.invoice.show',
        now()->addMinutes(10),
        ['purchase' => $purchase->id],
    );

    $this->get($url)->assertNotFound();
});

it('denies invoice access when the signature is invalid', function () {
    $purchase = createGuestInvoiceSecurityTestPurchase();

    $url = URL::temporarySignedRoute(
        'guest-report.invoice.show',
        now()->addMinutes(10),
        ['purchase' => $purchase->id],
    );

    $tamperedUrl = preg_replace(
        '/([?&])signature=[^&]+/',
        '$1signature=invalid',
        $url,
    );

    $this->get($tamperedUrl)->assertForbidden();
});

it('allows invoice page access with a valid signature and paid purchase', function () {
    $this->withoutVite();

    $purchase = createGuestInvoiceSecurityTestPurchase();

    $url = URL::temporarySignedRoute(
        'guest-report.invoice.show',
        now()->addMinutes(10),
        ['purchase' => $purchase->id],
    );

    $this->get($url)->assertOk();
});