import {
    Head,
    router,
} from '@inertiajs/react';

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

type CheckoutSuccessProps = {
    purchaseType?: PurchaseType;

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
}: CheckoutSuccessProps) {
    const isSubscription = purchaseType === 'subscription';
    const isCardPayment = !!order.cardBrand && !!order.cardLast4;
    const formattedAmount = formatCurrency(order.amount, order.currency);

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

                    {/* Status */}
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

                    {/* Receipt */}
                    <section className="overflow-hidden rounded-xl border border-outline-variant/60 bg-white shadow-[0_18px_50px_rgba(0,13,47,0.06)]">

                        {/* Header */}
                        <div className="flex flex-col gap-5 border-b border-outline-variant/50 px-6 py-6 sm:flex-row sm:items-center sm:justify-between md:px-8">
                            <div className="flex items-center gap-3">
                                <div className="flex size-10 items-center justify-center rounded-lg bg-primary text-white">
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
                                <p className="text-xs uppercase tracking-wider text-outline">
                                    Reference
                                </p>

                                <p className="mt-1 text-sm font-semibold text-primary">
                                    {order.number}
                                </p>
                            </div>
                        </div>

                        {/* Content */}
                        <div className="grid md:grid-cols-[1fr_280px]">

                            {/* Details */}
                            <div className="px-6 py-7 md:px-8 md:py-8">
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-on-surface-variant">
                                    {isSubscription ? 'Membership' : 'Purchase'}
                                </p>

                                <h2 className="mt-2 text-2xl font-bold text-primary">
                                    {order.item}
                                </h2>

                                <div className="mt-7 grid gap-6 border-t border-outline-variant/40 pt-6 sm:grid-cols-2">
                                    <ReceiptField
                                        label="Transaction date"
                                        value={order.date}
                                    />

                                    <ReceiptField
                                        label="Reference number"
                                        value={order.number}
                                    />

                                    <div>
                                        <p className="text-xs text-on-surface-variant">
                                            Payment method
                                        </p>

                                        <div className="mt-2 flex items-center gap-2 text-sm font-semibold text-primary">
                                            {isCardPayment ? (
                                                <>
                                                    <CreditCard className="size-4" />

                                                    <span className="capitalize">
                                                        {order.cardBrand} •••• {order.cardLast4}
                                                    </span>
                                                </>
                                            ) : (
                                                <>
                                                    <Wallet className="size-4" />
                                                    <span>PayPal</span>
                                                </>
                                            )}
                                        </div>
                                    </div>

                                    <ReceiptField
                                        label="Status"
                                        value={isSubscription ? 'Active' : 'Paid'}
                                    />
                                </div>

                                {isSubscription && (
                                    <div className="mt-7 rounded-lg bg-surface-container-low px-4 py-4">
                                        <div className="flex items-start gap-3">
                                            <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" />

                                            <div>
                                                <p className="text-sm font-semibold text-primary">
                                                    Monthly membership
                                                </p>

                                                <p className="mt-1 text-xs leading-5 text-on-surface-variant">
                                                    Your membership renews automatically each billing cycle until cancelled.
                                                    You can change or cancel your plan from your subscription settings.
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* Amount */}
                            <div className="flex flex-col justify-between border-t border-outline-variant/50 bg-primary p-6 text-white md:border-l md:border-t-0 md:p-8">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-[0.15em] text-white/60">
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

                                        {isSubscription
                                            ? 'Membership active'
                                            : 'Payment received'}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Footer */}
                        <div className="flex flex-col gap-3 border-t border-outline-variant/50 bg-surface-container-low/40 px-6 py-5 sm:flex-row sm:items-center sm:justify-between md:px-8">
                            <p className="text-xs leading-5 text-on-surface-variant">
                                {isSubscription
                                    ? 'You can manage this membership from your account settings.'
                                    : 'Keep this receipt for your records.'}
                            </p>

                            <div className="flex flex-col gap-3 sm:flex-row">
                                <button
                                    type="button"
                                    disabled
                                    title="Coming soon"
                                    className="flex cursor-not-allowed items-center justify-center gap-2 rounded-md border border-outline-variant bg-white px-5 py-3 text-sm font-semibold text-outline opacity-60"
                                >
                                    <Download className="size-4" />
                                    Download receipt
                                </button>

                                <button
                                    type="button"
                                    onClick={handleDashboard}
                                    className="flex items-center justify-center gap-2 rounded-md bg-secondary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-secondary-container"
                                >
                                    Go to dashboard
                                    <ArrowRight className="size-4" />
                                </button>
                            </div>
                        </div>
                    </section>

                    <div className="mt-6 flex items-center justify-center gap-2 text-xs text-outline">
                        <ShieldCheck className="size-3.5" />
                        Securely processed payment
                    </div>
                </div>
            </main>
        </>
    );
}

function ReceiptField({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div>
            <p className="text-xs text-on-surface-variant">
                {label}
            </p>

            <p className="mt-2 break-words text-sm font-semibold text-primary">
                {value}
            </p>
        </div>
    );
}

function formatCurrency(
    amount: string,
    currency: string,
): string {
    const numericAmount = Number(amount);

    if(Number.isNaN(numericAmount)) {
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