import { Head, Link } from '@inertiajs/react';
import React from 'react';

import StatCard from '@/components/admin/stat-card';
import StatusBadge from '@/components/admin/status-badge';
import AdminLayout from '@/layouts/admin-layout';

interface CurrencyFinancial {
    currency: string;
    gross: number;
    refunds: number;
    net_collected: number;
    transaction_count: number;
}

interface DashboardProps {
    stats: {
        customers: {
            total: number;
            new_last_30_days: number;
            suspended: number;
        };
        subscriptions: {
            active: number;
        };
        reports: {
            total: number;
            last_30_days: number;
            vin_checks_success: number;
            vin_checks_failed: number;
            vin_checks_pending: number;
        };
        guest_fulfillment: {
            pending: number;
            processing: number;
            completed: number;
            failed: number;
        };
        financial: {
            all_time: Record<string, CurrencyFinancial>;
            last_30_days: Record<string, CurrencyFinancial>;
        };
    };
    recentTransactions: Array<{
        id: number | string;
        reference: string;
        source_type: string;
        customer_name: string;
        customer_email: string;
        amount: number;
        currency: string;
        status: string;
        category: string;
        payment_method: string;
        created_at: string;
    }>;
    recentReports: Array<{
        id: number;
        registration_number: string;
        vin: string;
        report_type: string;
        customer_name: string;
        customer_email: string;
        status: string;
        created_at: string;
    }>;
}

export default function Dashboard({
    stats,
    recentTransactions,
    recentReports,
}: DashboardProps) {
    const financialCurrencies = Object.values(stats.financial.all_time || {});
    const primaryFinancial = financialCurrencies[0] || {
        currency: 'GBP',
        gross: 0,
        refunds: 0,
        net_collected: 0,
        transaction_count: 0,
    };

    return (
        <AdminLayout
            title="Operational Overview"
            subtitle="Real-time business metrics and system operational activity"
        >
            <Head title="Admin Overview - VinCheck" />

            {/* Top Stat Cards */}
            <div className="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    title={`Net Collected (${primaryFinancial.currency})`}
                    value={`${primaryFinancial.currency === 'GBP' ? '£' : ''}${primaryFinancial.net_collected.toLocaleString('en-GB', { minimumFractionDigits: 2 })}`}
                    subtitle={`Gross: £${primaryFinancial.gross.toFixed(2)} | Refunds: £${primaryFinancial.refunds.toFixed(2)}`}
                    icon="💷"
                    variant="primary"
                />
                <StatCard
                    title="Active Subscriptions"
                    value={stats.subscriptions.active}
                    subtitle="Current ongoing renewals"
                    icon="🔄"
                />
                <StatCard
                    title="Registered Customers"
                    value={stats.customers.total}
                    subtitle={`+${stats.customers.new_last_30_days} in last 30 days (${stats.customers.suspended} suspended)`}
                    icon="👥"
                />
                <StatCard
                    title="Vehicle Reports Total"
                    value={stats.reports.total}
                    subtitle={`+${stats.reports.last_30_days} generated last 30d`}
                    icon="📄"
                />
            </div>

            {/* Currency Breakdown if multi-currency exists */}
            {financialCurrencies.length > 1 && (
                <div className="mb-8 rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                    <h3 className="mb-3 font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        Revenue Breakdown by Currency (Separated)
                    </h3>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        {financialCurrencies.map((fc) => (
                            <div
                                key={fc.currency}
                                className="rounded-lg border border-outline-variant bg-surface p-3"
                            >
                                <span className="text-xs font-bold text-primary">
                                    {fc.currency}
                                </span>
                                <div className="mt-1 font-sans text-base font-extrabold text-on-surface">
                                    Net: {fc.net_collected.toFixed(2)}{' '}
                                    {fc.currency}
                                </div>
                                <div className="mt-0.5 text-[11px] text-on-surface-variant">
                                    Gross: {fc.gross.toFixed(2)} | Refunds:{' '}
                                    {fc.refunds.toFixed(2)}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Operational Status Panels */}
            <div className="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/* Vehicle Checks Processing */}
                <div className="rounded-xl border border-outline-variant bg-white p-6 shadow-xs">
                    <h3 className="mb-4 flex items-center justify-between font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        <span>Vehicle Check Outflows</span>
                        <span>🚗</span>
                    </h3>
                    <div className="space-y-3">
                        <div className="flex items-center justify-between border-b border-surface-container py-2">
                            <span className="text-xs text-on-surface">
                                Successful Checks
                            </span>
                            <span className="font-mono text-xs font-bold text-emerald-600">
                                {stats.reports.vin_checks_success}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b border-surface-container py-2">
                            <span className="text-xs text-on-surface">
                                Failed Checks
                            </span>
                            <span className="font-mono text-xs font-bold text-error">
                                {stats.reports.vin_checks_failed}
                            </span>
                        </div>
                        <div className="flex items-center justify-between py-2">
                            <span className="text-xs text-on-surface">
                                Queued / Processing
                            </span>
                            <span className="font-mono text-xs font-bold text-amber-600">
                                {stats.reports.vin_checks_pending}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Guest Purchases Fulfillment */}
                <div className="rounded-xl border border-outline-variant bg-white p-6 shadow-xs">
                    <h3 className="mb-4 flex items-center justify-between font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        <span>Guest Purchases Fulfillment</span>
                        <span>✉️</span>
                    </h3>
                    <div className="space-y-3">
                        <div className="flex items-center justify-between border-b border-surface-container py-2">
                            <span className="text-xs text-on-surface">
                                Fulfilled (Delivered)
                            </span>
                            <span className="font-mono text-xs font-bold text-emerald-600">
                                {stats.guest_fulfillment.completed}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b border-surface-container py-2">
                            <span className="text-xs text-on-surface">
                                Processing / Pending
                            </span>
                            <span className="font-mono text-xs font-bold text-amber-600">
                                {stats.guest_fulfillment.processing +
                                    stats.guest_fulfillment.pending}
                            </span>
                        </div>
                        <div className="flex items-center justify-between py-2">
                            <span className="text-xs text-on-surface">
                                Failed Fulfillment
                            </span>
                            <span className="font-mono text-xs font-bold text-error">
                                {stats.guest_fulfillment.failed}
                            </span>
                        </div>
                    </div>
                </div>

                {/* Account Operations Summary */}
                <div className="rounded-xl border border-outline-variant bg-white p-6 shadow-xs">
                    <h3 className="mb-4 flex items-center justify-between font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        <span>Customer Status</span>
                        <span>🛡️</span>
                    </h3>
                    <div className="space-y-3">
                        <div className="flex items-center justify-between border-b border-surface-container py-2">
                            <span className="text-xs text-on-surface">
                                Active Accounts
                            </span>
                            <span className="font-mono text-xs font-bold text-emerald-600">
                                {stats.customers.total -
                                    stats.customers.suspended}
                            </span>
                        </div>
                        <div className="flex items-center justify-between border-b border-surface-container py-2">
                            <span className="text-xs text-on-surface">
                                Suspended Accounts
                            </span>
                            <span className="font-mono text-xs font-bold text-error">
                                {stats.customers.suspended}
                            </span>
                        </div>
                        <div className="flex items-center justify-between py-2">
                            <span className="text-xs text-on-surface">
                                30d Growth Ratio
                            </span>
                            <span className="font-mono text-xs font-bold text-primary">
                                {stats.customers.total > 0
                                    ? `${Math.round((stats.customers.new_last_30_days / stats.customers.total) * 100)}%`
                                    : '0%'}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Recent Tables Grid */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Recent Transactions */}
                <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                            Recent Transactions
                        </h3>
                        <Link
                            href="/admin/transactions"
                            className="text-xs font-semibold text-primary hover:underline"
                        >
                            View All →
                        </Link>
                    </div>

                    {recentTransactions.length === 0 ? (
                        <div className="py-8 text-center text-xs text-on-surface-variant">
                            No transactions recorded yet.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-outline-variant/60 text-on-surface-variant">
                                        <th className="pb-2 font-semibold">
                                            Reference
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Customer
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Amount
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-container">
                                    {recentTransactions.map((tx) => (
                                        <tr
                                            key={tx.id}
                                            className="hover:bg-surface/50"
                                        >
                                            <td className="py-2.5 font-mono text-[11px] font-semibold text-primary">
                                                {tx.reference}
                                                <span className="block text-[10px] font-normal text-on-surface-variant">
                                                    {tx.source_type}
                                                </span>
                                            </td>
                                            <td className="py-2.5">
                                                <span className="block font-medium text-on-surface">
                                                    {tx.customer_name}
                                                </span>
                                                <span className="block text-[10px] text-on-surface-variant">
                                                    {tx.customer_email}
                                                </span>
                                            </td>
                                            <td className="py-2.5 font-mono font-bold">
                                                {tx.currency === 'GBP'
                                                    ? '£'
                                                    : ''}
                                                {tx.amount.toFixed(2)}
                                            </td>
                                            <td className="py-2.5">
                                                <StatusBadge
                                                    status={tx.status}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {/* Recent Reports */}
                <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                            Recent Vehicle Reports
                        </h3>
                        <Link
                            href="/admin/reports"
                            className="text-xs font-semibold text-primary hover:underline"
                        >
                            View All →
                        </Link>
                    </div>

                    {recentReports.length === 0 ? (
                        <div className="py-8 text-center text-xs text-on-surface-variant">
                            No vehicle reports generated yet.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-outline-variant/60 text-on-surface-variant">
                                        <th className="pb-2 font-semibold">
                                            Reg / VIN
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Type
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Customer
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-container">
                                    {recentReports.map((rep) => (
                                        <tr
                                            key={rep.id}
                                            className="hover:bg-surface/50"
                                        >
                                            <td className="py-2.5">
                                                <span className="block font-mono font-bold text-primary">
                                                    {rep.registration_number}
                                                </span>
                                                <span className="block font-mono text-[10px] text-on-surface-variant">
                                                    {rep.vin !== '-'
                                                        ? rep.vin
                                                        : 'No VIN'}
                                                </span>
                                            </td>
                                            <td className="py-2.5 text-[10px] font-semibold text-on-surface-variant uppercase">
                                                {rep.report_type}
                                            </td>
                                            <td className="py-2.5">
                                                <span className="block font-medium text-on-surface">
                                                    {rep.customer_name}
                                                </span>
                                                <span className="block text-[10px] text-on-surface-variant">
                                                    {rep.customer_email}
                                                </span>
                                            </td>
                                            <td className="py-2.5">
                                                <StatusBadge
                                                    status={rep.status}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
