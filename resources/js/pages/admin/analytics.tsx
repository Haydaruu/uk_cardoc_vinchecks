import { Head, router } from '@inertiajs/react';
import React from 'react';

import StatCard from '@/components/admin/stat-card';
import AdminLayout from '@/layouts/admin-layout';

interface CurrencySummary {
    currency: string;
    gross: number;
    refunds: number;
    net_collected: number;
    transaction_count: number;
}

interface AnalyticsPageProps {
    period: string;
    financialSummary: Record<string, CurrencySummary>;
    customerRegistrations: Array<{
        date: string;
        count: number;
    }>;
    vinChecksSummary: {
        success: number;
        failed: number;
        pending: number;
        total: number;
        success_rate: number;
    };
    subscriptionDistribution: Array<{
        plan_name: string;
        count: number;
    }>;
}

export default function Analytics({
    period,
    financialSummary,
    customerRegistrations,
    vinChecksSummary,
    subscriptionDistribution,
}: AnalyticsPageProps) {
    const currencies = Object.values(financialSummary || {});
    const primaryCurrency = currencies[0] || {
        currency: 'GBP',
        gross: 0,
        refunds: 0,
        net_collected: 0,
        transaction_count: 0,
    };

    const handlePeriodChange = (newPeriod: string) => {
        router.get(
            '/admin/analytics',
            { period: newPeriod },
            { preserveState: true, preserveScroll: true },
        );
    };

    const totalRegistrationsInPeriod = customerRegistrations.reduce(
        (acc, curr) => acc + curr.count,
        0,
    );

    return (
        <AdminLayout
            title="Business & Operations Analytics"
            subtitle="Financial metrics, customer acquisition trajectories, and report performance"
        >
            <Head title="Analytics - VinCheck Admin" />

            {/* Period Selector Tabs */}
            <div className="mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                <div>
                    <h2 className="font-sans text-sm font-bold tracking-wider text-on-surface uppercase">
                        Reporting Period: {period.toUpperCase()}
                    </h2>
                    <span className="text-xs text-on-surface-variant">
                        Aggregated from verifiable database transactions and
                        check logs
                    </span>
                </div>

                <div className="flex items-center gap-1 rounded-lg border border-outline-variant bg-white p-1 shadow-xs">
                    {['7d', '30d', '90d', '1y'].map((p) => (
                        <button
                            key={p}
                            onClick={() => handlePeriodChange(p)}
                            type="button"
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold transition-colors ${
                                period === p
                                    ? 'bg-primary text-white shadow-xs'
                                    : 'text-on-surface-variant hover:bg-surface-container hover:text-on-surface'
                            }`}
                        >
                            {p.toUpperCase()}
                        </button>
                    ))}
                </div>
            </div>

            {/* Financial Domain Cards */}
            <div className="mb-8 grid grid-cols-1 gap-4 md:grid-cols-3">
                <StatCard
                    title={`Gross Payments (${primaryCurrency.currency})`}
                    value={`${primaryCurrency.currency === 'GBP' ? '£' : ''}${primaryCurrency.gross.toFixed(2)}`}
                    subtitle={`${primaryCurrency.transaction_count} successful payment transactions`}
                    icon="📥"
                />
                <StatCard
                    title={`Refunds (${primaryCurrency.currency})`}
                    value={`${primaryCurrency.currency === 'GBP' ? '£' : ''}${primaryCurrency.refunds.toFixed(2)}`}
                    subtitle="Confirmed returned payment transactions"
                    icon="📤"
                />
                <StatCard
                    title={`Net Collected (${primaryCurrency.currency})`}
                    value={`${primaryCurrency.currency === 'GBP' ? '£' : ''}${primaryCurrency.net_collected.toFixed(2)}`}
                    subtitle="Gross payments minus refunds (Not Net Profit)"
                    variant="primary"
                    icon="💷"
                />
            </div>

            {/* Additional Currencies Grid if present */}
            {currencies.length > 1 && (
                <div className="mb-8 rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                    <h3 className="mb-3 font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        Multi-Currency Revenue Summary (Separated per Currency)
                    </h3>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        {currencies.map((curr) => (
                            <div
                                key={curr.currency}
                                className="rounded-lg border border-outline-variant bg-surface p-3"
                            >
                                <span className="text-xs font-bold text-primary">
                                    {curr.currency}
                                </span>
                                <div className="mt-1 font-sans text-base font-extrabold text-on-surface">
                                    Net: {curr.net_collected.toFixed(2)}{' '}
                                    {curr.currency}
                                </div>
                                <div className="mt-0.5 text-[11px] text-on-surface-variant">
                                    Gross: {curr.gross.toFixed(2)} | Refunds:{' '}
                                    {curr.refunds.toFixed(2)}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Reports Outcome & Customer Growth Row */}
            <div className="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Reports / Vehicle Checks Domain */}
                <div className="rounded-xl border border-outline-variant bg-white p-6 shadow-xs">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                            Vehicle Checks Performance
                        </h3>
                        <span className="rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-mono text-xs font-bold text-emerald-800">
                            {vinChecksSummary.success_rate}% Success Rate
                        </span>
                    </div>

                    <div className="space-y-4">
                        <div>
                            <div className="mb-1.5 flex justify-between text-xs font-medium">
                                <span className="text-on-surface">
                                    Successful Checks:{' '}
                                    {vinChecksSummary.success}
                                </span>
                                <span className="font-mono font-bold text-emerald-600">
                                    {vinChecksSummary.total > 0
                                        ? `${Math.round((vinChecksSummary.success / vinChecksSummary.total) * 100)}%`
                                        : '0%'}
                                </span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-surface-container">
                                <div
                                    className="h-2 rounded-full bg-emerald-500"
                                    style={{
                                        width: `${
                                            vinChecksSummary.total > 0
                                                ? (vinChecksSummary.success /
                                                      vinChecksSummary.total) *
                                                  100
                                                : 0
                                        }%`,
                                    }}
                                />
                            </div>
                        </div>

                        <div>
                            <div className="mb-1.5 flex justify-between text-xs font-medium">
                                <span className="text-on-surface">
                                    Failed Checks: {vinChecksSummary.failed}
                                </span>
                                <span className="font-mono font-bold text-error">
                                    {vinChecksSummary.total > 0
                                        ? `${Math.round((vinChecksSummary.failed / vinChecksSummary.total) * 100)}%`
                                        : '0%'}
                                </span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-surface-container">
                                <div
                                    className="h-2 rounded-full bg-error"
                                    style={{
                                        width: `${
                                            vinChecksSummary.total > 0
                                                ? (vinChecksSummary.failed /
                                                      vinChecksSummary.total) *
                                                  100
                                                : 0
                                        }%`,
                                    }}
                                />
                            </div>
                        </div>

                        <div>
                            <div className="mb-1.5 flex justify-between text-xs font-medium">
                                <span className="text-on-surface">
                                    Queued / Processing:{' '}
                                    {vinChecksSummary.pending}
                                </span>
                                <span className="font-mono font-bold text-amber-600">
                                    {vinChecksSummary.total > 0
                                        ? `${Math.round((vinChecksSummary.pending / vinChecksSummary.total) * 100)}%`
                                        : '0%'}
                                </span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-surface-container">
                                <div
                                    className="h-2 rounded-full bg-amber-500"
                                    style={{
                                        width: `${
                                            vinChecksSummary.total > 0
                                                ? (vinChecksSummary.pending /
                                                      vinChecksSummary.total) *
                                                  100
                                                : 0
                                        }%`,
                                    }}
                                />
                            </div>
                        </div>

                        <div className="flex items-center justify-between border-t border-outline-variant/60 pt-2 text-xs text-on-surface-variant">
                            <span>Total checks requested in period:</span>
                            <span className="font-mono font-bold text-on-surface">
                                {vinChecksSummary.total}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Customer Growth Trajectory */}
                <div className="rounded-xl border border-outline-variant bg-white p-6 shadow-xs">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                            Customer Registrations Trajectory
                        </h3>
                        <span className="font-mono text-xs font-bold text-primary">
                            +{totalRegistrationsInPeriod} users in period
                        </span>
                    </div>

                    {customerRegistrations.length === 0 ? (
                        <div className="py-12 text-center text-xs text-on-surface-variant">
                            No new customer registrations in the selected
                            period.
                        </div>
                    ) : (
                        <div className="max-h-[220px] space-y-2 overflow-y-auto pr-1">
                            {customerRegistrations.map((reg) => (
                                <div
                                    key={reg.date}
                                    className="flex items-center justify-between rounded border border-outline-variant bg-surface p-2 text-xs"
                                >
                                    <span className="font-mono font-medium text-on-surface">
                                        {new Date(reg.date).toLocaleDateString(
                                            'en-GB',
                                            {
                                                day: 'numeric',
                                                month: 'short',
                                                year: 'numeric',
                                            },
                                        )}
                                    </span>
                                    <span className="rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-mono font-bold text-emerald-600">
                                        +{reg.count} users
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Subscriptions Domain */}
            <div className="rounded-xl border border-outline-variant bg-white p-6 shadow-xs">
                <h3 className="mb-4 font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                    Active Subscription Tier Breakdown
                </h3>

                {subscriptionDistribution.length === 0 ? (
                    <div className="py-8 text-center text-xs text-on-surface-variant">
                        No active subscriptions currently recorded in the
                        database.
                    </div>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-4">
                        {subscriptionDistribution.map((sub) => (
                            <div
                                key={sub.plan_name}
                                className="rounded-xl border border-outline-variant bg-surface p-4"
                            >
                                <span className="block text-xs font-semibold text-on-surface-variant">
                                    {sub.plan_name}
                                </span>
                                <span className="mt-1 block font-mono font-sans text-2xl font-extrabold text-primary">
                                    {sub.count}
                                </span>
                                <span className="mt-0.5 block text-[11px] text-on-surface-variant">
                                    Active accounts
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
