
import { useState } from 'react';
import BaseLayout from '@/layouts/base-layout';
import { Head } from '@inertiajs/react';
import {
    CarFront,
    CheckCircle2,
    Clock3,
    Download,
    ReceiptText,
    ShieldCheck,
    Copy,
    ExternalLink,
    Mail,
} from 'lucide-react';

type GuestInvoice = {
    id: number;
    invoice_id: string;
    description: string;
    amount: string;
    currency: string;
    status: string;
    payment_method: string;
    payment_gateway_ref: string | null;
    paid_at: string | null;

    customer: {
        name: string;
        email: string;
        phone?: string | null;
    };

    vehicle: {
        make: string;
        model: string;
        registration_number: string;
    };

    report_status: string;
    report_generated_at: string | null;
};

type Props = {
    invoice: GuestInvoice;
    downloadUrl: string;
    reportUrl: string | null;
    accessEmailSent: boolean;
};

export default function GuestInvoiceShow({
    invoice,
    downloadUrl,
    reportUrl,
    accessEmailSent,
}: Props) {
    const amount = formatMoney(invoice.amount, invoice.currency);
    const paidDate = invoice.paid_at
        ? formatDateTime(invoice.paid_at)
        : '—';

    const isReportReady =
        invoice.report_status === 'completed'
        && invoice.report_generated_at !== null;
    const [copyMessage, setCopyMessage] = useState('');
    async function copyReportLink() {
        if (!reportUrl) {
            return;
        }

        try {
            await navigator.clipboard.writeText(reportUrl);
            setCopyMessage('Report link copied successfully.');
        } catch {
            setCopyMessage(
                'Unable to copy automatically. Please open the report first.',
            );
        }
    }

    const reportStatusLabel = getReportStatusLabel(
        invoice.report_status,
    );

    return (
        <>
            <Head title={`Invoice ${invoice.invoice_id}`} />

            <main className="min-h-screen bg-surface px-5 py-10 md:px-8 md:py-14">
                <div className="mx-auto max-w-[920px]">

                    {/* Header */}
                    <div className="mb-8">
                        <p className="mb-2 text-xs font-semibold uppercase tracking-[0.16em] text-secondary">
                            Guest purchase
                        </p>

                        <h1 className="text-3xl font-bold tracking-tight text-primary md:text-4xl">
                            Payment invoice
                        </h1>

                        <p className="mt-3 max-w-xl text-sm leading-6 text-on-surface-variant">
                            Keep this invoice as proof of payment. If you
                            experience an issue accessing your report, provide
                            the invoice reference to our support team.
                        </p>
                    </div>

                    <section className="overflow-hidden rounded-xl border border-outline-variant/60 border-t-4 border-t-[#c8102e] bg-white shadow-[0_14px_40px_rgba(0,13,47,0.08)]">

                        {/* Invoice header */}
                        <div className="flex flex-col gap-6 border-b border-outline-variant/50 px-6 py-7 sm:flex-row sm:items-start sm:justify-between md:px-8">
                            <div className="flex items-center gap-3">
                                <div className="flex size-11 items-center justify-center rounded-lg bg-primary text-white">
                                    <ReceiptText className="size-5" />
                                </div>

                                <div>
                                    <p className="font-semibold text-primary">
                                        UKCarDoc
                                    </p>

                                    <p className="text-xs text-on-surface-variant">
                                        Guest payment receipt
                                    </p>
                                </div>
                            </div>

                            <div className="sm:text-right">
                                <p className="text-xs uppercase tracking-wider text-outline">
                                    Invoice reference
                                </p>

                                <p className="mt-1 break-all text-sm font-bold text-primary">
                                    {invoice.invoice_id}
                                </p>

                                <div className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-green-700">
                                    <CheckCircle2 className="size-3.5" />
                                    Paid
                                </div>
                            </div>
                        </div>

                        {/* Customer and payment */}
                        <div className="grid gap-8 px-6 py-7 md:grid-cols-2 md:px-8 md:py-9">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-on-surface-variant">
                                    Invoice to
                                </p>

                                <p className="mt-2 text-lg font-bold text-primary">
                                    {invoice.customer.name}
                                </p>

                                <p className="mt-1 break-all text-sm text-on-surface-variant">
                                    {invoice.customer.email}
                                </p>

                                {invoice.customer.phone && (
                                    <p className="mt-1 text-sm text-on-surface-variant">
                                        {invoice.customer.phone}
                                    </p>
                                )}
                            </div>

                            <div className="md:text-right">
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-on-surface-variant">
                                    Payment details
                                </p>

                                <p className="mt-2 text-sm font-semibold capitalize text-primary">
                                    {formatPaymentMethod(invoice.payment_method)}
                                </p>

                                <p className="mt-1 text-sm text-on-surface-variant">
                                    {paidDate}
                                </p>
                            </div>
                        </div>

                        {/* Vehicle details */}
                        <div className="mx-6 rounded-lg border border-outline-variant/60 md:mx-8">
                            <div className="flex items-center gap-3 border-b border-outline-variant/50 bg-surface-container-low px-5 py-4">
                                <CarFront className="size-5 text-primary" />

                                <p className="text-sm font-semibold text-primary">
                                    Vehicle details
                                </p>
                            </div>

                            <div className="grid gap-5 p-5 sm:grid-cols-2">
                                <div>
                                    <p className="text-xs text-on-surface-variant">
                                        Vehicle
                                    </p>

                                    <p className="mt-1 font-semibold text-primary">
                                        {[invoice.vehicle.make, invoice.vehicle.model]
                                            .filter(Boolean)
                                            .join(' ') || 'Vehicle report'}
                                    </p>
                                </div>

                                <div>
                                    <p className="text-xs text-on-surface-variant">
                                        Registration
                                    </p>

                                    <p className="mt-1 font-mono font-bold text-primary">
                                        {invoice.vehicle.registration_number}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* Purchased item */}
                        <div className="mx-6 mt-7 overflow-hidden rounded-lg border border-outline-variant/60 md:mx-8">
                            <div className="grid grid-cols-[1fr_auto] border-b border-outline-variant/60 bg-surface-container-low px-5 py-4">
                                <span className="text-xs font-bold uppercase tracking-wider text-on-surface-variant">
                                    Description
                                </span>

                                <span className="text-xs font-bold uppercase tracking-wider text-on-surface-variant">
                                    Amount
                                </span>
                            </div>

                            <div className="grid grid-cols-[1fr_auto] gap-4 px-5 py-6">
                                <div>
                                    <p className="font-semibold text-primary">
                                        Full Vehicle Report
                                    </p>

                                    <p className="mt-1 text-xs leading-5 text-on-surface-variant">
                                        {invoice.description}
                                    </p>
                                </div>

                                <p className="font-semibold text-primary">
                                    {amount}
                                </p>
                            </div>
                        </div>

                        {/* Total */}
                        <div className="mx-6 py-7 md:mx-8">
                            <div className="ml-auto max-w-sm space-y-3">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-on-surface-variant">
                                        Subtotal
                                    </span>

                                    <span className="font-medium text-primary">
                                        {amount}
                                    </span>
                                </div>

                                <div className="flex items-center justify-between border-t border-outline-variant/60 pt-4">
                                    <span className="text-lg font-bold text-primary">
                                        Total paid
                                    </span>

                                    <span className="text-2xl font-bold text-primary">
                                        {amount}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Report access actions */}
                        {isReportReady && (
                            <div className="mx-6 mb-7 rounded-xl border border-outline-variant/60 bg-white p-5 md:mx-8">
                                <div className="flex items-start gap-3">
                                    <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-green-600" />

                                    <div className="min-w-0 flex-1">
                                        <h2 className="font-semibold text-primary">
                                            Your full report is ready
                                        </h2>

                                        <p className="mt-1 text-sm leading-6 text-on-surface-variant">
                                            Access your report securely or copy its link to save
                                            for later.
                                        </p>
                                    </div>
                                </div>

                                {reportUrl ? (
                                    <div className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md bg-[#c8102e] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#a80d26] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#c8102e] focus-visible:ring-offset-2">
                                        <a
                                            href={reportUrl}
                                            className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md bg-primary px-5 py-3 text-sm font-bold text-white transition hover:opacity-90"
                                        >
                                            <ExternalLink className="size-4" />
                                            View Full Report
                                        </a>

                                        <button
                                            type="button"
                                            onClick={() => void copyReportLink()}
                                            className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md border border-[#000d2f] px-5 py-3 text-sm font-bold text-[#000d2f] transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#000d2f] focus-visible:ring-offset-2"
                                        >
                                            <Copy className="size-4" />
                                            Copy Report Link
                                        </button>
                                    </div>
                                ) : (
                                    <p className="mt-4 rounded-md bg-surface-container-low p-3 text-sm leading-6 text-on-surface-variant">
                                        Your report is marked as ready, but its access link
                                        is currently unavailable. Check your email or contact
                                        support using your invoice reference.
                                    </p>
                                )}

                                {copyMessage && (
                                    <p
                                        role="status"
                                        aria-live="polite"
                                        className="mt-3 text-sm text-on-surface-variant"
                                    >
                                        {copyMessage}
                                    </p>
                                )}

                                <div className="mt-5 flex items-start gap-3 rounded-lg bg-surface-container-low p-4">
                                    <Mail className="mt-0.5 size-5 shrink-0 text-primary" />

                                    <div>
                                        <p className="text-sm font-semibold text-primary">
                                            {accessEmailSent
                                                ? 'Your report email has been sent'
                                                : 'Your report email is being prepared'}
                                        </p>

                                        <p className="mt-1 break-all text-sm leading-6 text-on-surface-variant">
                                            {accessEmailSent
                                                ? `We sent the report access link and invoice to ${invoice.customer.email}. Check your inbox and spam folder.`
                                                : `Your report access email has not yet been marked as sent. Please check back later or use the report link above.`}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Payment reference */}
                        <div className="border-t border-outline-variant/50 bg-surface-container-low/40 px-6 py-5 md:px-8">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <p className="text-xs text-on-surface-variant">
                                        Payment reference
                                    </p>

                                    <p className="mt-1 break-all text-sm font-medium text-primary">
                                        {invoice.payment_gateway_ref ?? '—'}
                                    </p>
                                </div>

                                <a
                                    href={downloadUrl}
                                    className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-md bg-[#000d2f] px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-[#142a57] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#000d2f] focus-visible:ring-offset-2"
                                >
                                    <Download className="size-4" />
                                    Download Invoice PDF
                                </a>
                            </div>
                        </div>
                    </section>

                    {/* Support note */}
                    <div className="mt-6 flex items-start justify-center gap-2 text-xs leading-5 text-outline">
                        <ShieldCheck className="mt-0.5 size-4 shrink-0" />

                        <p className="max-w-xl text-center">
                            Keep your invoice reference for support. This invoice
                            does not contain your private report access token.
                        </p>
                    </div>
                </div>
            </main>
        </>
    );
}

function getReportStatusLabel(status: string): string {
    switch (status) {
        case 'completed':
            return 'Your full report is ready.';
        case 'processing':
            return 'Your full report is being prepared.';
        case 'pending':
            return 'Your full report is waiting to be processed.';
        case 'failed':
            return 'Report processing needs attention. Please contact support if this persists.';
        default:
            return 'Your report status is being checked.';
    }
}

function formatMoney(amount: string, currency: string): string {
    try {
        return new Intl.NumberFormat('en-GB', {
            style: 'currency',
            currency: currency.toUpperCase(),
        }).format(Number(amount));
    } catch {
        return `${currency.toUpperCase()} ${amount}`;
    }
}

function formatDateTime(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'long',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);
}

function formatPaymentMethod(value: string): string {
    if (value === 'stripe') {
        return 'Stripe';
    }

    if (value === 'paypal') {
        return 'PayPal';
    }

    return value;
}

GuestInvoiceShow.layout = (
    page: React.ReactNode,
) => <BaseLayout>{page}</BaseLayout>;
