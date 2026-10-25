<?php

namespace App\Jobs;

use App\Mail\GuestReportAccessReadyMail;
use App\Models\GuestReportPurchase;
use App\Services\GuestReportAccessService;
use App\Services\GuestReportInvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class SendGuestReportAccessEmail implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $purchaseId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'guest-report-access-email-'.$this->purchaseId;
    }

    public function handle(
        GuestReportInvoiceService $invoiceService,
        GuestReportAccessService $accessService,
    ): void {
        $purchase = GuestReportPurchase::query()
            ->with('report')
            ->findOrFail($this->purchaseId);

        // Jangan kirim ulang jika pengiriman sebelumnya sudah tercatat.
        if ($purchase->access_email_sent_at !== null) {
            return;
        }

        $report = $purchase->report;

        // Pastikan pembayaran dan fulfillment benar-benar selesai.
        if (
            $purchase->payment_status !== GuestReportPurchase::STATUS_PAID
            || $purchase->fulfillment_status !== GuestReportPurchase::FULFILLMENT_COMPLETED
            || ! $report
            || $report->user_id !== null
            || $report->report_type !== 'premium'
        ) {
            return;
        }

        if (
            ! is_string($purchase->email)
            || ! filter_var($purchase->email, FILTER_VALIDATE_EMAIL)
        ) {
            throw new RuntimeException(
                'Guest report purchase has no valid customer email address.'
            );
        }

        // Hanya baca token aktif. Jangan membuat atau merotasi token.
        $token = $accessService->tokenForCompletedPurchase($purchase);

        if ($token === null) {
            throw new RuntimeException(
                'No active access token exists for the completed guest purchase.'
            );
        }

        $invoice = $invoiceService->payload($purchase);
        $pdf = $invoiceService->generatePdf($invoice);

        try {
            $reportUrl = route('page.my-report.show', [
                'report' => $report->id,
                'guest_token' => $token,
            ]);

            $invoiceUrl = URL::temporarySignedRoute(
                'guest-report.invoice.show',
                now()->addDays(30),
                ['purchase' => $purchase->id],
            );

            Mail::to($purchase->email)->send(
                new GuestReportAccessReadyMail(
                    customerName: $purchase->name ?: 'Customer',
                    registrationNumber: (string) data_get(
                        $invoice,
                        'vehicle.registration_number',
                        'Unknown',
                    ),
                    reportUrl: $reportUrl,
                    invoiceUrl: $invoiceUrl,
                    invoicePdfPath: $pdf['path'],
                    invoicePdfFileName: $pdf['file_name'],
                )
            );

            // Tandai terkirim hanya setelah Mail::send berhasil.
            GuestReportPurchase::query()
                ->whereKey($purchase->id)
                ->where('payment_status', GuestReportPurchase::STATUS_PAID)
                ->where(
                    'fulfillment_status',
                    GuestReportPurchase::FULFILLMENT_COMPLETED,
                )
                ->whereNull('access_email_sent_at')
                ->update([
                    'access_email_sent_at' => now(),
                ]);
        } finally {
            // Jangan tinggalkan PDF sementara setelah proses pengiriman.
            if (is_file($pdf['path'])) {
                unlink($pdf['path']);
            }
        }
    }
}