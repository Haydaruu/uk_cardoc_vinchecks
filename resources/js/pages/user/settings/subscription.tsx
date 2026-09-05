import SettingsLayout from '@/layouts/settings/settings-layout';
import { Head, router } from '@inertiajs/react';
import {
    CheckCircle2,
    CreditCard,
    Download,
    ShieldCheck,
    X,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

type AvailablePlan = {
    slug: string;
    name: string;
    price: string;
    monthly_credits: number;
};

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

type CurrentPlan = {
    name: string;
    price: string;
    monthly_credits: number;
};

type Props = {
    subscription: SubscriptionData | null;
    plan: CurrentPlan | null;
    availablePlans: AvailablePlan[];
    recentInvoices?: Invoice[];
};

export default function Subscription({
    subscription,
    plan,
    availablePlans = [],
    recentInvoices = [],
}: Props) {
    const [changePlanOpen, setChangePlanOpen] = useState(false);

    const [changingPlan, setChangingPlan] =
        useState<string | null>(null);

    const [isSubscribing, setIsSubscribing] =
        useState(false);

    /*
     * Fallback untuk user yang belum punya subscription.
     * Default ke plan pertama dari backend.
     */
    const defaultPlan =
        availablePlans[0] ?? null;

    const displayPlan =
        plan ??
        (defaultPlan
            ? {
                  name: defaultPlan.name,
                  price: defaultPlan.price,
                  monthly_credits:
                      defaultPlan.monthly_credits,
              }
            : null);

    const subscribe = () => {
        if (!defaultPlan) {
            return;
        }

        router.post(
            '/settings/subscription/checkout',
            {
                plan: defaultPlan.slug,
            },
            {
                preserveScroll: true,

                onStart: () => {
                    setIsSubscribing(true);
                },

                onFinish: () => {
                    setIsSubscribing(false);
                },
            },
        );
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

    const changePlan = (planSlug: string) => {
        setChangingPlan(planSlug);

        router.patch(
            '/settings/subscription/plan',
            {
                plan: planSlug,
            },
            {
                preserveScroll: true,

                onSuccess: () => {
                    setChangePlanOpen(false);
                },

                onFinish: () => {
                    setChangingPlan(null);
                },
            },
        );
    };

    return (
        <SettingsLayout>
            <Head title="Subscription" />

            <div className="min-h-screen bg-surface">
                <div className="mx-auto w-full max-w-container-max px-margin-mobile pb-12 md:px-gutter">
                    {/* Header */}
                    <header className="mb-12 border-b border-outline-variant pb-6 pt-8">
                        <h1 className="font-h1 text-h1 text-primary">
                            Subscription
                        </h1>

                        <p className="mt-2 font-body-lg text-body-lg text-on-surface-variant">
                            Manage your current plan and billing
                            preferences.
                        </p>
                    </header>

                    <div className="grid grid-cols-1 gap-gutter lg:grid-cols-3">
                        {/* Current Plan */}
                        <section className="relative overflow-hidden rounded-xl border border-outline-variant bg-white p-8 shadow-[0_4px_20px_rgba(0,32,91,0.04)] lg:col-span-2">
                            <div className="absolute left-0 top-0 h-px w-full bg-outline-variant">
                                <div className="h-full w-8 bg-secondary" />
                            </div>

                            {subscription &&
                            displayPlan ? (
                                <div className="flex h-full flex-col justify-between">
                                    <div>
                                        {/* Plan Header */}
                                        <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <p className="mb-1 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                                    Current Plan
                                                </p>

                                                <div className="flex flex-wrap items-center gap-3">
                                                    <h2 className="font-h2 text-h2 text-primary">
                                                        {
                                                            displayPlan.name
                                                        }
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
                                                    ).toFixed(
                                                        2,
                                                    )}
                                                </p>

                                                <p className="text-sm text-on-surface-variant">
                                                    per month
                                                </p>
                                            </div>
                                        </div>

                                        {/* Benefits */}
                                        <div className="mb-8 space-y-4">
                                            <Benefit>
                                                {
                                                    subscription.monthly_credits
                                                }{' '}
                                                credits every
                                                month
                                            </Benefit>

                                            <Benefit>
                                                Credits are added
                                                after every
                                                successful
                                                billing cycle
                                            </Benefit>

                                            <Benefit>
                                                Unused credits
                                                never expire
                                            </Benefit>

                                            <Benefit>
                                                Unlock full
                                                vehicle reports
                                                using your
                                                credits
                                            </Benefit>
                                        </div>
                                    </div>

                                    {/* Billing / Actions */}
                                    <div className="flex flex-col gap-4 border-t border-outline-variant pt-6 md:flex-row md:items-center md:justify-between">
                                        <div className="text-sm text-on-surface-variant">
                                            {subscription.cancel_at_period_end ? (
                                                <>
                                                    Access
                                                    available
                                                    until{' '}
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

                                        {subscription.cancel_at_period_end ? (
                                            <span className="inline-flex rounded bg-surface-container-low px-4 py-2 text-sm font-semibold text-on-surface-variant">
                                                Cancellation
                                                scheduled
                                            </span>
                                        ) : subscription.status ===
                                          'active' ? (
                                            <div className="flex items-center gap-5">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setChangePlanOpen(
                                                            true,
                                                        )
                                                    }
                                                    className="font-label-sm text-label-sm font-semibold text-primary transition-colors hover:underline"
                                                >
                                                    Change Plan
                                                </button>

                                                <button
                                                    type="button"
                                                    onClick={
                                                        cancelSubscription
                                                    }
                                                    className="font-label-sm text-label-sm text-secondary transition-colors hover:text-secondary-container"
                                                >
                                                    Cancel
                                                    Subscription
                                                </button>
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                            ) : displayPlan ? (
                                /*
                                 * User belum mempunyai subscription.
                                 */
                                <>
                                    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p className="mb-1 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                                Available Plan
                                            </p>

                                            <h2 className="font-h2 text-h2 text-primary">
                                                {
                                                    displayPlan.name
                                                }
                                            </h2>
                                        </div>

                                        <div className="sm:text-right">
                                            <p className="font-h3 text-h3 text-primary">
                                                {displayPlan.price.replace(
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
                                            {
                                                displayPlan.monthly_credits
                                            }{' '}
                                            credits every month
                                        </Benefit>

                                        <Benefit>
                                            Automatic monthly
                                            credit renewal
                                        </Benefit>

                                        <Benefit>
                                            Unused credits never
                                            expire
                                        </Benefit>

                                        <Benefit>
                                            Cancel anytime
                                        </Benefit>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-4 border-t border-outline-variant pt-6">
                                        <button
                                            type="button"
                                            disabled={
                                                isSubscribing
                                            }
                                            onClick={subscribe}
                                            className="rounded bg-secondary px-6 py-2 font-semibold text-white transition-colors hover:bg-secondary-container disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {isSubscribing
                                                ? 'Redirecting...'
                                                : 'Subscribe'}
                                        </button>

                                        {availablePlans.length >
                                            1 && (
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setChangePlanOpen(
                                                        true,
                                                    )
                                                }
                                                className="font-semibold text-primary hover:underline"
                                            >
                                                View other plans
                                            </button>
                                        )}
                                    </div>
                                </>
                            ) : (
                                <div className="py-12 text-center text-on-surface-variant">
                                    No subscription plans are
                                    currently available.
                                </div>
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
                                                    Billing
                                                    details are
                                                    handled
                                                    securely by
                                                    Stripe.
                                                </p>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="mt-6 flex items-start gap-2 text-xs text-on-surface-variant">
                                        <ShieldCheck className="h-4 w-4 shrink-0" />

                                        <p>
                                            Payment method
                                            management will be
                                            available here.
                                        </p>
                                    </div>
                                </>
                            ) : (
                                <div className="rounded-lg border border-outline-variant bg-surface-bright p-5 text-sm text-on-surface-variant">
                                    Subscribe to a membership
                                    plan to manage your billing
                                    method here.
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
                                        {recentInvoices.length >
                                        0 ? (
                                            recentInvoices.map(
                                                (
                                                    invoice,
                                                ) => (
                                                    <tr
                                                        key={
                                                            invoice.id
                                                        }
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
                                                            {invoice.description ??
                                                                displayPlan?.name ??
                                                                'Membership'}
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
                                                ),
                                            )
                                        ) : (
                                            <tr>
                                                <td
                                                    colSpan={
                                                        4
                                                    }
                                                    className="py-10 text-center text-on-surface-variant"
                                                >
                                                    No
                                                    subscription
                                                    invoices yet.
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

            {/* Change Plan Modal */}
            {changePlanOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
                    onClick={() => {
                        if (!changingPlan) {
                            setChangePlanOpen(false);
                        }
                    }}
                >
                    <div
                        className="w-full max-w-3xl rounded-xl bg-white p-8 shadow-xl"
                        onClick={(event) =>
                            event.stopPropagation()
                        }
                    >
                        {/* Modal Header */}
                        <div className="mb-8 flex items-start justify-between gap-6">
                            <div>
                                <p className="mb-1 font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                                    Membership
                                </p>

                                <h2 className="text-2xl font-bold text-primary">
                                    {subscription
                                        ? 'Change Membership Plan'
                                        : 'Choose Membership Plan'}
                                </h2>

                                <p className="mt-2 max-w-xl text-sm leading-relaxed text-on-surface-variant">
                                    {subscription
                                        ? 'Choose the plan you want to use for your next billing cycle. Your existing credits will remain in your account.'
                                        : 'Choose the membership plan that works best for you.'}
                                </p>
                            </div>

                            <button
                                type="button"
                                disabled={
                                    changingPlan !== null
                                }
                                onClick={() =>
                                    setChangePlanOpen(
                                        false,
                                    )
                                }
                                className="flex size-9 shrink-0 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-surface-container-low hover:text-primary disabled:opacity-40"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        {/* Plans */}
                        <div className="grid gap-4 md:grid-cols-3">
                            {availablePlans.map(
                                (availablePlan) => {
                                    const isCurrent =
                                        subscription?.plan_name ===
                                        availablePlan.slug;

                                    const isChanging =
                                        changingPlan ===
                                        availablePlan.slug;

                                    return (
                                        <button
                                            key={
                                                availablePlan.slug
                                            }
                                            type="button"
                                            disabled={
                                                isCurrent ||
                                                changingPlan !==
                                                    null
                                            }
                                            onClick={() => {
                                                /*
                                                 * Existing user:
                                                 * change subscription.
                                                 */
                                                if (
                                                    subscription
                                                ) {
                                                    changePlan(
                                                        availablePlan.slug,
                                                    );

                                                    return;
                                                }

                                                /*
                                                 * No subscription:
                                                 * create checkout for
                                                 * selected plan.
                                                 */
                                                setChangingPlan(
                                                    availablePlan.slug,
                                                );

                                                router.post(
                                                    '/settings/subscription/checkout',
                                                    {
                                                        plan: availablePlan.slug,
                                                    },
                                                    {
                                                        onFinish:
                                                            () => {
                                                                setChangingPlan(
                                                                    null,
                                                                );
                                                            },
                                                    },
                                                );
                                            }}
                                            className={`relative flex min-h-[190px] flex-col rounded-lg border p-5 text-left transition-all ${
                                                isCurrent
                                                    ? 'border-secondary bg-secondary/5 shadow-sm'
                                                    : 'border-outline-variant bg-white hover:border-primary hover:shadow-sm'
                                            } disabled:cursor-not-allowed`}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <p className="font-bold text-primary">
                                                    {
                                                        availablePlan.name
                                                    }
                                                </p>

                                                {isCurrent && (
                                                    <span className="rounded-full bg-secondary px-2 py-1 text-[9px] font-bold uppercase tracking-wider text-white">
                                                        Current
                                                    </span>
                                                )}
                                            </div>

                                            <p className="mt-5 text-2xl font-black text-primary">
                                                {
                                                    availablePlan.price
                                                }
                                            </p>

                                            <p className="mt-1 text-xs text-on-surface-variant">
                                                billed monthly
                                            </p>

                                            <div className="mt-auto border-t border-outline-variant pt-4">
                                                <p className="text-sm font-semibold text-primary">
                                                    {
                                                        availablePlan.monthly_credits
                                                    }{' '}
                                                    credits /
                                                    month
                                                </p>

                                                {!isCurrent && (
                                                    <p className="mt-2 text-xs font-medium text-secondary">
                                                        {isChanging
                                                            ? 'Updating...'
                                                            : subscription
                                                              ? 'Select plan'
                                                              : 'Subscribe'}
                                                    </p>
                                                )}
                                            </div>
                                        </button>
                                    );
                                },
                            )}
                        </div>

                        {/* Modal Footer */}
                        <div className="mt-8 flex flex-col gap-3 border-t border-outline-variant pt-6 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-xs text-on-surface-variant">
                                No credits are removed when
                                changing your membership.
                            </p>

                            <button
                                type="button"
                                disabled={
                                    changingPlan !== null
                                }
                                onClick={() =>
                                    setChangePlanOpen(
                                        false,
                                    )
                                }
                                className="rounded border border-outline-variant px-5 py-2 text-sm font-semibold text-primary transition-colors hover:bg-surface-container-low disabled:opacity-40"
                            >
                                Close
                            </button>
                        </div>
                    </div>
                </div>
            )}
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
            'border-l-4 border-green-500 bg-green-100 text-green-800',

        pending:
            'border-l-4 border-amber-500 bg-amber-100 text-amber-800',

        cancelled:
            'border-l-4 border-red-500 bg-red-100 text-red-800',

        expired:
            'border-l-4 border-outline bg-surface-container-high text-on-surface-variant',
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