import SettingsLayout from '@/layouts/settings/settings-layout';
import { router } from '@inertiajs/react';
import {
    CheckCircle2,
    CreditCard,
    Download,
    ShieldCheck,
} from 'lucide-react';
import type { ReactNode } from 'react';

type SubscriptionData = {
    plan_name: string;
    price: string;
    status: 'active' | 'pending' | 'cancelled' | 'expired';
    monthly_credits: number;
    payment_method: string | null;
    start_date: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
    cancelled_at: string | null;
};

type Invoice = {
    id: number;
    invoice_id: string | null;
    amount: string;
    currency: string;
    status: string;
    paid_at: string | null;
    description: string | null;
};

type Props = {
    subscription: SubscriptionData | null;

    plan: {
        name: string;
        price: string;
        monthly_credits: number;
    };

    recentInvoices?: Invoice[];
};

export default function Subscription({
    subscription,
    plan,
    recentInvoices = [],
}: Props) {
    const subscribe = () => {
        router.post('/settings/subscription/checkout');
    };

    const cancelSubscription = () => {
        const confirmed = window.confirm(
            'Cancel your subscription at the end of the current billing period?',
        );

        if (!confirmed) {
            return;
        }

        router.delete('/settings/subscription', {
            preserveScroll: true,
        });
    };

    return (
        <SettingsLayout>
            <div className="min-h-screen bg-surface">
                <div className="mx-auto w-full max-w-container-max px-margin-mobile pb-12 md:px-gutter">

                    {/* Header */}
                    <header className="mb-12 border-b border-outline-variant pb-6 pt-8">
                        <h1 className="font-h1 text-h1 text-primary">
                            Subscription
                        </h1>

                        <p className="mt-2 font-body-lg text-body-lg text-on-surface-variant">
                            Manage your current plan and billing preferences.
                        </p>
                    </header>

                    <div className="grid grid-cols-1 gap-gutter lg:grid-cols-3">

                        {/* Current Plan */}
                        <section className="relative overflow-hidden rounded-xl border border-outline-variant bg-white p-8 shadow-[0_4px_20px_rgba(0,32,91,0.04)] lg:col-span-2">
                            <div className="absolute left-0 top-0 h-px w-full bg-outline-variant">
                                <div className="h-full w-8 bg-secondary" />
                            </div>

                            {subscription ? (
                                <div className="flex h-full flex-col justify-between">

                                    <div>
                                        <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <p className="mb-1 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                                    Current Plan
                                                </p>

                                                <div className="flex flex-wrap items-center gap-3">
                                                    <h2 className="font-h2 text-h2 text-primary">
                                                        {plan.name}
                                                    </h2>

                                                    <StatusBadge
                                                        status={
                                                            subscription.status
                                                        }
                                                    />
                                                </div>
                                            </div>

                                            <div className="sm:text-right">
                                                <p className="font-h3 text-h3 text-primary">
                                                    £
                                                    {Number(
                                                        subscription.price,
                                                    ).toFixed(2)}
                                                </p>

                                                <p className="text-sm text-on-surface-variant">
                                                    per month
                                                </p>
                                            </div>
                                        </div>

                                        <div className="mb-8 space-y-4">
                                            <Benefit>
                                                {
                                                    subscription.monthly_credits
                                                }{' '}
                                                credits every month
                                            </Benefit>

                                            <Benefit>
                                                Credits are added after every
                                                successful billing cycle
                                            </Benefit>

                                            <Benefit>
                                                Unlock full vehicle reports
                                                using your credits
                                            </Benefit>
                                        </div>
                                    </div>

                                    <div className="flex flex-col gap-4 border-t border-outline-variant pt-6 md:flex-row md:items-center md:justify-between">
                                        <div className="text-sm text-on-surface-variant">
                                            {subscription.cancel_at_period_end ? (
                                                <>
                                                    Access available until{' '}
                                                    <span className="font-semibold text-primary">
                                                        {formatDate(
                                                            subscription.current_period_end,
                                                        )}
                                                    </span>
                                                </>
                                            ) : (
                                                <>
                                                    Renews on{' '}
                                                    <span className="font-semibold text-primary">
                                                        {formatDate(
                                                            subscription.current_period_end,
                                                        )}
                                                    </span>
                                                </>
                                            )}
                                        </div>

                                        <div>
                                            {subscription.cancel_at_period_end ? (
                                                <span className="inline-flex rounded bg-surface-container-low px-4 py-2 text-sm font-semibold text-on-surface-variant">
                                                    Cancellation scheduled
                                                </span>
                                            ) : subscription.status ===
                                              'active' ? (
                                                <button
                                                    type="button"
                                                    onClick={
                                                        cancelSubscription
                                                    }
                                                    className="font-label-sm text-label-sm text-secondary transition-colors hover:text-secondary-container"
                                                >
                                                    Cancel Subscription
                                                </button>
                                            ) : null}
                                        </div>
                                    </div>
                                </div>
                            ) : (
                                <>
                                    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p className="mb-1 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                                Available Plan
                                            </p>

                                            <h2 className="font-h2 text-h2 text-primary">
                                                {plan.name}
                                            </h2>
                                        </div>

                                        <div className="sm:text-right">
                                            <p className="font-h3 text-h3 text-primary">
                                                {plan.price.replace(
                                                    '/month',
                                                    '',
                                                )}
                                            </p>

                                            <p className="text-sm text-on-surface-variant">
                                                per month
                                            </p>
                                        </div>
                                    </div>

                                    <div className="mb-8 space-y-4">
                                        <Benefit>
                                            {plan.monthly_credits} credits every
                                            month
                                        </Benefit>

                                        <Benefit>
                                            Automatic monthly credit renewal
                                        </Benefit>

                                        <Benefit>
                                            Cancel anytime
                                        </Benefit>
                                    </div>

                                    <div className="border-t border-outline-variant pt-6">
                                        <button
                                            type="button"
                                            onClick={subscribe}
                                            className="rounded bg-secondary px-6 py-2 font-semibold text-white transition-colors hover:bg-secondary-container"
                                        >
                                            Subscribe
                                        </button>
                                    </div>
                                </>
                            )}
                        </section>

                        {/* Payment Method */}
                        <section className="relative rounded-xl border border-outline-variant bg-white p-8 shadow-[0_4px_20px_rgba(0,32,91,0.04)]">
                            <div className="absolute left-0 top-0 h-px w-full bg-outline-variant">
                                <div className="h-full w-8 bg-secondary" />
                            </div>

                            <h2 className="mb-6 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                Payment Method
                            </h2>

                            {subscription ? (
                                <>
                                    <div className="rounded-lg border border-outline-variant p-4">
                                        <div className="flex items-center gap-4">
                                            <div className="flex h-9 w-12 shrink-0 items-center justify-center rounded border border-outline-variant bg-surface-container-high">
                                                <CreditCard className="h-5 w-5 text-primary" />
                                            </div>

                                            <div>
                                                <p className="font-semibold text-primary">
                                                    {subscription.payment_method ??
                                                        'Managed securely by Stripe'}
                                                </p>

                                                <p className="text-xs text-on-surface-variant">
                                                    Billing details are handled
                                                    securely by Stripe.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-6 flex items-start gap-2 text-xs text-on-surface-variant">
                                        <ShieldCheck className="h-4 w-4 shrink-0" />

                                        <p>
                                            Payment method management will be
                                            available here.
                                        </p>
                                    </div>
                                </>
                            ) : (
                                <div className="rounded-lg border border-outline-variant bg-surface-bright p-5 text-sm text-on-surface-variant">
                                    Subscribe to Premium Membership to manage
                                    your billing method here.
                                </div>
                            )}
                        </section>

                        {/* Recent Invoices */}
                        <section className="relative mt-6 rounded-xl border border-outline-variant bg-white p-8 shadow-[0_4px_20px_rgba(0,32,91,0.04)] lg:col-span-3">
                            <div className="absolute left-0 top-0 h-px w-full bg-outline-variant">
                                <div className="h-full w-8 bg-secondary" />
                            </div>

                            <div className="mb-6 flex items-center justify-between">
                                <h2 className="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                    Recent Invoices
                                </h2>

                                <a
                                    href="/settings/purchase-history"
                                    className="font-label-sm text-label-sm text-primary hover:underline"
                                >
                                    View All
                                </a>
                            </div>

                            <div className="overflow-x-auto">
                                <table className="w-full border-collapse text-left">
                                    <thead>
                                        <tr className="border-b border-outline-variant text-sm text-on-surface-variant">
                                            <th className="py-3 font-normal">
                                                Date
                                            </th>

                                            <th className="py-3 font-normal">
                                                Amount
                                            </th>

                                            <th className="py-3 font-normal">
                                                Plan
                                            </th>

                                            <th className="py-3 text-right font-normal">
                                                Invoice
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {recentInvoices.length > 0 ? (
                                            recentInvoices.map((invoice) => (
                                                <tr
                                                    key={invoice.id}
                                                    className="border-b border-outline-variant transition-colors hover:bg-surface-bright"
                                                >
                                                    <td className="py-4">
                                                        {formatDate(
                                                            invoice.paid_at,
                                                        )}
                                                    </td>

                                                    <td className="py-4 font-semibold">
                                                        {formatMoney(
                                                            invoice.amount,
                                                            invoice.currency,
                                                        )}
                                                    </td>

                                                    <td className="py-4">
                                                        {plan.name}
                                                    </td>

                                                    <td className="py-4 text-right">
                                                        <button
                                                            type="button"
                                                            disabled
                                                            title="Invoice PDF coming soon"
                                                            className="inline-flex cursor-not-allowed items-center gap-1 text-primary opacity-40"
                                                        >
                                                            <Download className="h-4 w-4" />
                                                            PDF
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))
                                        ) : (
                                            <tr>
                                                <td
                                                    colSpan={4}
                                                    className="py-10 text-center text-on-surface-variant"
                                                >
                                                    No subscription invoices
                                                    yet.
                                                </td>
                                            </tr>
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    );
}

function Benefit({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <div className="flex items-start gap-3">
            <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-secondary" />

            <span className="text-on-surface">
                {children}
            </span>
        </div>
    );
}

function StatusBadge({
    status,
}: {
    status: SubscriptionData['status'];
}) {
    const styles: Record<
        SubscriptionData['status'],
        string
    > = {
        active:
            'bg-green-100 text-green-800 border-l-4 border-green-500',

        pending:
            'bg-amber-100 text-amber-800 border-l-4 border-amber-500',

        cancelled:
            'bg-red-100 text-red-800 border-l-4 border-red-500',

        expired:
            'bg-surface-container-high text-on-surface-variant border-l-4 border-outline',
    };

    return (
        <span
            className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${styles[status]}`}
        >
            {status.charAt(0).toUpperCase() +
                status.slice(1)}
        </span>
    );
}

function formatDate(value: string | null) {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function formatMoney(
    amount: string,
    currency: string,
) {
    return new Intl.NumberFormat('en-GB', {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(Number(amount));
}