<?php

namespace App\Http\Controllers;

use App\Models\GuestReportPurchase;
use App\Services\GuestReportAccessService;
use App\Services\GuestReportInvoiceService;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GuestReportInvoiceController extends Controller
{
    public function show(
        GuestReportPurchase $purchase,
        GuestReportInvoiceService $invoiceService,
        GuestReportAccessService $accessService,
    ): Response {
        $this->assertInvoiceAvailable($purchase);

        $token = $accessService->tokenForCompletedPurchase($purchase);

        $reportUrl = $token !== null
            ? route('page.my-report.show', [
                'report' => $purchase->report_id,
                'guest_token' => $token,
            ])
            : null;

        return Inertia::render('user/invoice/guest-show', [
            'invoice' => $invoiceService->payload($purchase),

            'downloadUrl' => URL::temporarySignedRoute(
                'guest-report.invoice.download',
                now()->addDays(30),
                ['purchase' => $purchase->id],
            ),

            'reportUrl' => $reportUrl,

            'accessEmailSent' => $purchase->access_email_sent_at !== null,
        ]);
    }

    public function download(
        GuestReportPurchase $purchase,
        GuestReportInvoiceService $invoiceService,
    ): BinaryFileResponse {
        $this->assertInvoiceAvailable($purchase);

        $invoice = $invoiceService->payload($purchase);
        $pdf = $invoiceService->generatePdf($invoice);

        return response()
            ->download($pdf['path'], $pdf['file_name'], [
                'Content-Type' => 'application/pdf',
            ])
            ->deleteFileAfterSend(true);
    }

    private function assertInvoiceAvailable(
        GuestReportPurchase $purchase,
    ): void {
        abort_unless(
            $purchase->payment_status === GuestReportPurchase::STATUS_PAID,
            404,
        );

        abort_unless($purchase->report()->exists(), 404);
    }
}
