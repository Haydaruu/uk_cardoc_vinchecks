import { Head, Link, router } from '@inertiajs/react';

import {
    ArrowRight,
    Check,
    CheckCircle2,
    CreditCard,
    Download,
    ReceiptText,
    ShieldCheck,
    Wallet,
} from 'lucide-react';

type PurchaseType = 'credit_purchase' | 'subscription';

type TransactionSummary = {
    id: number;
    invoice_id: string;
    description: string;
    amount: string;
    currency: string;
    status: 'success';
    payment_method: string;
    payment_gateway_ref: string | null;
    paid_at: string | null;
};

type CheckoutSuccessProps = {
    purchaseType?: PurchaseType;
    transaction?: TransactionSummary | null;

    order: {
        number: string;
        date: string;
        item: string;
        amount: string;
        currency: string;
        cardBrand: string | null;
        cardLast4: string | null;
    };
};

export default function CheckoutSuccess({
    order,
    purchaseType = 'credit_purchase',
    transaction = null,
}: CheckoutSuccessProps) {
    const isSubscription = purchaseType === 'subscription';
    const isCardPayment = !!order.cardBrand && !!order.cardLast4;

    const formattedAmount = formatCurrency(
        order.amount,
        order.currency,
    );

    const invoiceViewUrl = transaction
        ? `/settings/purchase-history/${transaction.id}/invoice`
        : null;

    const invoiceDownloadUrl = transaction
        ? `/settings/purchase-history/${transaction.id}/invoice/pdf`
        : null;

    function handleDashboard() {
        router.visit('/dashboard');
    }

    return (
        <>
            <Head
                title={
                    isSubscription
                        ? 'Subscription Confirmed'
                        : 'Payment Confirmed'
                }
            />

            <main className="min-h-screen bg-surface">
                <div className="mx-auto max-w-[920px] px-5 py-12 md:px-8 md:py-20">

                    {/* =====================================================
                        SUCCESS HEADER
                    ===================================================== */}
                    <div className="mb-8 flex flex-col items-center text-center">
                        <div className="mb-5 flex size-14 items-center justify-center rounded-full bg-surface-container">
                            <CheckCircle2 className="size-7 text-secondary" />
                        </div>

                        <p className="mb-2 text-xs font-semibold uppercase tracking-[0.18em] text-secondary">
                            {isSubscription
                                ? 'Membership confirmed'
                                : 'Payment confirmed'}
                        </p>

                        <h1 className="max-w-2xl text-3xl font-bold tracking-tight text-primary md:text-4xl">
                            {isSubscription
                                ? 'Your membership is active'
                                : 'Your purchase is complete'}
                        </h1>

                        <p className="mt-3 max-w-lg text-sm leading-6 text-on-surface-variant">
                            {isSubscription
                                ? 'Your membership has been activated. Monthly credits are applied after payment confirmation.'
                                : 'Your payment was successful and your credits are now available in your account.'}
                        </p>
                    </div>

                    {/* =====================================================
                        RECEIPT CARD
                    ===================================================== */}
                    <section className="overflow-hidden rounded-2xl border border-outline-variant/60 bg-white shadow-[0_18px_50px_rgba(0,13,47,0.06)]">

                        {/* -------------------------------------------------
                            Receipt Header
                        ------------------------------------------------- */}
                        <div className="border-b border-outline-variant/50 px-6 py-6 md:px-8 md:py-7">
                            <div className="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">

                                <div className="flex items-center gap-3">
                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-white">
                                        <ReceiptText className="size-5" />
                                    </div>

                                    <div>
                                        <p className="font-semibold text-primary">
                                            UKCarDoc
                                        </p>

                                        <p className="text-xs text-on-surface-variant">
                                            {isSubscription
                                                ? 'Membership confirmation'
                                                : 'Payment receipt'}
                                        </p>
                                    </div>
                                </div>

                                <div className="sm:text-right">
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-outline">
                                        Reference
                                    </p>

                                    <p className="mt-1 text-sm font-semibold text-primary">
                                        {order.number}
                                    </p>
                                </div>
                            </div>
                        </div>

                        {/* -------------------------------------------------
                            Main Receipt Content
                        ------------------------------------------------- */}
                        <div className="grid md:grid-cols-[1fr_280px]">

                            {/* =============================================
                                Transaction Details
                            ============================================= */}
                            <div className="px-6 py-7 md:px-8 md:py-8">

                                <div>
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-on-surface-variant">
                                        {isSubscription
                                            ? 'Membership'
                                            : 'Purchase'}
                                    </p>

                                    <h2 className="mt-2 text-2xl font-bold tracking-tight text-primary">
                                        {order.item}
                                    </h2>
                                </div>

                                <div className="mt-7 border-t border-outline-variant/40 pt-6">

                                    <p className="mb-5 text-sm font-semibold text-primary">
                                        Transaction details
                                    </p>

                                    <div className="grid gap-x-8 gap-y-6 sm:grid-cols-2">

                                        <ReceiptField
                                            label="Transaction date"
                                            value={
                                                transaction?.paid_at
                                                    ? formatDateTime(
                                                          transaction.paid_at,
                                                      )
                                                    : order.date
                                            }
                                        />

                                        <ReceiptField
                                            label="Invoice number"
                                            value={
                                                transaction?.invoice_id ??
                                                order.number
                                            }
                                        />

                                        {/* Payment Method */}
                                        <div>
                                            <p className="text-xs text-on-surface-variant">
                                                Payment method
                                            </p>

                                            <div className="mt-2 flex items-center gap-2 text-sm font-semibold text-primary">
                                                {isCardPayment ? (
                                                    <>
                                                        <CreditCard className="size-4 shrink-0" />

                                                        <span className="capitalize">
                                                            {order.cardBrand}{' '}
                                                            ••••{' '}
                                                            {order.cardLast4}
                                                        </span>
                                                    </>
                                                ) : (
                                                    <>
                                                        <Wallet className="size-4 shrink-0" />

                                                        <span>
                                                            {formatPaymentMethod(
                                                                transaction?.payment_method,
                                                            )}
                                                        </span>
                                                    </>
                                                )}
                                            </div>
                                        </div>

                                        <ReceiptField
                                            label="Payment gateway reference"
                                            value={
                                                transaction?.payment_gateway_ref ??
                                                'Pending'
                                            }
                                        />

                                        <ReceiptField
                                            label="Description"
                                            value={
                                                transaction?.description ??
                                                order.item
                                            }
                                        />

                                        <ReceiptField
                                            label="Status"
                                            value="Paid"
                                        />
                                    </div>
                                </div>

                                {/* Subscription Note */}
                                {isSubscription && (
                                    <div className="mt-8 rounded-xl border border-outline-variant/40 bg-surface-container-low px-4 py-4">
                                        <div className="flex items-start gap-3">
                                            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" />

                                            <div>
                                                <p className="text-sm font-semibold text-primary">
                                                    Monthly membership
                                                </p>

                                                <p className="mt-1 text-xs leading-5 text-on-surface-variant">
                                                    Your membership renews
                                                    automatically each billing
                                                    cycle until cancelled. You
                                                    can change or cancel your
                                                    plan from your subscription
                                                    settings.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* =============================================
                                Amount Panel
                            ============================================= */}
                            <div className="flex flex-col justify-between border-t border-outline-variant/50 bg-primary p-6 text-white md:border-l md:border-t-0 md:p-8">

                                <div>
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-white/60">
                                        {isSubscription
                                            ? 'Monthly amount'
                                            : 'Amount paid'}
                                    </p>

                                    <p className="mt-3 text-4xl font-bold tracking-tight">
                                        {formattedAmount}
                                    </p>

                                    {isSubscription && (
                                        <p className="mt-2 text-xs leading-5 text-white/65">
                                            Billed monthly until cancelled
                                        </p>
                                    )}
                                </div>

                                <div className="mt-10 border-t border-white/15 pt-5">
                                    <div className="flex items-center gap-2 text-xs text-white/70">
                                        <Check className="size-4" />

                                        <span>
                                            {isSubscription
                                                ? 'Membership active'
                                                : 'Payment received'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* =================================================
                            ACTION FOOTER
                        ================================================= */}
                        <div className="border-t border-outline-variant/50 bg-surface-container-low px-5 py-4 md:px-6 md:py-5">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                                {/* Invoice Actions */}
                                <div className="flex flex-col gap-3 sm:flex-row">
                                    {invoiceViewUrl && (
                                        <Link
                                            href={invoiceViewUrl}
                                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-outline-variant bg-white px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-surface-container"
                                        >
                                            <ReceiptText className="size-4" />
                                            View Invoice
                                        </Link>
                                    )}

                                    {invoiceDownloadUrl && (
                                        <a
                                            href={invoiceDownloadUrl}
                                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-secondary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-secondary-container"
                                        >
                                            <Download className="size-4" />
                                            Download Invoice
                                        </a>
                                    )}

                                    {!invoiceDownloadUrl && (
                                        <Link
                                            href="/settings/purchase-history"
                                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-outline-variant bg-white px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-surface-container"
                                        >
                                            <ReceiptText className="size-4" />
                                            Purchase History
                                        </Link>
                                    )}
                                </div>

                                {/* Primary Navigation */}
                                <button
                                    type="button"
                                    onClick={handleDashboard}
                                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-primary/90"
                                >
                                    Go to dashboard
                                    <ArrowRight className="size-4" />
                                </button>
                            </div>
                        </div>
                    </section>

                    {/* =====================================================
                        Security Note
                    ===================================================== */}
                    <div className="mt-6 flex items-center justify-center gap-2 text-xs text-outline">
                        <ShieldCheck className="size-3.5" />
                        Securely processed payment
                    </div>
                </div>
            </main>
        </>
    );
}

/* =============================================================
   Receipt Field
============================================================= */

function ReceiptField({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="min-w-0">
            <p className="text-xs text-on-surface-variant">
                {label}
            </p>

            <p className="mt-2 break-words text-sm font-semibold leading-5 text-primary">
                {value}
            </p>
        </div>
    );
}

/* =============================================================
   Currency
============================================================= */

function formatCurrency(
    amount: string,
    currency: string,
): string {
    const numericAmount = Number(amount);

    if (Number.isNaN(numericAmount)) {
        return `${currency.toUpperCase()} ${amount}`;
    }

    try {
        return new Intl.NumberFormat('en-GB', {
            style: 'currency',
            currency: currency.toUpperCase(),
        }).format(numericAmount);
    } catch {
        return `${currency.toUpperCase()} ${amount}`;
    }
}

/* =============================================================
   Date
============================================================= */

function formatDateTime(
    value: string,
): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);
}

/* =============================================================
   Payment Method
============================================================= */

function formatPaymentMethod(
    value?: string | null,
): string {
    if (!value) {
        return '—';
    }

    if (value === 'stripe') {
        return 'Stripe';
    }

    if (value === 'paypal') {
        return 'PayPal';
    }

    return value;
}