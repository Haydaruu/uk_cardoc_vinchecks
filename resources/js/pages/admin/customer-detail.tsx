import { Head, Link, usePage } from '@inertiajs/react';
import { AlertCircle, CheckCircle, Coins, UserCheck, UserX } from 'lucide-react';
import React, { useState } from 'react';

import AdjustCreditsModal from '@/components/admin/adjust-credits-modal';
import ReactivateCustomerModal from '@/components/admin/reactivate-customer-modal';
import StatusBadge from '@/components/admin/status-badge';
import SuspendCustomerModal from '@/components/admin/suspend-customer-modal';
import AdminLayout from '@/layouts/admin-layout';

interface CustomerDetailProps {
    customer: {
        id: number;
        name: string;
        email: string;
        role: string;
        credits: number;
        is_suspended: boolean;
        suspended_reason?: string | null;
        suspended_at?: string | null;
        suspended_by?: string | null;
        email_verified_at?: string | null;
        created_at: string;
        subscriptions: Array<{
            id: number;
            plan_name: string;
            price: number;
            status: string;
            start_date?: string | null;
            end_date?: string | null;
            monthly_credits: number;
            payment_method?: string | null;
        }>;
        transactions: Array<{
            id: number;
            invoice_id?: string | null;
            amount: number;
            currency: string;
            status: string;
            created_at: string;
        }>;
        credit_history: Array<{
            id: number;
            type: string;
            amount: number;
            balance_after: number;
            description?: string | null;
            created_at: string;
        }>;
        reports: Array<{
            id: number;
            vin?: string | null;
            report_type: string;
            created_at: string;
        }>;
    };
}

export default function CustomerDetail({ customer }: CustomerDetailProps) {
    const { flash } = usePage<{
        flash?: { success?: string | null; error?: string | null };
    }>().props;

    const [isAdjustOpen, setIsAdjustOpen] = useState(false);
    const [isSuspendOpen, setIsSuspendOpen] = useState(false);
    const [isReactivateOpen, setIsReactivateOpen] = useState(false);

    return (
        <AdminLayout
            title={`Customer: ${customer.name}`}
            subtitle={`User ID #${customer.id} Profile & Activity Ledger`}
        >
            <Head title={`Customer ${customer.name} - VinCheck Admin`} />

            {/* Flash notification banner */}
            {flash?.success && (
                <div className="mb-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 shadow-xs">
                    <CheckCircle className="h-4 w-4 shrink-0 text-emerald-600" />
                    <span>{flash.success}</span>
                </div>
            )}
            {flash?.error && (
                <div className="mb-4 flex items-center gap-3 rounded-xl border border-error/30 bg-error-container/30 p-4 text-xs font-semibold text-error shadow-xs">
                    <AlertCircle className="h-4 w-4 shrink-0 text-error" />
                    <span>{flash.error}</span>
                </div>
            )}

            {/* Back link */}
            <div className="mb-4">
                <Link
                    href="/admin/customers"
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline"
                >
                    <span>←</span>
                    <span>Back to Customers list</span>
                </Link>
            </div>

            {/* Top Customer Summary Card */}
            <div className="mb-6 rounded-xl border border-outline-variant bg-white p-6 shadow-xs">
                <div className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div className="flex items-center gap-4">
                        <div className="flex h-14 w-14 items-center justify-center rounded-full bg-primary-container font-sans text-xl font-bold text-white uppercase">
                            {customer.name.charAt(0)}
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="font-sans text-lg font-bold text-on-surface">
                                    {customer.name}
                                </h2>
                                <span className="rounded bg-surface-container px-2 py-0.5 text-[10px] font-bold tracking-wider text-primary uppercase">
                                    {customer.role}
                                </span>
                            </div>
                            <span className="mt-0.5 block text-xs text-on-surface-variant">
                                {customer.email} • Joined{' '}
                                {new Date(
                                    customer.created_at,
                                ).toLocaleDateString('en-GB')}
                            </span>
                        </div>
                    </div>

                    {/* Balance & Status Badges */}
                    <div className="flex flex-wrap items-center gap-3">
                        <div className="min-w-[120px] rounded-lg border border-outline-variant bg-surface p-3 text-center">
                            <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                Credit Balance
                            </span>
                            <span className="mt-0.5 block font-mono text-xl font-extrabold text-primary">
                                {customer.credits}
                            </span>
                        </div>

                        <div className="min-w-[120px] rounded-lg border border-outline-variant bg-surface p-3 text-center">
                            <span className="block text-[10px] font-bold tracking-wider text-on-surface-variant uppercase">
                                Account Status
                            </span>
                            <div className="mt-1">
                                {customer.is_suspended ? (
                                    <span className="text-xs font-bold text-error">
                                        Suspended
                                    </span>
                                ) : (
                                    <span className="text-xs font-bold text-emerald-600">
                                        Active
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Suspension Details notice if suspended */}
                {customer.is_suspended && (
                    <div className="mt-4 rounded-lg border border-error/30 bg-error-container/30 p-3 text-xs text-error">
                        <div className="font-semibold">Account Suspended</div>
                        <p className="mt-0.5 text-on-surface-variant">
                            Reason:{' '}
                            {customer.suspended_reason ||
                                'No specific reason recorded.'}
                            {customer.suspended_at &&
                                ` • Suspended on ${new Date(customer.suspended_at).toLocaleDateString('en-GB')}`}
                            {customer.suspended_by &&
                                ` by ${customer.suspended_by}`}
                        </p>
                    </div>
                )}

                {/* Phase 2 Interactive Operational Action Bar */}
                <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-outline-variant/60 pt-4 text-xs text-on-surface-variant">
                    <span className="font-medium text-on-surface-variant">
                        Operational Actions
                    </span>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setIsAdjustOpen(true)}
                            className="flex items-center gap-1.5 rounded-lg border border-primary/30 bg-primary/5 px-3.5 py-1.5 text-xs font-semibold text-primary transition hover:bg-primary hover:text-white"
                        >
                            <Coins className="h-3.5 w-3.5" />
                            <span>Adjust Credits</span>
                        </button>
                        {customer.is_suspended ? (
                            <button
                                type="button"
                                onClick={() => setIsReactivateOpen(true)}
                                className="flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3.5 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-600 hover:text-white"
                            >
                                <UserCheck className="h-3.5 w-3.5" />
                                <span>Reactivate Customer</span>
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setIsSuspendOpen(true)}
                                className="flex items-center gap-1.5 rounded-lg border border-error/30 bg-error-container/20 px-3.5 py-1.5 text-xs font-semibold text-error transition hover:bg-error hover:text-white"
                            >
                                <UserX className="h-3.5 w-3.5" />
                                <span>Suspend Customer</span>
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {/* Subscriptions & Credit History Grids */}
            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Active & Past Subscriptions */}
                <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                    <h3 className="mb-3 font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        Subscriptions History
                    </h3>
                    {customer.subscriptions.length === 0 ? (
                        <div className="py-6 text-center text-xs text-on-surface-variant">
                            No subscription records found for this customer.
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {customer.subscriptions.map((sub) => (
                                <div
                                    key={sub.id}
                                    className="flex items-center justify-between rounded-lg border border-outline-variant bg-surface p-3 text-xs"
                                >
                                    <div>
                                        <span className="block font-bold text-on-surface">
                                            {sub.plan_name}
                                        </span>
                                        <span className="block text-[11px] text-on-surface-variant">
                                            £{sub.price.toFixed(2)} / month •{' '}
                                            {sub.monthly_credits} monthly
                                            credits
                                        </span>
                                    </div>
                                    <StatusBadge status={sub.status} />
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Credit Ledger History */}
                <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                    <h3 className="mb-3 font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        Recent Credit Ledger (10 latest)
                    </h3>
                    {customer.credit_history.length === 0 ? (
                        <div className="py-6 text-center text-xs text-on-surface-variant">
                            No credit transaction ledger entries found.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-outline-variant/60 text-[11px] text-on-surface-variant">
                                        <th className="pb-2 font-semibold">
                                            Type
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Change
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Balance After
                                        </th>
                                        <th className="pb-2 font-semibold">
                                            Date
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-surface-container">
                                    {customer.credit_history.map((ch) => (
                                        <tr
                                            key={ch.id}
                                            className="hover:bg-surface/50"
                                        >
                                            <td className="py-2 font-medium capitalize">
                                                {ch.type}
                                            </td>
                                            <td
                                                className={`py-2 font-mono font-bold ${ch.amount > 0 ? 'text-emerald-600' : 'text-error'}`}
                                            >
                                                {ch.amount > 0
                                                    ? `+${ch.amount}`
                                                    : ch.amount}
                                            </td>
                                            <td className="py-2 font-mono font-bold text-primary">
                                                {ch.balance_after}
                                            </td>
                                            <td className="py-2 text-[11px] text-on-surface-variant">
                                                {new Date(
                                                    ch.created_at,
                                                ).toLocaleDateString('en-GB')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {/* Transactions & Reports Row */}
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {/* Recent Purchases */}
                <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                    <h3 className="mb-3 font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        Recent Purchases
                    </h3>
                    {customer.transactions.length === 0 ? (
                        <div className="py-6 text-center text-xs text-on-surface-variant">
                            No payment transactions recorded.
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {customer.transactions.map((tx) => (
                                <div
                                    key={tx.id}
                                    className="flex items-center justify-between rounded-lg border border-outline-variant bg-surface p-2.5 text-xs"
                                >
                                    <div>
                                        <span className="block font-mono font-bold text-primary">
                                            {tx.invoice_id || `TX-${tx.id}`}
                                        </span>
                                        <span className="text-[11px] text-on-surface-variant">
                                            {new Date(
                                                tx.created_at,
                                            ).toLocaleDateString('en-GB')}
                                        </span>
                                    </div>
                                    <div className="text-right">
                                        <span className="block font-mono font-bold">
                                            {tx.currency} £
                                            {tx.amount.toFixed(2)}
                                        </span>
                                        <StatusBadge status={tx.status} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Recent Vehicle Checks */}
                <div className="rounded-xl border border-outline-variant bg-white p-5 shadow-xs">
                    <h3 className="mb-3 font-sans text-xs font-bold tracking-wider text-on-surface-variant uppercase">
                        Generated Reports (10 latest)
                    </h3>
                    {customer.reports.length === 0 ? (
                        <div className="py-6 text-center text-xs text-on-surface-variant">
                            No reports generated by this customer.
                        </div>
                    ) : (
                        <div className="space-y-2">
                            {customer.reports.map((rep) => (
                                <div
                                    key={rep.id}
                                    className="flex items-center justify-between rounded-lg border border-outline-variant bg-surface p-2.5 text-xs"
                                >
                                    <div>
                                        <span className="block font-mono font-bold text-primary">
                                            Report #{rep.id}
                                        </span>
                                        <span className="text-[11px] text-on-surface-variant">
                                            {rep.vin || 'Standard Check'} •{' '}
                                            {new Date(
                                                rep.created_at,
                                            ).toLocaleDateString('en-GB')}
                                        </span>
                                    </div>
                                    <a
                                        href={`/report/${rep.id}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-semibold text-primary hover:underline"
                                    >
                                        View ↗
                                    </a>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Modals */}
            {isAdjustOpen && (
                <AdjustCreditsModal
                    isOpen={isAdjustOpen}
                    onClose={() => setIsAdjustOpen(false)}
                    customer={{
                        id: customer.id,
                        name: customer.name,
                        credits: customer.credits,
                    }}
                />
            )}

            {isSuspendOpen && (
                <SuspendCustomerModal
                    isOpen={isSuspendOpen}
                    onClose={() => setIsSuspendOpen(false)}
                    customer={{
                        id: customer.id,
                        name: customer.name,
                        email: customer.email,
                    }}
                />
            )}

            {isReactivateOpen && (
                <ReactivateCustomerModal
                    isOpen={isReactivateOpen}
                    onClose={() => setIsReactivateOpen(false)}
                    customer={{
                        id: customer.id,
                        name: customer.name,
                        suspended_reason: customer.suspended_reason,
                    }}
                />
            )}
        </AdminLayout>
    );
}
