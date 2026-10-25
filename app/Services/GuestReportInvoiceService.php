<?php

namespace App\Services;

use App\Models\GuestReportPurchase;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

class GuestReportInvoiceService
{
    public function payload(GuestReportPurchase $purchase): array
    {
        $report = $purchase->report;

        if (! $report) {
            throw new \RuntimeException(
                'Guest report purchase has no associated report.'
            );
        }

        $data = $report->report_data ?? [];

        $make = (string) data_get(
            $data,
            'make',
            data_get($data, 'vehicle.make', ''),
        );

        $model = (string) data_get(
            $data,
            'model',
            data_get($data, 'vehicle.model', ''),
        );

        $registration = (string) data_get(
            $data,
            'registrationNumber',
            data_get(
                $data,
                'vehicle.vrm',
                data_get(
                    $data,
                    'vehicle.registration_number',
                    $report->vin ?? 'Unknown',
                ),
            ),
        );

        $vehicleName = trim("{$make} {$model}");

        $reportIsReady =
            $purchase->fulfillment_status
                === GuestReportPurchase::FULFILLMENT_COMPLETED
            && $report->report_type === 'premium';

        return [
            'id' => $purchase->id,

            'invoice_id' => 'UKC-GUEST-'
                .str_pad((string) $purchase->id, 8, '0', STR_PAD_LEFT),

            'description' => 'Full Vehicle Report - '
                .$vehicleName
                .' ('.$registration.')',

            'amount' => number_format(
                (int) $purchase->amount_minor / 100,
                2,
                '.',
                '',
            ),

            'currency' => strtoupper($purchase->currency),
            'status' => $purchase->payment_status,
            'payment_method' => $purchase->payment_provider ?? 'stripe',

            'payment_gateway_ref' =>
                $purchase->provider_payment_id
                ?? $purchase->provider_order_id,

            'paid_at' => $purchase->paid_at,

            'customer' => [
                'name' => $purchase->name ?: 'Guest customer',
                'email' => $purchase->email,
                'phone' => $purchase->phone,
            ],

            'vehicle' => [
                'make' => $make,
                'model' => $model,
                'registration_number' => $registration,
            ],

            'report_status' => $purchase->fulfillment_status,

            'report_generated_at' => $reportIsReady
                ? $report->generated_at
                : null,

            'purchase_type' => 'guest_report',
        ];
    }

    /**
     * Generate a PDF using the existing invoice template.
     *
     * @return array{path: string, file_name: string}
     */
    public function generatePdf(array $invoice): array
    {
        $directory = storage_path('app/pdf-temp');

        if (! is_dir($directory)) {
            mkdir($directory, 0750, true);
        }

        $fileName = 'invoice-'
            .preg_replace(
                '/[^A-Za-z0-9-]/',
                '',
                $invoice['invoice_id'],
            )
            .'.pdf';

        $outputPath = $directory
            .DIRECTORY_SEPARATOR
            .Str::uuid()
            .'.pdf';

        try {
            Pdf::view('pdf.invoices.invoice', [
                'invoice' => $invoice,
            ])
                ->format('A4')
                ->margins(12, 12, 16, 12)
                ->save($outputPath);
        } catch (Throwable $exception) {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            throw $exception;
        }

        return [
            'path' => $outputPath,
            'file_name' => $fileName,
        ];
    }
}