import {
    Head,
    router,
} from '@inertiajs/react';

import {
    ArrowRight,
    CheckCircle2,
    CreditCard,
    Download,
    Wallet,
} from 'lucide-react';

type PurchaseType =
    | 'credit_purchase'
    | 'subscription';

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

    /*
     * Default ke credit purchase supaya
     * Stripe/PayPal one-time lama tetap
     * kompatibel walaupun controller belum
     * mengirim purchaseType.
     */
    purchaseType = 'credit_purchase',
}: CheckoutSuccessProps) {
    const isSubscription =
        purchaseType ===
        'subscription';

    function handleDashboard() {
        router.visit('/dashboard');
    }

    const formattedAmount =
        formatCurrency(
            order.amount,
            order.currency,
        );

    /*
     * Untuk flow sekarang:
     *
     * Stripe/card:
     * punya cardBrand + cardLast4
     *
     * PayPal:
     * keduanya null
     */
    const isCardPayment =
        !!order.cardBrand &&
        !!order.cardLast4;

    return (
        <>
            <Head
                title={
                    isSubscription
                        ? 'Subscription Successful'
                        : 'Payment Successful'
                }
            />

            <div className="flex min-h-screen items-center justify-center bg-background py-10 font-body-md text-on-background">

                {/* Main */}
                <main className="bg-pattern relative w-full px-gutter py-section-padding">

                    {/* Decorative Background */}
                    <div className="absolute left-1/4 top-1/4 -z-10 size-96 rounded-full bg-primary-fixed-dim/20 blur-3xl mix-blend-multiply" />

                    <div className="absolute bottom-1/4 right-1/4 -z-10 size-80 rounded-full bg-secondary-fixed-dim/20 blur-3xl mix-blend-multiply" />

                    <div className="mx-auto w-full max-w-2xl">

                        <div className="card-shadow overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest">

                            <div className="sovereign-line" />

                            <div className="flex flex-col items-center p-8 text-center md:p-12">

                                {/* Success Icon */}
                                <div className="relative mb-6 flex size-20 items-center justify-center rounded-full bg-surface-container-low">

                                    <div
                                        className="absolute inset-0 animate-ping rounded-full bg-secondary opacity-10"
                                        style={{
                                            animationDuration:
                                                '3s',
                                        }}
                                    />

                                    <CheckCircle2
                                        className="size-10 text-secondary"
                                        strokeWidth={
                                            2
                                        }
                                    />

                                </div>

                                {/* Success Title */}
                                <h1 className="font-h1 text-h1 mb-2 text-primary">
                                    {isSubscription
                                        ? 'Subscription Successful'
                                        : 'Payment Successful'}
                                </h1>

                                {/* Success Description */}
                                <p className="font-body-lg text-body-lg mb-8 max-w-lg text-on-surface-variant">
                                    {isSubscription
                                        ? 'Your membership is now active and your monthly credits have been added.'
                                        : 'Your credits have been added to your account.'}
                                </p>

                                {/* Transaction Summary */}
                                <div className="mb-8 w-full rounded-lg border border-outline-variant/50 bg-surface-container-low p-6 text-left">

                                    <h2 className="font-label-sm text-label-sm mb-4 border-b border-outline-variant/30 pb-2 uppercase tracking-widest text-on-surface-variant">
                                        Transaction
                                        Summary
                                    </h2>

                                    <div className="grid grid-cols-1 gap-x-8 gap-y-4 md:grid-cols-2">

                                        <SummaryField
                                            label="Order Number"
                                            value={
                                                order.number
                                            }
                                        />

                                        <SummaryField
                                            label="Date"
                                            value={
                                                order.date
                                            }
                                        />

                                        {/* Item */}
                                        <div className="flex flex-col md:col-span-2">

                                            <span className="font-label-sm text-label-sm text-on-surface-variant">
                                                {isSubscription
                                                    ? 'Membership Plan'
                                                    : 'Item'}
                                            </span>

                                            <span className="font-body-md text-body-md font-semibold text-primary">
                                                {
                                                    order.item
                                                }
                                            </span>

                                        </div>

                                        {/* Amount */}
                                        <div className="flex flex-col">

                                            <span className="font-label-sm text-label-sm text-on-surface-variant">
                                                {isSubscription
                                                    ? 'Amount Paid Today'
                                                    : 'Total Amount'}
                                            </span>

                                            <span className="font-h3 text-h3 text-primary">
                                                {
                                                    formattedAmount
                                                }
                                            </span>

                                        </div>

                                        {/* Payment Method */}
                                        <div className="flex flex-col">

                                            <span className="font-label-sm text-label-sm text-on-surface-variant">
                                                Payment Method
                                            </span>

                                            {isCardPayment ? (
                                                <div className="mt-1 flex items-center gap-2">

                                                    <CreditCard className="size-4 text-primary" />

                                                    <span className="font-body-md text-body-md font-semibold capitalize text-primary">
                                                        {
                                                            order.cardBrand
                                                        }{' '}
                                                        ending
                                                        in{' '}
                                                        {
                                                            order.cardLast4
                                                        }
                                                    </span>

                                                </div>
                                            ) : (
                                                <div className="mt-1 flex items-center gap-2">

                                                    <Wallet className="size-4 text-primary" />

                                                    <span className="font-body-md text-body-md font-semibold text-primary">
                                                        PayPal
                                                    </span>

                                                </div>
                                            )}

                                        </div>

                                        {/* Subscription Info */}
                                        {isSubscription && (
                                            <div className="mt-2 rounded-md border border-outline-variant/30 bg-surface-container-lowest p-4 md:col-span-2">

                                                <p className="text-sm font-semibold text-primary">
                                                    Monthly
                                                    Membership
                                                </p>

                                                <p className="mt-1 text-xs leading-relaxed text-on-surface-variant">
                                                    Your
                                                    membership
                                                    will renew
                                                    automatically
                                                    according
                                                    to your
                                                    billing
                                                    cycle. You
                                                    can manage
                                                    or cancel
                                                    it from
                                                    your
                                                    subscription
                                                    settings.
                                                </p>

                                            </div>
                                        )}

                                    </div>

                                </div>

                                {/* Actions */}
                                <div className="flex w-full flex-col gap-4 md:flex-row">

                                    <button
                                        type="button"
                                        onClick={
                                            handleDashboard
                                        }
                                        className="group relative flex flex-1 items-center justify-center gap-2 overflow-hidden rounded bg-secondary px-6 py-4 font-label-sm text-label-sm text-on-secondary shadow-sm transition-colors duration-200 hover:bg-on-secondary-fixed-variant"
                                    >

                                        <div className="absolute left-0 top-0 h-px w-full bg-white/30" />

                                        <span>
                                            Go to
                                            Dashboard
                                        </span>

                                        <ArrowRight className="size-[18px]" />

                                    </button>

                                    {/* Download Receipt */}
                                    <button
                                        type="button"
                                        disabled
                                        title="Coming soon"
                                        className="flex flex-1 cursor-not-allowed items-center justify-center gap-2 rounded border border-primary/40 px-6 py-4 font-label-sm text-label-sm text-primary/40"
                                    >

                                        <Download className="size-[18px]" />

                                        <span>
                                            Download
                                            Receipt
                                        </span>

                                    </button>

                                </div>

                            </div>

                        </div>

                    </div>

                </main>

            </div>
        </>
    );
}

function SummaryField({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div className="flex flex-col">

            <span className="font-label-sm text-label-sm text-on-surface-variant">
                {label}
            </span>

            <span className="font-body-md text-body-md font-semibold text-primary">
                {value}
            </span>

        </div>
    );
}

function formatCurrency(
    amount: string,
    currency: string,
): string {
    const numericAmount =
        Number(amount);

    if (
        Number.isNaN(
            numericAmount,
        )
    ) {
        return `${currency} ${amount}`;
    }

    try {
        return new Intl.NumberFormat(
            'en-GB',
            {
                style: 'currency',
                currency:
                    currency.toUpperCase(),
            },
        ).format(
            numericAmount,
        );
    } catch {
        return `${currency.toUpperCase()} ${amount}`;
    }
}