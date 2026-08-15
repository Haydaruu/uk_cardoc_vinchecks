import { Head, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import {
    Lock, Leaf, CheckCircle2, TrendingDown, AlertTriangle, Gauge, LockKeyholeOpen,
} from 'lucide-react';
import BaseLayout from '@/layouts/base-layout';
import LoginRequiredModal from '@/components/report/login-required-modal';
import CreditsExhaustedModal from '@/components/report/credits-exhausted-modal';

type ReportProps = {
    report: {
        id: number;
        report_type: 'basic' | 'premium';
        data: {
            registrationNumber: string;
            make: string;
            model: string;
            colour: string;
            fuelType: string;
            engineCapacity: number;
            yearOfManufacture: number;
            co2Emissions?: number;
            tax?: { taxStatus: string };
            mot?: { motStatus: string; motDueDate: string };
        };
    };
};

type PageProps = {
    flash: { modal?: 'login_required' | 'credits_exhausted' };
};

function isUlezCompliant(fuelType: string, year: number): boolean {
    const fuel = fuelType?.toLowerCase();
    if (fuel === 'diesel' || fuel === 'heavy oil') return year >= 2015;
    if (fuel === 'petrol') return year >= 2006;
    return true; // electric/hybrid dianggap compliant
}

export default function ShowReport({ report }: ReportProps) {
    const { data } = report;
    const isPremium = report.report_type === 'premium';
    const { props } = usePage<PageProps>();
    const [modal, setModal] = useState<string | null>(null);
    const ulezCompliant = isUlezCompliant(data.fuelType, data.yearOfManufacture);

    useEffect(() => {
        if (props.flash?.modal) setModal(props.flash.modal);
    }, [props.flash?.modal]);

    function handleUnlock() {
        router.post(`/reports/${report.id}/unlock`);
    }

    return (
        <>
            <Head title={`${data.make} ${data.model} — Report`} />

            <main className="mx-auto max-w-[1200px] px-6 py-10 lg:px-0">
                {/* Header */}
                <section className="mb-8">
                    <div className="mb-6 flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-end">
                        <div>
                            <span className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-green-100 px-3 py-1 text-xs font-bold text-green-800">
                                <CheckCircle2 className="size-3.5" /> VEHICLE IDENTIFIED
                            </span>
                            <h1 className="font-h1 text-primary">{data.make} {data.model}</h1>
                            <p className="mt-1 text-slate-500">
                                Registration:{' '}
                                <span className="rounded border border-slate-200 bg-slate-100 px-2 py-1 font-mono font-bold text-primary">
                                    {data.registrationNumber}
                                </span>
                            </p>
                        </div>

                        {!isPremium && (
                            <button
                                onClick={handleUnlock}
                                className="flex items-center gap-2 rounded-md bg-secondary px-6 py-3.5 text-sm font-bold uppercase tracking-wide text-white shadow-md transition-transform hover:bg-secondary-container active:scale-[0.98]"
                            >
                                <Lock className="size-4" /> Unlock Full Report
                            </button>
                        )}
                    </div>
                    <div className="sovereign-line" />
                </section>

                {/* Bento Grid */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-12">
                    {/* Hero Vehicle Card */}
                    <div className="relative overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm md:col-span-8">
                        <div className="flex h-80 w-full items-center justify-center bg-slate-100">
                            <Gauge className="size-16 text-slate-300" />
                        </div>
                        <div className="absolute inset-x-0 bottom-0 flex gap-3 bg-gradient-to-t from-black/60 to-transparent p-6">
                            <StatChip label="Fuel Type" value={data.fuelType} />
                            <StatChip label="Engine" value={`${data.engineCapacity}cc`} />
                            <StatChip label="Year" value={`${data.yearOfManufacture}`} />
                        </div>
                    </div>

                    {/* ULEZ Compliant — unlocked, dihitung dari data yang sudah ada */}
                    <div
                        className={`flex flex-col items-center justify-center rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm md:col-span-4 border-l-4 ${
                            ulezCompliant ? 'border-l-green-500' : 'border-l-red-500'
                        }`}
                    >
                        <Leaf className={`mb-4 size-14 ${ulezCompliant ? 'text-green-500' : 'text-red-500'}`} />
                        <h3 className="font-h3 mb-2 text-primary">
                            {ulezCompliant ? 'ULEZ Compliant' : 'Not ULEZ Compliant'}
                        </h3>
                        <p className="text-sm text-slate-500">
                            {ulezCompliant
                                ? 'This vehicle meets the emissions standards for the London Ultra Low Emission Zone.'
                                : 'This vehicle may be subject to daily ULEZ charges in London.'}
                        </p>
                    </div>

                    {/* Outstanding Finance — locked */}
                    <LockedStatCard
                        icon={<TrendingDown className="size-5 text-red-600" />}
                        borderColor="border-l-red-500"
                        title="Outstanding Finance"
                        note="Active finance agreements may exist. Unlock to verify."
                    />

                    {/* Accident History — locked */}
                    <LockedStatCard
                        icon={<AlertTriangle className="size-5 text-amber-600" />}
                        borderColor="border-l-amber-500"
                        title="Accident History"
                        note="Total loss or Category S/N records may be present."
                    />

                    {/* Mileage Analysis — unlocked (basic MOT data), chart tetap premium */}
                    <div className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm md:col-span-4 border-l-4 border-l-blue-500">
                        <div className="mb-6 flex items-start justify-between">
                            <h4 className="flex items-center gap-2 font-bold text-primary">
                                <Gauge className="size-5 text-blue-600" /> Mileage Analysis
                            </h4>
                            <Lock className="size-4 text-slate-300" />
                        </div>
                        <div className="space-y-3">
                            <div className="flex items-center justify-between">
                                <p className="text-sm font-medium text-slate-500">MOT Status</p>
                                <span
                                    className={`rounded px-2 py-0.5 text-[10px] font-bold uppercase ${
                                        data.mot?.motStatus === 'Valid'
                                            ? 'bg-green-100 text-green-700'
                                            : 'bg-red-100 text-red-700'
                                    }`}
                                >
                                    {data.mot?.motStatus ?? '—'}
                                </span>
                            </div>
                            <div className="flex items-center justify-between">
                                <p className="text-sm font-medium text-slate-500">Expiry Date</p>
                                <p className="text-sm font-bold text-primary">{data.mot?.motDueDate ?? '—'}</p>
                            </div>
                        </div>
                        <p className="mt-6 text-xs italic text-slate-400">
                            Basic MOT records available. Unlock for full mileage chart.
                        </p>
                    </div>
                </div>
            </main>

            {modal === 'login_required' && <LoginRequiredModal onClose={() => setModal(null)} />}
            {modal === 'credits_exhausted' && <CreditsExhaustedModal onClose={() => setModal(null)} />}
        </>
    );
}

function StatChip({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg bg-white/20 px-4 py-2 text-white backdrop-blur-md">
            <p className="text-[10px] font-bold uppercase tracking-widest opacity-80">{label}</p>
            <p className="font-bold">{value}</p>
        </div>
    );
}

function LockedStatCard({
    icon, title, note, borderColor,
}: {
    icon: React.ReactNode;
    title: string;
    note: string;
    borderColor: string;
}) {
    return (
        <div className={`rounded-lg border border-slate-200 bg-white p-6 shadow-sm md:col-span-4 border-l-4 ${borderColor}`}>
            <div className="mb-6 flex items-start justify-between">
                <h4 className="flex items-center gap-2 font-bold text-primary">
                    {icon} {title}
                </h4>
                <Lock className="size-4 text-slate-300" />
            </div>
            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 p-4 text-center">
                <Lock className="mb-2 size-5 text-slate-400" />
                <p className="text-sm font-semibold text-slate-500">Premium Only</p>
                <p className="text-[10px] text-slate-400">Upgrade to Full Report</p>
            </div>
            <p className="mt-6 text-xs italic text-slate-500">{note}</p>
        </div>
    );
}

ShowReport.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;