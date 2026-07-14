import { Head } from '@inertiajs/react';
import BaseLayout from '@/layouts/base-layout';
import {
    BarChart3,
    Car,
    CheckCircle,
    Download,
    FileText,
    Key,
    Share2,
    Shield,
    ShieldCheck,
    TrendingUp,
} from 'lucide-react';

const specifications = [
    { label: 'Make', value: 'BMW' },
    { label: 'Model', value: '3 Series 320d M Sport' },
    { label: 'Body Type', value: 'Saloon' },
    { label: 'Colour', value: 'Grey' },
    { label: 'Date of First Reg', value: '14 Oct 2021' },
    { label: 'Fuel Type', value: 'Diesel' },
    { label: 'Transmission', value: '6 Speed Manual' },
    { label: 'CO2 Emissions', value: '128 g/km' },
    { label: 'Engine Number', value: 'B47D20A' },
];

const mileageHistory = [
    { date: '14/10/2024', miles: '32,451 miles' },
    { date: '12/10/2023', miles: '28,102 miles' },
    { date: '10/10/2022', miles: '19,840 miles' },
];

const motHistory = [
    { date: '14 Oct 2024', result: 'PASS' },
    { date: '12 Oct 2023', result: 'PASS' },
];

const badges = ['ULEZ Compliant', 'Manual', 'Diesel', 'Euro 6'];

function MyReport() {
    return (
        <>
            <Head title="Vehicle Report" />

            <div className="bg-slate-50 py-10">
                <div className="mx-auto max-w-[1200px] space-y-6 px-6 md:px-10">
                    {/* Hero Card */}
                    <div className="overflow-hidden rounded-sm border border-slate-200 bg-white shadow-sm">
                        <div className="grid md:grid-cols-[340px_1fr]">
                            <div className="relative h-56 md:h-auto">
                                <img
                                    src="https://images.unsplash.com/photo-1555215695-3004980ad54e?w=800&q=80"
                                    alt="BMW 3 Series 320d M Sport"
                                    className="h-full w-full object-cover"
                                />
                            </div>
                            <div className="relative p-8">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <span className="inline-block rounded-sm bg-surface-container px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-primary-container">
                                            Report ID: UKC-9283-XJ
                                        </span>
                                        <h1 className="mt-4 text-2xl font-black tracking-tight text-primary-container md:text-3xl">
                                            BMW 3 SERIES 320d M Sport
                                        </h1>
                                        <p className="mt-2 text-sm text-slate-500">
                                            Year: 2021 • Reg: GY71 XJN • Engine: 1995cc
                                        </p>
                                        <div className="mt-4 flex flex-wrap gap-2">
                                            {badges.map((badge) => (
                                                <span
                                                    key={badge}
                                                    className="rounded-sm border border-slate-200 bg-slate-50 px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-slate-600"
                                                >
                                                    {badge}
                                                </span>
                                            ))}
                                        </div>
                                    </div>
                                    <div className="rounded-sm border-2 border-green-500 bg-green-50 px-5 py-4 text-center">
                                        <CheckCircle
                                            size={28}
                                            className="mx-auto text-green-600"
                                        />
                                        <p className="mt-2 text-sm font-black text-green-600">
                                            CLEAN REPORT
                                        </p>
                                        <p className="mt-1 text-[10px] text-slate-400">
                                            Validated 14 Oct 2024
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Specs + Finance */}
                    <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                        <div className="rounded-sm border border-slate-200 bg-white p-8 shadow-sm">
                            <div className="mb-6 flex items-center gap-3">
                                <Car size={20} className="text-primary-container" />
                                <h2 className="text-lg font-bold text-primary-container">
                                    Vehicle Specifications
                                </h2>
                            </div>
                            <div className="grid gap-6 sm:grid-cols-3">
                                {specifications.map((spec) => (
                                    <div key={spec.label}>
                                        <p className="text-[10px] font-bold uppercase tracking-widest text-slate-400">
                                            {spec.label}
                                        </p>
                                        <p className="mt-1 text-sm font-bold text-primary-container">
                                            {spec.value}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="rounded-sm border border-slate-200 bg-white p-8 text-center shadow-sm">
                            <div className="mb-6 flex items-center gap-3">
                                <Shield size={20} className="text-primary-container" />
                                <h2 className="text-lg font-bold text-primary-container">
                                    Finance Status
                                </h2>
                            </div>
                            <ShieldCheck size={40} className="mx-auto text-green-600" />
                            <p className="mt-4 text-sm font-black text-green-600">
                                No Finance Recorded
                            </p>
                            <p className="mt-2 text-xs leading-relaxed text-slate-500">
                                No outstanding finance agreements were found against this vehicle
                                at the time of this report.
                            </p>
                        </div>
                    </div>

                    {/* Mileage + MOT */}
                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="rounded-sm border border-slate-200 bg-white p-8 shadow-sm">
                            <div className="mb-6 flex items-center gap-3">
                                <TrendingUp size={20} className="text-primary-container" />
                                <h2 className="text-lg font-bold text-primary-container">
                                    Mileage History
                                </h2>
                            </div>
                            <div className="space-y-4">
                                {mileageHistory.map((entry) => (
                                    <div
                                        key={entry.date}
                                        className="flex items-center justify-between border-b border-slate-100 pb-4 last:border-0 last:pb-0"
                                    >
                                        <span className="text-sm text-slate-500">
                                            {entry.date}
                                        </span>
                                        <div className="flex items-center gap-3">
                                            <span className="text-sm font-bold text-primary-container">
                                                {entry.miles}
                                            </span>
                                            <span className="flex items-center gap-1 rounded-sm bg-green-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-widest text-green-600">
                                                <TrendingUp size={10} />
                                                Valid
                                            </span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div className="mt-6 flex items-center gap-2 rounded-sm bg-slate-50 px-4 py-3">
                                <BarChart3 size={16} className="text-slate-400" />
                                <p className="text-xs text-slate-500">
                                    No mileage discrepancies detected across recorded entries.
                                </p>
                            </div>
                        </div>

                        <div className="rounded-sm border border-slate-200 bg-white p-8 shadow-sm">
                            <div className="mb-6 flex items-center justify-between">
                                <div className="flex items-center gap-3">
                                    <FileText size={20} className="text-primary-container" />
                                    <h2 className="text-lg font-bold text-primary-container">
                                        MOT Status
                                    </h2>
                                </div>
                                <span className="rounded-sm bg-green-50 px-3 py-1 text-[10px] font-bold uppercase tracking-widest text-green-600">
                                    Pass
                                </span>
                            </div>
                            <div className="space-y-2">
                                <p className="text-sm text-slate-500">
                                    <span className="font-semibold text-primary-container">
                                        Expiry Date:
                                    </span>{' '}
                                    14 Oct 2025
                                </p>
                                <p className="text-sm text-slate-500">
                                    <span className="font-semibold text-primary-container">
                                        Days Remaining:
                                    </span>{' '}
                                    364 Days
                                </p>
                            </div>
                            <div className="mt-6 space-y-3 border-t border-slate-100 pt-6">
                                {motHistory.map((entry) => (
                                    <div
                                        key={entry.date}
                                        className="flex items-center justify-between"
                                    >
                                        <span className="text-sm text-slate-500">
                                            {entry.date}
                                        </span>
                                        <span className="text-sm font-black text-green-600">
                                            {entry.result}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Bottom Status Cards */}
                    <div className="grid gap-6 md:grid-cols-3">
                        {[
                            {
                                icon: Shield,
                                title: 'Stolen Records',
                                status: 'Not Recorded as Stolen',
                                sub: 'Police data check OK',
                            },
                            {
                                icon: Car,
                                title: 'Salvage & Write-off',
                                status: 'No Category Recorded',
                                sub: 'MIAFTR registry check OK',
                            },
                            {
                                icon: Key,
                                title: 'Owner History',
                                status: '1 Previous Owner',
                                sub: 'Current since Oct 2023',
                            },
                        ].map((card) => {
                            const Icon = card.icon;

                            return (
                                <div
                                    key={card.title}
                                    className="rounded-sm border border-slate-200 bg-white p-8 text-center shadow-sm"
                                >
                                    <div className="mb-4 flex items-center justify-center gap-2">
                                        <Icon size={18} className="text-primary-container" />
                                        <h3 className="text-sm font-bold text-primary-container">
                                            {card.title}
                                        </h3>
                                    </div>
                                    <ShieldCheck
                                        size={32}
                                        className="mx-auto text-green-600"
                                    />
                                    <p className="mt-3 text-sm font-black text-green-600">
                                        {card.status}
                                    </p>
                                    <p className="mt-1 text-[10px] font-bold uppercase tracking-widest text-slate-400">
                                        {card.sub}
                                    </p>
                                </div>
                            );
                        })}
                    </div>

                    {/* Action Buttons */}
                    <div className="flex flex-col items-center justify-center gap-4 pt-4 sm:flex-row">
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-sm border border-slate-800 px-8 py-3.5 text-[11px] font-bold uppercase tracking-widest text-slate-800 transition-colors hover:bg-slate-50"
                        >
                            <Download size={16} />
                            Download PDF Report
                        </button>
                        <button
                            type="button"
                            className="flex items-center gap-2 rounded-sm bg-secondary px-8 py-3.5 text-[11px] font-bold uppercase tracking-widest text-white transition-colors hover:bg-secondary-container"
                        >
                            <Share2 size={16} />
                            Share Report Link
                        </button>
                    </div>
                </div>
            </div>
        </>
    );
}

MyReport.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default MyReport;
