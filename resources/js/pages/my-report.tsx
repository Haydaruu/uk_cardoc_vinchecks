import { Head, Link, router } from '@inertiajs/react';
import { useState, FormEvent } from 'react';
import { Search, ArrowRight, SearchX, Car } from 'lucide-react';
import BaseLayout from '@/layouts/base-layout';

type ReportRow = {
    id: number;
    reportType: 'basic' | 'premium' | 'full';
    registrationNumber: string;
    make: string;
    model: string;
    imageUrl: string | null;
    checkedOn: string;
    financeRecord: boolean | null;
    writeOffRecord: boolean | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type MyReportProps = {
    reports: ReportRow[];
    links: PaginationLink[];
    search: string;
    totalReports: number;
    premiumReports: number;
};

export default function MyReport({ reports, links, search, totalReports, premiumReports }: MyReportProps) {
    const [query, setQuery] = useState(search);

    function handleSearch(e: FormEvent) {
        e.preventDefault();
        router.get('/my-report', { q: query }, { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="My Reports" />

            <main className="mx-auto max-w-5xl px-6 pb-20 pt-12">
                <header className="mb-8 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 className="text-3xl font-black tracking-tight text-primary">Your Report History</h1>
                        <p className="mt-2 text-sm text-slate-500">
                            Every vehicle you've checked, all in one place.
                        </p>
                    </div>
                    <div className="flex gap-6">
                        <div className="text-right">
                            <p className="text-2xl font-black text-primary">{totalReports}</p>
                            <p className="text-xs uppercase tracking-widest text-slate-400">Total Reports</p>
                        </div>
                        <div className="text-right">
                            <p className="text-2xl font-black text-primary">{premiumReports}</p>
                            <p className="text-xs uppercase tracking-widest text-slate-400">Premium Unlocked</p>
                        </div>
                    </div>
                </header>

                <form onSubmit={handleSearch} className="relative mb-6">
                    <Search className="absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" />
                    <input
                        type="text"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search by registration (e.g. AB12 CDE)"
                        className="h-14 w-full rounded-lg border border-slate-200 bg-white pl-12 pr-28 font-mono text-lg shadow-sm outline-none transition-all focus:border-primary focus:ring-2 focus:ring-primary-container"
                    />
                    <button
                        type="submit"
                        className="absolute right-2 top-1/2 -translate-y-1/2 rounded bg-primary-container px-4 py-2 text-sm font-bold text-white transition-transform active:scale-95"
                    >
                        Search
                    </button>
                </form>

                <div className="sovereign-line mb-6" />

                {reports.length === 0 ? (
                    <EmptyState hasSearch={!!search} />
                ) : (
                    <div className="space-y-4">
                        {reports.map((report) => (
                            <ReportRowCard key={report.id} report={report} />
                        ))}
                    </div>
                )}

                {links.length > 3 && (
                    <div className="mt-8 flex flex-wrap items-center justify-center gap-2">
                        {links.map((link, i) => (
                            <Link
                                key={i}
                                href={link.url ?? '#'}
                                preserveState
                                className={`rounded px-3 py-2 text-sm font-semibold transition-colors ${
                                    link.active
                                        ? 'bg-secondary text-white'
                                        : link.url
                                          ? 'text-slate-600 hover:bg-slate-100'
                                          : 'cursor-not-allowed text-slate-300'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </main>
        </>
    );
}

function ReportRowCard({ report }: { report: ReportRow }) {
    return (
        <div className="group rounded-lg border border-slate-200 bg-white p-4 shadow-[0px_4px_20px_rgba(0,32,91,0.04)] transition-all duration-300 hover:border-primary-container hover:shadow-[0px_8px_30px_rgba(0,32,91,0.08)] md:p-6">
            <div className="flex flex-col items-start gap-6 md:flex-row md:items-center">
                <div className="relative h-32 w-full flex-shrink-0 overflow-hidden rounded-lg bg-slate-100 md:w-48">
                    {report.imageUrl ? (
                        <img src={report.imageUrl} alt={report.make} className="h-full w-full object-cover" />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center">
                            <Car className="size-10 text-slate-300" />
                        </div>
                    )}
                </div>

                <div className="grid flex-grow grid-cols-2 items-center gap-6 lg:grid-cols-4">
                    <div>
                        <span className="mb-1 block text-[10px] uppercase text-slate-400">Number Plate</span>
                        <div className="flex h-10 w-32 items-center justify-center rounded-sm border border-yellow-600/30 bg-gradient-to-b from-yellow-400 to-yellow-500 shadow-sm">
                            <span className="font-mono text-xl font-bold tracking-widest text-black">
                                {report.registrationNumber}
                            </span>
                        </div>
                    </div>
                    <div>
                        <span className="mb-1 block text-[10px] uppercase text-slate-400">Make &amp; Model</span>
                        <h3 className="text-lg font-bold text-primary">{report.make}</h3>
                        {report.model && <p className="text-sm text-slate-500">{report.model}</p>}
                    </div>
                    <div>
                        <span className="mb-1 block text-[10px] uppercase text-slate-400">Checked on</span>
                        <p className="font-semibold text-primary">{report.checkedOn}</p>
                        {report.reportType === 'premium' && (
                            <div className="mt-1 flex gap-1">
                                <Tag positive={!report.financeRecord} label={report.financeRecord ? 'Finance' : 'No Finance'} />
                                <Tag positive={!report.writeOffRecord} label={report.writeOffRecord ? 'Write-off' : 'Clear'} />
                            </div>
                        )}
                        {report.reportType === 'basic' && (
                            <span className="mt-1 inline-block rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase text-slate-500">
                                Basic
                            </span>
                        )}
                    </div>
                    <div className="col-span-2 flex justify-end lg:col-span-1">
                        <Link
                            href={`/my-report/${report.id}`}
                            className="flex w-full items-center justify-center gap-2 rounded bg-primary-container px-6 py-3 text-sm font-bold text-white shadow-sm transition-all hover:bg-primary group-hover:translate-x-1 lg:w-auto"
                        >
                            View Report
                            <ArrowRight className="size-4" />
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}

function Tag({ label, positive }: { label: string; positive: boolean }) {
    return (
        <span
            className={`rounded px-2 py-0.5 text-[10px] font-bold uppercase ${
                positive ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
            }`}
        >
            {label}
        </span>
    );
}

function EmptyState({ hasSearch }: { hasSearch: boolean }) {
    return (
        <div className="mt-4 flex flex-col items-center rounded-xl border border-dashed border-slate-300 bg-slate-50 p-12 text-center">
            <SearchX className="mb-4 size-10 text-slate-400" />
            <h4 className="mb-2 text-lg font-bold text-primary">
                {hasSearch ? "Can't find that registration" : "You haven't checked any vehicles yet"}
            </h4>
            <p className="mx-auto mb-6 max-w-md text-sm text-slate-500">
                {hasSearch
                    ? 'Try a different registration number, or start a new check from the dashboard.'
                    : 'Run your first vehicle check from the dashboard to see it appear here.'}
            </p>
            <Link
                href="/dashboard"
                className="rounded border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-primary transition-colors hover:bg-slate-50"
            >
                Go to Dashboard
            </Link>
        </div>
    );
}

MyReport.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;