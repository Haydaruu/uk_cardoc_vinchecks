<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelPdf\Facades\Pdf;

class InvoiceController extends Controller
{
    public function show(Transaction $transaction, Request $request): Response
    {
        $this->authorizeTransaction($transaction, $request);

        return Inertia::render('user/invoice/show', 
            [
                'invoice' => $this->invoicePayload(
                    $transaction,
                    $request,
                ),
            ]
        );
    }

    public function download(Transaction $transaction, Request $request)
    {
        $this->authorizeTransaction($transaction, $request);

        $invoiceNumber = $transaction->invoice_id ?? 'UKC-TXN-' . $transaction->id;

        $fileName = 'invoice' . preg_replace('/[^A-Za-z0-9\-]/', '', $invoiceNumber) . '.pdf';

        $directory = storage_path('app/pdf-temp');

        if(! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $outputPath = $directory . DIRECTORY_SEPARATOR . $fileName;

        Pdf::view('pdf.invoices.invoice',
            [
                'invoice' => $this->invoicePayload($transaction, $request),
            ]
        )
        ->format('A4')
        ->margins(12,12,16,12)
        ->save($outputPath);

        return response()->file($outputPath,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]
        );
    }

    private function authorizeTransaction(Transaction $transaction, Request $request): void
    {
        abort_unless($transaction->user_id === $request->user()->id, 403);
        abort_unless($transaction->type === 'payment' && $transaction->status === 'success', 404);
    }

    private function invoicePayload(Transaction $transaction, Request $request): array
    {
        $user = $request->user();
        
        return [
            'id' => $transaction->id,
            'invoice_id' => $transaction->invoice_id ?? 'UKC-TXN-' . $transaction->id,
            'description' => $transaction->description ?? 'UKCarDoc payment',
            'amount' => (string) $transaction->amount,
            'currency' => strtoupper($transaction->currency),
            'status' => $transaction->status,
            'payment_method' => $transaction->payment_method ?? 'unknown',
            'payment_gateway_ref' => $transaction->payment_gateway_ref,
            'paid_at' => $transaction->paid_at,
            'customer' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
        ];
    }
}
