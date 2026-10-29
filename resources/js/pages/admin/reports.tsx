import { Head } from '@inertiajs/react';
import React from 'react';

import EmptyState from '@/components/admin/empty-state';
import Pagination from '@/components/admin/pagination';
import SearchFilterBar from '@/components/admin/search-filter-bar';
import StatusBadge from '@/components/admin/status-badge';
import AdminLayout from '@/layouts/admin-layout';

interface ReportItem {
    id: number;
    registration_number: string;
    vin: string;
    report_type: string;
    customer_id?: number | null;
    customer_name: string;
    customer_email: string;
    status: string;
    stage: string;
    generated_at: string;
    download_count: number;
}

interface ReportsPageProps {
    reports: {
        data: ReportItem[];
        links: any[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: {
        search: string;
        type: string;
        status: string;
    };
}

export default function Reports({ reports, filters }: ReportsPageProps) {
    const filterOptions = [
        {
            name: 'type',
            label: 'Report Tier',
            value: filters.type,
            options: [
                { label: 'All Tiers', value: 'all' },
                { label: 'Basic', value: 'basic' },
                { label: 'Premium', value: 'premium' },
            ],
        },
        {
            name: 'status',
            label: 'Process Status',
            value: filters.status,
            options: [
                { label: 'All Statuses', value: 'all' },
                { label: 'Success / Completed', value: 'success' },
                { label: 'Pending / Queued', value: 'pending' },
                { label: 'Failed', value: 'failed' },
            ],
        },
    ];

    return (
        <AdminLayout
            title="Vehicle Reports"
            subtitle="Vehicle checks generated, data provider processing status, and report logs"
        >
            <Head title="Vehicle Reports - VinCheck Admin" />

            {/* Search and Filters */}
            <SearchFilterBar
                baseUrl="/admin/reports"
                searchPlaceholder="Search by Registration Number, VIN, or customer name..."
                searchValue={filters.search}
                filters={filterOptions}
            />

            {/* Reports Table */}
            <div className="overflow-hidden rounded-xl border border-outline-variant bg-white shadow-xs">
                {reports.data.length === 0 ? (
                    <EmptyState
                        title="No reports found"
                        description="Try modifying search keywords or clearing filter parameters."
                    />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-outline-variant/60 bg-surface text-[11px] tracking-wider text-on-surface-variant uppercase">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">
                                        Report ID
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Registration / VIN
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Tier
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Customer
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Process Status
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Generated Date
                                    </th>
                                    <th className="px-4 py-3 font-semibold">
                                        Downloads
                                    </th>
                                    <th className="px-4 py-3 text-right font-semibold">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-surface-container">
                                {reports.data.map((rep) => (
                                    <tr
                                        key={rep.id}
                                        className="transition-colors hover:bg-surface/50"
                                    >
                                        <td className="px-4 py-3 font-mono font-bold text-primary">
                                            #{rep.id}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="block font-mono text-sm font-extrabold text-primary">
                                                {rep.registration_number}
                                            </span>
                                            <span className="mt-0.5 block font-mono text-[10px] text-on-surface-variant">
                                                {rep.vin !== '-'
                                                    ? rep.vin
                                                    : 'No VIN'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge
                                                status={rep.report_type}
                                            />
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="block font-medium text-on-surface">
                                                {rep.customer_name}
                                            </span>
                                            <span className="block text-[11px] text-on-surface-variant">
                                                {rep.customer_email}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge status={rep.status} />
                                            {rep.stage && rep.stage !== '-' && (
                                                <span className="mt-0.5 block font-mono text-[10px] text-on-surface-variant">
                                                    Stage: {rep.stage}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-[11px] text-on-surface-variant">
                                            {new Date(
                                                rep.generated_at,
                                            ).toLocaleDateString('en-GB', {
                                                day: 'numeric',
                                                month: 'short',
                                                year: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </td>
                                        <td className="px-4 py-3 font-mono font-semibold text-on-surface">
                                            {rep.download_count}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <a
                                                href={`/report/${rep.id}`}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex items-center gap-1 rounded border border-outline-variant bg-surface px-2.5 py-1 text-[11px] font-semibold text-primary hover:bg-surface-container hover:underline"
                                            >
                                                <span>View</span>
                                                <span className="text-[10px]">
                                                    ↗
                                                </span>
                                            </a>
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
                        links={reports.links}
                        from={reports.from}
                        to={reports.to}
                        total={reports.total}
                    />
                </div>
            </div>
        </AdminLayout>
    );
}
