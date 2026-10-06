import BaseLayout from '@/layouts/base-layout';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    Download,
    ReceiptText,
} from 'lucide-react';

type Invoice = {
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
    };
};

type Props = {
    invoice: Invoice;
};

export default function InvoiceShow({
    invoice,
}: Props) {
    const amount = formatMoney(
        invoice.amount,
        invoice.currency,
    );

    const paidDate = invoice.paid_at
        ? formatDate(invoice.paid_at)
        : '—';

    const pdfUrl =
        `/settings/purchase-history/${invoice.id}/invoice/pdf`;

    return (
        <>
            <Head
                title={`Invoice ${invoice.invoice_id}`}
            />

            <main className="min-h-screen bg-surface px-5 py-10 md:px-8 md:py-14">
                <div className="mx-auto max-w-[920px]">

                    <div className="mb-6">
                        <Link
                            href="/settings/purchase-history"
                            className="inline-flex items-center gap-2 text-sm font-medium text-on-surface-variant transition-colors hover:text-primary"
                        >
                            <ArrowLeft className="size-4" />
                            Purchase History
                        </Link>
                    </div>

                    <section className="overflow-hidden rounded-xl border border-outline-variant/60 bg-white shadow-[0_14px_40px_rgba(0,13,47,0.06)]">

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
                                        Payment invoice
                                    </p>
                                </div>
                            </div>

                            <div className="sm:text-right">
                                <p className="text-xs uppercase tracking-wider text-outline">
                                    Invoice
                                </p>

                                <p className="mt-1 text-sm font-bold text-primary">
                                    {invoice.invoice_id}
                                </p>

                                <div className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-green-100 px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider text-green-700">
                                    <CheckCircle2 className="size-3.5" />
                                    Paid
                                </div>
                            </div>

                        </div>

                        <div className="grid gap-8 px-6 py-7 md:grid-cols-2 md:px-8 md:py-9">

                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-on-surface-variant">
                                    Invoice To
                                </p>

                                <p className="mt-2 text-lg font-bold text-primary">
                                    {invoice.customer.name}
                                </p>

                                <p className="mt-1 text-sm text-on-surface-variant">
                                    {invoice.customer.email}
                                </p>
                            </div>

                            <div className="md:text-right">
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-on-surface-variant">
                                    Payment Details
                                </p>

                                <p className="mt-2 text-sm font-semibold text-primary">
                                    {formatPaymentMethod(
                                        invoice.payment_method,
                                    )}
                                </p>

                                <p className="mt-1 text-sm text-on-surface-variant">
                                    {paidDate}
                                </p>
                            </div>

                        </div>

                        <div className="mx-6 overflow-hidden rounded-lg border border-outline-variant/60 md:mx-8">

                            <div className="grid grid-cols-[1fr_auto] border-b border-outline-variant/60 bg-surface-container-low px-5 py-4">
                                <span className="text-xs font-bold uppercase tracking-wider text-on-surface-variant">
                                    Description
                                </span>

                                <span className="text-xs font-bold uppercase tracking-wider text-on-surface-variant">
                                    Amount
                                </span>
                            </div>

                            <div className="grid grid-cols-[1fr_auto] gap-6 px-5 py-6">
                                <div>
                                    <p className="font-semibold text-primary">
                                        {invoice.description}
                                    </p>

                                    <p className="mt-1 text-xs text-on-surface-variant">
                                        UKCarDoc account purchase
                                    </p>
                                </div>

                                <p className="font-semibold text-primary">
                                    {amount}
                                </p>
                            </div>

                        </div>

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
                                        Total Paid
                                    </span>

                                    <span className="text-2xl font-bold text-primary">
                                        {amount}
                                    </span>
                                </div>
                            </div>

                        </div>

                        <div className="border-t border-outline-variant/50 bg-surface-container-low/40 px-6 py-5 md:px-8">

                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                                <div>
                                    <p className="text-xs text-on-surface-variant">
                                        Gateway reference
                                    </p>

                                    <p className="mt-1 break-all text-sm font-medium text-primary">
                                        {invoice.payment_gateway_ref ??
                                            '—'}
                                    </p>
                                </div>

                                <a
                                    href={pdfUrl}
                                    className="inline-flex items-center justify-center gap-2 rounded-md bg-secondary px-5 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-secondary-container"
                                >
                                    <Download className="size-4" />
                                    Download Invoice
                                </a>

                            </div>

                        </div>

                    </section>
                </div>
            </main>
        </>
    );
}

function formatMoney(
    amount: string,
    currency: string,
): string {
    return new Intl.NumberFormat(
        'en-GB',
        {
            style: 'currency',
            currency: currency.toUpperCase(),
        },
    ).format(Number(amount));
}

function formatDate(
    value: string,
): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(
        'en-GB',
        {
            day: '2-digit',
            month: 'long',
            year: 'numeric',
        },
    ).format(date);
}

function formatPaymentMethod(
    value: string,
): string {
    if (value === 'stripe') {
        return 'Stripe';
    }

    if (value === 'paypal') {
        return 'PayPal';
    }

    return value;
}

InvoiceShow.layout = (
    page: React.ReactNode,
) => <BaseLayout>{page}</BaseLayout>;