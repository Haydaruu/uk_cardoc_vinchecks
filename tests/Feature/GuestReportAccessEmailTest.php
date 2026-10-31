
<?php

use App\Jobs\SendGuestReportAccessEmail;
use App\Mail\GuestReportAccessReadyMail;
use App\Models\GuestReportAccessToken;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Services\GuestReportAccessService;
use App\Services\GuestReportInvoiceService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function createGuestReportEmailTestPurchase(
    string $paymentStatus = GuestReportPurchase::STATUS_PAID,
    string $fulfillmentStatus = GuestReportPurchase::FULFILLMENT_COMPLETED,
): GuestReportPurchase {
    $registration = 'MAIL-'.Str::upper(Str::random(8));

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
        'generated_at' => now(),
    ]);

    return GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Email Test Customer',
        'email' => 'guest@example.com',
        'phone' => '07000000000',
        'payment_provider' => 'stripe',
        'provider_order_id' => 'cs_test_'.Str::uuid(),
        'payment_status' => $paymentStatus,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'paid_at' => $paymentStatus === GuestReportPurchase::STATUS_PAID
            ? now()
            : null,
        'fulfillment_status' => $fulfillmentStatus,
        'fulfilled_at' => $fulfillmentStatus === GuestReportPurchase::FULFILLMENT_COMPLETED
            ? now()
            : null,
    ]);
}

function activateGuestReportEmailTestToken(
    GuestReportPurchase $purchase,
): string {
    $material = sprintf(
        'vincheck-guest-report-access:report:%d:purchase:%d',
        $purchase->report_id,
        $purchase->id,
    );

    $token = hash_hmac(
        'sha256',
        $material,
        config('app.key'),
    );

    GuestReportAccessToken::create([
        'guest_report_id' => $purchase->report_id,
        'token_hash' => hash('sha256', $token),
        'revoked_at' => null,
        'created_at' => now(),
    ]);

    return $token;
}

it('does not send access email before fulfillment is completed', function () {
    Mail::fake();

    $purchase = createGuestReportEmailTestPurchase(
        GuestReportPurchase::STATUS_PAID,
        GuestReportPurchase::FULFILLMENT_PENDING,
    );

    $invoiceService = Mockery::mock(GuestReportInvoiceService::class);
    $invoiceService->shouldNotReceive('payload');
    $invoiceService->shouldNotReceive('generatePdf');

    $job = new SendGuestReportAccessEmail($purchase->id);

    $job->handle(
        $invoiceService,
        app(GuestReportAccessService::class),
    );

    Mail::assertNothingSent();

    expect($purchase->fresh()->access_email_sent_at)->toBeNull();
});

it('sends a completed report email with a valid report link', function () {
    Mail::fake();

    $purchase = createGuestReportEmailTestPurchase();
    $token = activateGuestReportEmailTestToken($purchase);

    $pdfPath = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR
        .'vincheck-email-test-'.Str::uuid().'.pdf';

    file_put_contents($pdfPath, '%PDF-1.4 test fixture');

    $invoiceService = Mockery::mock(
        GuestReportInvoiceService::class
    )->makePartial();

    $invoiceService->shouldReceive('generatePdf')
        ->once()
        ->with(Mockery::on(function (array $invoice): bool {
            return ! array_key_exists('token', $invoice)
                && ! array_key_exists('guest_token', $invoice)
                && ! array_key_exists('access_token', $invoice);
        }))
        ->andReturn([
            'path' => $pdfPath,
            'file_name' => 'invoice-test.pdf',
        ]);

    $job = new SendGuestReportAccessEmail($purchase->id);

    $job->handle(
        $invoiceService,
        app(GuestReportAccessService::class),
    );

    expect($purchase->fresh()->access_email_sent_at)->not->toBeNull();
    expect(is_file($pdfPath))->toBeFalse();

    Mail::assertSent(
        GuestReportAccessReadyMail::class,
        function (GuestReportAccessReadyMail $mail) use (

            $token,
        ): bool {
            return $mail->customerName === 'Email Test Customer'
                && str_contains($mail->reportUrl, $token)
                && str_contains($mail->invoiceUrl, 'signature=')
                && ! str_contains($mail->invoiceUrl, $token);
        }
    );

    $storedToken = GuestReportAccessToken::query()
        ->where('guest_report_id', $purchase->report_id)
        ->where('token_hash', hash('sha256', $token))
        ->whereNull('revoked_at')
        ->exists();

    expect($storedToken)->toBeTrue();
});

it('does not send the email again when it was already recorded as sent', function () {
    Mail::fake();

    $purchase = createGuestReportEmailTestPurchase();

    $purchase->update([
        'access_email_sent_at' => now(),
    ]);

    $invoiceService = Mockery::mock(GuestReportInvoiceService::class);
    $invoiceService->shouldNotReceive('payload');
    $invoiceService->shouldNotReceive('generatePdf');

    $job = new SendGuestReportAccessEmail($purchase->id);

    $job->handle(
        $invoiceService,
        app(GuestReportAccessService::class),
    );

    Mail::assertNothingSent();
});

it('attaches a PDF invoice to the access email', function () {
    $pdfPath = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR
        .'vincheck-attachment-test-'.Str::uuid().'.pdf';

    file_put_contents($pdfPath, '%PDF-1.4 attachment fixture');

    try {
        $mail = new GuestReportAccessReadyMail(
            customerName: 'Email Test Customer',
            registrationNumber: 'MAIL-TEST',
            reportUrl: 'https://example.com/report?guest_token='.str_repeat('a', 64),
            invoiceUrl: 'https://example.com/invoice?signature=test',
            invoicePdfPath: $pdfPath,
            invoicePdfFileName: 'invoice-test.pdf',
        );

        expect($mail->attachments())->toHaveCount(1);
    } finally {
        if (is_file($pdfPath)) {
            unlink($pdfPath);
        }
    }
});
