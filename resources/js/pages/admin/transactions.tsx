import { Head, usePage } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, CheckCircle, RotateCcw } from 'lucide-react';
import React, { useState } from 'react';

import EmptyState from '@/components/admin/empty-state';
import Pagination from '@/components/admin/pagination';
import RefundModal from '@/components/admin/refund-modal';
import SearchFilterBar from '@/components/admin/search-filter-bar';
import StatCard from '@/components/admin/stat-card';
import StatusBadge from '@/components/admin/status-badge';
import AdminLayout from '@/layouts/admin-layout';

interface TransactionItem {
    id: string;
    raw_id: number;
    reference: string;
    source_type: 'registered' | 'guest';
    customer_id?: number | null;
    customer_name: string;
    customer_email: string;
    amount: number;
    currency: string;
    status: string;
    category: string;
    payment_method: string;
    payment_gateway_ref?: string | null;
    paid_at?: string | null;
    refunded_at?: string | null;
    created_at: string;
}

interface TransactionsPageProps {
    transactions: {
        data: TransactionItem[];
        links: any[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        source: string;
        status: string;
        currency: string;
        search: string;
    };
    financialSummary: Record<
        string,
        {
            currency: string;
            gross: number;
            refunds: number;
            net_collected: number;
            transaction_count: number;
        }
    >;
}

export default function Transactions({
    transactions,
    filters,
    financialSummary,
}: TransactionsPageProps) {
    const { flash } = usePage<{
        flash?: {
            success?: string | null;
            error?: string | null;
            warning?: string | null;
        };
    }>().props;

    const [refundTarget, setRefundTarget] = useState<TransactionItem | null>(
        null,
    );

    const summaryList = Object.values(financialSummary || {});
    const primaryCurrency = summaryList[0] || {
        currency: 'GBP',
        gross: 0,
        refunds: 0,
        net_collected: 0,
        transaction_count: 0,
    };

    const filterOptions = [
        {
            name: 'source',
            label: 'Source',
            value: filters.source,
            options: [
                { label: 'All Sources', value: 'all' },
                { label: 'Registered Customer', value: 'registered' },
                { label: 'Guest Purchase', value: 'guest' },
            ],
        },
        {
            name: 'status',
            label: 'Status',
            value: filters.status,
            options: [
                { label: 'All Statuses', value: 'all' },
                { label: 'Success / Paid', value: 'success' },
                { label: 'Refunded', value: 'refunded' },
                { label: 'Pending', value: 'pending' },
                { label: 'Failed', value: 'failed' },
            ],
        },
        {
            name: 'currency',
            label: 'Currency',
            value: filters.currency,
            options: [
                { label: 'All Currencies', value: 'all' },
                { label: 'GBP', value: 'GBP' },
                { label: 'USD', value: 'USD' },
                { label: 'EUR', value: 'EUR' },
            ],
        },
    ];

    return (
        <AdminLayout
            title="Transactions"
            subtitle="Customer payments, guest purchases, and refund records"
        >
            <Head title="Transactions - VinCheck Admin" />

            {/* Flash notification banners */}
            {flash?.success && (
                <div className="mb-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 shadow-xs">
                    <CheckCircle className="h-4 w-4 shrink-0 text-emerald-600" />
                    <span>{flash.success}</span>
                </div>
            )}
            {flash?.warning && (
                <div className="mb-4 flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs font-semibold text-amber-800 shadow-xs">
                    <AlertTriangle className="h-4 w-4 shrink-0 text-amber-600" />
                    <span>{flash.warning}</span>
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 flex items-center gap-3 rounded-xl border border-error/30 bg-error-container/30 p-4 text-xs font-semibold text-error shadow-xs">
                    <AlertCircle className="h-4 w-4 shrink-0 text-error" />
                    <span>{flash.error}</span>
                </div>
            )}

            {/* Financial Summary Cards */}
            <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                <StatCard
                    title={`Gross Payments (${primaryCurrency.currency})`}
                    value={`${primaryCurrency.currency === 'GBP' ? '£' : ''}${primaryCurrency.gross.toFixed(2)}`}
                    subtitle="Confirmed successful payment volume"
                    icon="📥"
                />
                <StatCard
                    title={`Refunds (${primaryCurrency.currency})`}
                    value={`${primaryCurrency.currency === 'GBP' ? '£' : ''}${primaryCurrency.refunds.toFixed(2)}`}
                    subtitle="Confirmed returned payment volume"
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

            {/* Search and Filters */}
            <SearchFilterBar
                baseUrl="/admin/transactions"
                searchPlaceholder="Search by reference, gateway ID, customer name or email..."
                searchValue={filters.search}
                filters={filterOptions}
            />

            {/* Transactions Table */}
            <div className="overflow-hidden rounded-xl border border-outline-variant bg-white shadow-xs">
                {transactions.data.length === 0 ? (
                    <EmptyState
                        title="No transactions found"
                        description="Try modifying search keywords or clearing filter parameters."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-outline-variant/60 bg-surface text-[11px] tracking-wider text-on-surface-variant uppercase">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">
                                        Reference
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Customer / Guest
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Type / Item
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Gateway
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Amount
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Date
                                    </th>
                                    <th className="px-4 py-3 text-right font-semibold">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-container">
                                {transactions.data.map((tx) => (
                                    <tr
                                        key={tx.id}
                                        className="transition-colors hover:bg-surface/50"
                                    >
                                        <td className="px-4 py-3">
                                            <span className="block font-mono font-bold text-primary">
                                                {tx.reference}
                                            </span>
                                            <span className="mt-0.5 block text-[10px] font-semibold text-on-surface-variant uppercase">
                                                {tx.source_type === 'registered'
                                                    ? 'Registered User'
                                                    : 'Guest Purchase'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="block font-medium text-on-surface">
                                                {tx.customer_name}
                                            </span>
                                            <span className="block text-[11px] text-on-surface-variant">
                                                {tx.customer_email}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-on-surface capitalize">
                                            {tx.category
                                                ? tx.category.replace('_', ' ')
                                                : '-'}
                                        </td>
                                        <td className="px-4 py-3 font-medium text-on-surface">
                                            {tx.payment_method}
                                            {tx.payment_gateway_ref && (
                                                <span
                                                    className="block max-w-[140px] truncate font-mono text-[10px] text-on-surface-variant"
                                                    title={
                                                        tx.payment_gateway_ref
                                                    }
                                                >
                                                    {tx.payment_gateway_ref}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-sm font-bold text-on-surface">
                                            {tx.currency === 'GBP' ? '£' : ''}
                                            {tx.amount.toFixed(2)}{' '}
                                            {tx.currency !== 'GBP'
                                                ? tx.currency
                                                : ''}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={tx.status} />
                                        </td>
                                        <td className="px-4 py-3 text-[11px] text-on-surface-variant">
                                            <span>
                                                {tx.paid_at
                                                    ? new Date(
                                                          tx.paid_at,
                                                      ).toLocaleDateString(
                                                          'en-GB',
                                                      )
                                                    : new Date(
                                                          tx.created_at,
                                                      ).toLocaleDateString(
                                                          'en-GB',
                                                      )}
                                            </span>
                                            {tx.refunded_at && (
                                                <span className="block text-[10px] font-medium text-error">
                                                    Refunded:{' '}
                                                    {new Date(
                                                        tx.refunded_at,
                                                    ).toLocaleDateString(
                                                        'en-GB',
                                                    )}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {tx.status === 'success' ? (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setRefundTarget(tx)
                                                    }
                                                    className="inline-flex items-center gap-1.5 rounded bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 transition hover:bg-amber-100 hover:text-amber-800 border border-amber-200"
                                                >
                                                    <RotateCcw className="h-3 w-3" />
                                                    Refund
                                                </button>
                                            ) : (
                                                <StatusBadge
                                                    status={tx.status}
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Pagination */}
                <div className="border-t border-outline-variant/60">
                    <Pagination
                        links={transactions.links}
                        from={transactions.from}
                        to={transactions.to}
                        total={transactions.total}
                    />
                </div>
            </div>

            {/* Refund Modal */}
            {refundTarget && (
                <RefundModal
                    isOpen={!!refundTarget}
                    onClose={() => setRefundTarget(null)}
                    transaction={refundTarget}
                />
            )}
        </AdminLayout>
    );
}
