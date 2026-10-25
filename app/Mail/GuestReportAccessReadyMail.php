<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuestReportAccessReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $customerName,
        public string $registrationNumber,
        public string $reportUrl,
        public string $invoiceUrl,
        public string $invoicePdfPath,
        public string $invoicePdfFileName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your VinCheck Full Vehicle Report Is Ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-report-access-ready',
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->invoicePdfPath)
                ->as($this->invoicePdfFileName)
                ->withMime('application/pdf'),
        ];
    }
}