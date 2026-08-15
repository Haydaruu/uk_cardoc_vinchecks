import { useForm, router } from '@inertiajs/react';
import { store } from '@/actions/App/Http/Controllers/VehicleCheckController';
import { ArrowRight, Award, Car, ExternalLink, Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

type ReportBadgeTone = 'neutral' | 'alert';

type ReportBadge = {
    label: string;
    tone: ReportBadgeTone;
};

export type RecentReport = {
    id: string | number;
    make: string;
    model: string;
    registration: string;
    checkedOn: string;
    imageUrl?: string;
    status: 'completed' | 'alert';
    badges: ReportBadge[];
};

type UserDashboardProps = {
    user: {
        name: string;
        credits: number;
        is_premium: boolean;
    };
    recentReports?: RecentReport[];
    recentSearches?: string[];
};

export default function UserDashboard({ user, recentReports = [], recentSearches = [] }: UserDashboardProps) {
    const firstName = user.name.split(' ')[0];
    const { data, setData, post, processing, errors } = useForm({
        registration_number: '',
    });
    const [isSearchFocused, setIsSearchFocused] = useState(false);

    function handleCheck(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post(store().url);
    }

    return (
        <div className="mx-auto max-w-[1200px] px-6 py-12 md:px-10">
            {/* Welcome Header */}
            <div className="mb-10">
                <h1 className="text-4xl font-black tracking-tight text-primary-container md:text-5xl">
                    Welcome back, {firstName}.
                </h1>
                <p className="mt-2 max-w-xl text-slate-500">
                    {user.is_premium ? (
                        <>Your Premium status is active. Access unlimited history reports, live auction valuations, and priority support.</>
                    ) : (
                        <>
                            You have <span className="font-bold text-slate-700">{user.credits}</span> credit
                            {user.credits === 1 ? '' : 's'} remaining. Perform a full vehicle history check to uncover hidden pasts,
                            finance markers, and salvage data.
                        </>
                    )}
                </p>
            </div>

            <div className="grid gap-6 md:grid-cols-12">
                {/* Hero Search Module */}
                <div className="relative overflow-hidden rounded-lg border border-slate-200 bg-white p-8 md:col-span-8">
                    <div className="relative z-10">
                        <h2 className="text-xl font-bold text-primary-container">Start New Check</h2>
                        <p className="mt-1 text-sm text-slate-500">
                            Enter the VRM (Registration) or VIN to instantly pull UK government and private database records.
                        </p>

                        <form onSubmit={handleCheck} className="mt-4 flex flex-col gap-3 sm:flex-row">
                            <div className={`flex-1 transition-transform duration-200 ${isSearchFocused ? 'scale-[1.01]' : ''}`}>
                                <input
                                    type="text"
                                    value={data.registration_number}
                                    onChange={(e) => setData('registration_number', e.target.value)}
                                    onFocus={() => setIsSearchFocused(true)}
                                    onBlur={() => setIsSearchFocused(false)}
                                    placeholder="ENTER REG (E.G. AB12 CDE)"
                                    className="h-14 w-full rounded border border-slate-200 px-4 text-sm uppercase tracking-widest placeholder:normal-case placeholder:text-slate-400 focus:border-primary-container focus:outline-none"
                                />
                                {errors.registration_number && (
                                    <p className="mt-1 text-xs text-red-600">{errors.registration_number}</p>
                                )}
                            </div>
                            <button
                                type="submit"
                                disabled={processing}
                                className="flex h-14 items-center justify-center gap-2 rounded bg-secondary px-8 text-sm font-bold uppercase tracking-widest text-white transition-all hover:bg-secondary-container active:scale-95 disabled:opacity-60"
                            >
                                {processing ? (
                                    'Checking...'
                                ) : (
                                    <>
                                        Generate Report
                                        <ArrowRight className="size-4" />
                                    </>
                                )}
                            </button>
                        </form>

                        {recentSearches.length > 0 && (
                            <div className="mt-4 flex items-center gap-4">
                                <span className="text-xs font-bold uppercase tracking-wide text-slate-400">Recent Searches:</span>
                                <div className="flex gap-2">
                                    {recentSearches.map((reg) => (
                                        <button
                                            key={reg}
                                            type="button"
                                            onClick={() => setData('registration_number', reg)}
                                            className="cursor-pointer rounded border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-primary-container hover:bg-slate-100"
                                        >
                                            {reg}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Background decorative element */}
                    <div className="pointer-events-none absolute -right-16 -bottom-16 opacity-[0.04]">
                        <Car className="size-[260px]" strokeWidth={1.5} />
                    </div>
                </div>

                {/* Premium / Credits Stats Module */}
                <div className="relative overflow-hidden rounded-lg bg-primary-container p-6 text-white md:col-span-4">
                    <div className="relative z-10 flex h-full flex-col">
                        <div className="mb-8 flex items-start justify-between">
                            <span className="rounded bg-secondary px-3 py-1 text-[10px] font-bold tracking-widest text-white">
                                {user.is_premium ? 'SOVEREIGN ACCESS' : 'STANDARD ACCESS'}
                            </span>
                            <Award className="size-5 text-white/70" />
                        </div>
                        <div className="mt-auto">
                            <p className="text-sm font-semibold uppercase tracking-widest text-white/70">
                                {user.is_premium ? 'Reports Remaining' : 'Your Balance'}
                            </p>
                            <p className="mt-2 text-4xl font-black leading-none">{user.is_premium ? 'Unlimited' : user.credits}</p>
                            {!user.is_premium && <p className="mt-1 text-sm text-white/70">Full History Credits</p>}
                            <button
                                type="button"
                                onClick={() => router.visit(user.is_premium ? '/billing' : '/pricing')}
                                className="mt-6 w-full rounded bg-white py-2.5 text-sm font-bold text-primary-container hover:bg-slate-50"
                            >
                                {user.is_premium ? 'View Billing History' : 'Add More Credits'}
                            </button>
                        </div>
                    </div>

                    {/* Diagonal Motif */}
                    <div
                        className="pointer-events-none absolute inset-0 opacity-10"
                        style={{
                            backgroundImage:
                                'repeating-linear-gradient(45deg, transparent, transparent 40px, #ffffff 40px, #ffffff 41px)',
                        }}
                    />
                </div>

                {/* Recent Activity Module */}
                <div className="md:col-span-12">
                    <div className="mb-6 flex items-end justify-between">
                        <div>
                            <h3 className="text-xl font-bold text-primary-container">Recent Reports</h3>
                            <p className="text-sm text-slate-500">Tracked vehicle history and valuation alerts.</p>
                        </div>
                        <a href="/reports" className="flex items-center gap-1 text-sm font-bold text-secondary hover:underline">
                            View all reports
                            <ExternalLink className="size-4" />
                        </a>
                    </div>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                        {recentReports.map((report) => (
                            <a
                                key={report.id}
                                href={`/report/${report.id}`}
                                className={`group overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm transition-all hover:shadow-md border-l-4 ${
                                    report.status === 'alert' ? 'border-l-secondary' : 'border-l-[#22c55e]'
                                }`}
                            >
                                <div className="relative h-40 overflow-hidden bg-slate-100">
                                    {report.imageUrl ? (
                                        <img
                                            src={report.imageUrl}
                                            alt={`${report.make} ${report.model}`}
                                            className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                                        />
                                    ) : (
                                        <div className="flex h-full w-full items-center justify-center text-slate-300">
                                            <Car className="size-10" strokeWidth={1.5} />
                                        </div>
                                    )}
                                    <div
                                        className={`absolute top-3 right-3 rounded px-2 py-1 text-[11px] font-bold shadow-sm ${
                                            report.status === 'alert' ? 'bg-secondary text-white' : 'bg-white/90 text-primary-container backdrop-blur'
                                        }`}
                                    >
                                        {report.status === 'alert' ? 'ALERT' : 'COMPLETED'}
                                    </div>
                                </div>
                                <div className="p-4">
                                    <div className="mb-1 flex items-start justify-between">
                                        <h4 className="font-bold text-primary-container">
                                            {report.make} {report.model}
                                        </h4>
                                        <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-primary-container">
                                            {report.registration}
                                        </span>
                                    </div>
                                    <p className="mb-4 text-xs text-slate-500">Checked on {report.checkedOn}</p>
                                    <div className="flex flex-wrap gap-2">
                                        {report.badges.map((badge) => (
                                            <span
                                                key={badge.label}
                                                className={`rounded px-2 py-0.5 text-[10px] font-bold uppercase ${
                                                    badge.tone === 'alert' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-500'
                                                }`}
                                            >
                                                {badge.label}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            </a>
                        ))}

                        {/* New Check placeholder — selalu tampil di akhir grid */}
                        <button
                            type="button"
                            onClick={() => document.querySelector<HTMLInputElement>('input[placeholder^="ENTER REG"]')?.focus()}
                            className="group flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-200 p-8 text-center transition-colors hover:bg-slate-50"
                        >
                            <div className="mb-4 flex size-12 items-center justify-center rounded-full bg-slate-100 transition-transform group-hover:scale-110">
                                <Plus className="size-5 text-slate-400" />
                            </div>
                            <p className="font-bold text-primary-container">New Check</p>
                            <p className="text-xs text-slate-500">Add another vehicle to your dashboard</p>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}