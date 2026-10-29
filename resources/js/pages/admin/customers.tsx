import { Head, Link } from '@inertiajs/react';
import React from 'react';

import EmptyState from '@/components/admin/empty-state';
import Pagination from '@/components/admin/pagination';
import SearchFilterBar from '@/components/admin/search-filter-bar';
import AdminLayout from '@/layouts/admin-layout';

interface CustomerItem {
    id: number;
    name: string;
    email: string;
    role: string;
    credits: number;
    is_suspended: boolean;
    suspended_reason?: string | null;
    is_verified: boolean;
    created_at: string;
    active_plan: string;
    reports_count: number;
    transactions_count: number;
}

interface CustomersPageProps {
    customers: {
        data: CustomerItem[];
        links: any[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        search: string;
        status: string;
        role: string;
        verification: string;
    };
}

export default function Customers({ customers, filters }: CustomersPageProps) {
    const filterOptions = [
        {
            name: 'status',
            label: 'Account Status',
            value: filters.status,
            options: [
                { label: 'All Statuses', value: 'all' },
                { label: 'Active', value: 'active' },
                { label: 'Suspended', value: 'suspended' },
            ],
        },
        {
            name: 'role',
            label: 'Role',
            value: filters.role,
            options: [
                { label: 'All Roles', value: 'all' },
                { label: 'Customers (User)', value: 'user' },
                { label: 'Admins', value: 'admin' },
                { label: 'Super Admins', value: 'super_admin' },
            ],
        },
        {
            name: 'verification',
            label: 'Email Verified',
            value: filters.verification,
            options: [
                { label: 'All States', value: 'all' },
                { label: 'Verified', value: 'verified' },
                { label: 'Unverified', value: 'unverified' },
            ],
        },
    ];

    return (
        <AdminLayout
            title="Customers & Users"
            subtitle="Registered user accounts, credit balances, account status, and subscription entitlements"
        >
            <Head title="Customers - VinCheck Admin" />

            {/* Search and Filters */}
            <SearchFilterBar
                baseUrl="/admin/customers"
                searchPlaceholder="Search customer by name, email, or user ID..."
                searchValue={filters.search}
                filters={filterOptions}
            />

            {/* Customers Table */}
            <div className="overflow-hidden rounded-xl border border-outline-variant bg-white shadow-xs">
                {customers.data.length === 0 ? (
                    <EmptyState
                        title="No customers found"
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
                                        Role
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Account Status
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Credits
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Active Plan
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Activity
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Joined Date
                                    </th>
                                    <th className="px-4 py-3 text-right font-semibold">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-container">
                                {customers.data.map((user) => (
                                    <tr
                                        key={user.id}
                                        className="transition-colors hover:bg-surface/50"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2.5">
                                                <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary-container font-sans text-xs font-bold text-white uppercase">
                                                    {user.name.charAt(0)}
                                                </div>
                                                <div>
                                                    <span className="block font-semibold text-on-surface">
                                                        {user.name}
                                                    </span>
                                                    <span className="block text-[11px] text-on-surface-variant">
                                                        {user.email}
                                                    </span>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-[10px] font-bold text-on-surface-variant uppercase">
                                            {user.role}
                                        </td>
                                        <td className="px-4 py-3">
                                            {user.is_suspended ? (
                                                <span className="inline-flex items-center gap-1 rounded-full border border-error/30 bg-error-container/40 px-2.5 py-0.5 text-xs font-semibold text-error">
                                                    Suspended
                                                </span>
                                            ) : (
                                                <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                                    Active
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-sm font-extrabold text-primary">
                                            {user.credits}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="font-medium text-on-surface">
                                                {user.active_plan}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-[11px] text-on-surface-variant">
                                            <span>
                                                {user.reports_count} reports
                                            </span>
                                            <span className="block text-[10px]">
                                                {user.transactions_count} orders
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-[11px] text-on-surface-variant">
                                            {new Date(
                                                user.created_at,
                                            ).toLocaleDateString('en-GB')}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <Link
                                                href={`/admin/customers/${user.id}`}
                                                className="inline-flex items-center gap-1 rounded border border-outline-variant bg-surface px-2.5 py-1 text-[11px] font-semibold text-primary hover:bg-surface-container hover:underline"
                                            >
                                                <span>Detail</span>
                                                <span className="text-[10px]">
                                                    →
                                                </span>
                                            </Link>
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
                        links={customers.links}
                        from={customers.from}
                        to={customers.to}
                        total={customers.total}
                    />
                </div>
            </div>
        </AdminLayout>
    );
}
