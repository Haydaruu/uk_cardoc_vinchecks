import { Head, usePage } from '@inertiajs/react';
import { AlertCircle, AlertTriangle, Ban, CheckCircle } from 'lucide-react';
import React, { useState } from 'react';

import CancelSubscriptionModal from '@/components/admin/cancel-subscription-modal';
import EmptyState from '@/components/admin/empty-state';
import Pagination from '@/components/admin/pagination';
import SearchFilterBar from '@/components/admin/search-filter-bar';
import StatusBadge from '@/components/admin/status-badge';
import AdminLayout from '@/layouts/admin-layout';

interface SubscriptionItem {
    id: number;
    customer_id: number;
    customer_name: string;
    customer_email: string;
    plan_name: string;
    price: number;
    currency: string;
    status: string;
    gateway: string;
    provider_subscription_id: string;
    monthly_credits: number;
    start_date?: string | null;
    end_date?: string | null;
    current_period_end?: string | null;
    cancel_at_period_end: boolean;
    cancelled_at?: string | null;
    pending_plan_name?: string | null;
    pending_plan_effective_at?: string | null;
    created_at: string;
}

interface SubscriptionsPageProps {
    subscriptions: {
        data: SubscriptionItem[];
        links: any[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        search: string;
        status: string;
        provider: string;
    };
}

export default function Subscriptions({
    subscriptions,
    filters,
}: SubscriptionsPageProps) {
    const { flash } = usePage<{
        flash?: {
            success?: string | null;
            error?: string | null;
            warning?: string | null;
        };
    }>().props;

    const [cancelTarget, setCancelTarget] = useState<SubscriptionItem | null>(
        null,
    );

    const filterOptions = [
        {
            name: 'status',
            label: 'Status',
            value: filters.status,
            options: [
                { label: 'All Statuses', value: 'all' },
                { label: 'Active', value: 'active' },
                { label: 'Cancelled', value: 'cancelled' },
                { label: 'Expired', value: 'expired' },
                { label: 'Pending', value: 'pending' },
            ],
        },
        {
            name: 'provider',
            label: 'Gateway',
            value: filters.provider,
            options: [
                { label: 'All Gateways', value: 'all' },
                { label: 'Stripe', value: 'stripe' },
                { label: 'PayPal', value: 'paypal' },
            ],
        },
    ];

    return (
        <AdminLayout
            title="Customer Subscriptions"
            subtitle="Recurring memberships, credit quotas, provider sync state, and period entitlements"
        >
            <Head title="Subscriptions - VinCheck Admin" />

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

            {/* Search and Filters */}
            <SearchFilterBar
                baseUrl="/admin/subscriptions"
                searchPlaceholder="Search by customer name, email, plan, or gateway subscription ID..."
                searchValue={filters.search}
                filters={filterOptions}
            />

            {/* Subscriptions Table */}
            <div className="overflow-hidden rounded-xl border border-outline-variant bg-white shadow-xs">
                {subscriptions.data.length === 0 ? (
                    <EmptyState
                        title="No subscriptions found"
                        description="Try modifying search keywords or clearing filter parameters."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-outline-variant/60 bg-surface text-[11px] tracking-wider text-on-surface-variant uppercase">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">
                                        Customer
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Plan
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Billing Rate
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Gateway / ID
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Monthly Credits
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Status
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Renewal / End Date
                                    </th>
                                    <th className="px-4 py-3 text-right font-semibold">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-container">
                                {subscriptions.data.map((sub) => (
                                    <tr
                                        key={sub.id}
                                        className="transition-colors hover:bg-surface/50"
                                    >
                                        <td className="px-4 py-3">
                                            <span className="block font-semibold text-on-surface">
                                                {sub.customer_name}
                                            </span>
                                            <span className="block text-[11px] text-on-surface-variant">
                                                {sub.customer_email}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="block font-bold text-primary">
                                                {sub.plan_name}
                                            </span>
                                            {sub.pending_plan_name && (
                                                <span className="mt-0.5 inline-block rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-800">
                                                    Pending:{' '}
                                                    {sub.pending_plan_name}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 font-mono font-bold">
                                            £{sub.price.toFixed(2)} / mo
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="block font-semibold text-on-surface">
                                                {sub.gateway}
                                            </span>
                                            <span
                                                className="block max-w-[150px] truncate font-mono text-[10px] text-on-surface-variant"
                                                title={
                                                    sub.provider_subscription_id
                                                }
                                            >
                                                {sub.provider_subscription_id}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-center font-mono font-bold">
                                            {sub.monthly_credits}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={sub.status} />
                                            {sub.cancel_at_period_end && (
                                                <span className="mt-0.5 block text-[10px] font-medium text-error">
                                                    Cancels at period end
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-[11px] text-on-surface-variant">
                                            {sub.current_period_end
                                                ? new Date(
                                                      sub.current_period_end,
                                                  ).toLocaleDateString('en-GB')
                                                : sub.end_date
                                                  ? new Date(
                                                        sub.end_date,
                                                    ).toLocaleDateString(
                                                        'en-GB',
                                                    )
                                                  : 'Active Ongoing'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            {sub.status === 'active' &&
                                            !sub.cancel_at_period_end ? (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setCancelTarget(sub)
                                                    }
                                                    className="inline-flex items-center gap-1.5 rounded border border-error/30 bg-error-container/20 px-2.5 py-1 text-[11px] font-semibold text-error transition hover:bg-error-container/40"
                                                >
                                                    <Ban className="h-3 w-3" />
                                                    Cancel
                                                </button>
                                            ) : sub.cancel_at_period_end ? (
                                                <span className="inline-block rounded border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] font-medium text-amber-700">
                                                    Cancellation Scheduled
                                                </span>
                                            ) : (
                                                <StatusBadge
                                                    status={sub.status}
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
                        links={subscriptions.links}
                        from={subscriptions.from}
                        to={subscriptions.to}
                        total={subscriptions.total}
                    />
                </div>
            </div>

            {/* Cancel Subscription Modal */}
            {cancelTarget && (
                <CancelSubscriptionModal
                    isOpen={!!cancelTarget}
                    onClose={() => setCancelTarget(null)}
                    subscription={cancelTarget}
                />
            )}
        </AdminLayout>
    );
}
