
<?php

use App\Models\GuestReportAccessToken;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

function createGuestPremiumReportForAccessTest(): Report
{

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-ACCESS-TEST',
    ]);

    return Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'premium',
        'report_data' => [
            'meta' => [
                'format' => 'normalized',
                'schema_version' => 2,
            ],
            'vehicle' => [
                'vrm' => 'GUEST-ACCESS-TEST',
                'make' => 'Ford',
                'model' => 'Focus',
            ],
        ],
    ]);
}

it('denies guest access to a premium report without an access token', function () {
    $report = createGuestPremiumReportForAccessTest();

    $this->get(route('page.my-report.show', [
        'report' => $report->id,
    ]))->assertForbidden();
});

it('allows guest access with a matching paid completed purchase', function () {
    $this->withoutVite();

    $report = createGuestPremiumReportForAccessTest();
    $sessionId = 'cs_test_guest_access_123';

    $accessToken = bin2hex(random_bytes(32));

    GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'provider_order_id' => $sessionId,
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
        'fulfilled_at' => now(),
    ]);

    GuestReportAccessToken::create([
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $accessToken),
        'created_at' => now(),
    ]);

    $url = route('page.my-report.show', [
        'report' => $report->id,
    ]);

    $this->get($url.'?guest_token='.urlencode($accessToken))
        ->assertOk();
});

it('denies guest PDF access without a valid purchase session', function () {
    $report = createGuestPremiumReportForAccessTest();

    $this->get(route('report.pdf.download', [
        'report' => $report->id,
    ]))->assertForbidden();
});

it('allows guest to download PDF with a matching paid completed purchase', function () {
    $report = createGuestPremiumReportForAccessTest();
    $sessionId = 'cs_test_guest_pdf_123';

    $accessToken = bin2hex(random_bytes(32));

    GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'provider_order_id' => $sessionId,
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
        'fulfilled_at' => now(),
    ]);

    GuestReportAccessToken::create([
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $accessToken),
        'created_at' => now(),
    ]);

    $renderer = Mockery::mock(PdfBuilder::class);

    $renderer->shouldReceive('format')
        ->once()
        ->with('a4')
        ->andReturnSelf();

    $renderer->shouldReceive('margins')
        ->once()
        ->with(12, 12, 14, 12)
        ->andReturnSelf();

    $renderer->shouldReceive('save')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturnUsing(function (string $path) use ($renderer) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, "%PDF-1.4\n%%EOF");

            return $renderer;
        });

    Pdf::shouldReceive('view')
        ->once()
        ->with(
            'pdf.reports.vehicle-report',
            Mockery::type('array')
        )
        ->andReturn($renderer);

    $response = $this->get(route('report.pdf.download', [
        'report' => $report->id,
        'guest_token' => $accessToken,
    ]));

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $filePath = storage_path(
        'app/pdf-temp/vehicle-report-GUEST-ACCESS-TEST.pdf'
    );

    if (file_exists($filePath)) {
        unlink($filePath);
    }
});
