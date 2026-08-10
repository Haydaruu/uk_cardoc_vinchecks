import { Head, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Lock, Leaf } from 'lucide-react';
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

            <div className="mx-auto max-w-[1200px] px-6 py-10">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <span className="rounded-full bg-green-50 px-3 py-1 text-xs font-bold uppercase text-green-700">
                            Vehicle Identified
                        </span>
                        <h1 className="mt-2 text-2xl font-bold text-[#151c27]">
                            {data.make} {data.model}
                        </h1>
                        <p className="text-sm text-slate-500">
                            Registration: <span className="font-mono font-semibold">{data.registrationNumber}</span>
                        </p>
                    </div>
                    {!isPremium && (
                        <button onClick={handleUnlock} className="flex items-center gap-2 rounded bg-[#bb001a] px-6 py-3 text-sm font-bold uppercase text-white">
                            <Lock className="size-4" /> Unlock Full Report
                        </button>
                    )}
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-lg border border-slate-200 p-6">
                        <div className="grid grid-cols-2 gap-4">
                            <InfoCard label="Fuel Type" value={data.fuelType} />
                            <InfoCard label="Engine" value={`${data.engineCapacity}cc`} />
                            <InfoCard label="Tax Status" value={data.tax?.taxStatus ?? 'Unknown'} />
                            <InfoCard label="MOT Status" value={data.mot?.motStatus ?? 'Unknown'} />
                        </div>
                    </div>

                    {/* ULEZ Compliant card — dihitung dari data yang sudah ada, bukan API baru */}
                    <div className="flex flex-col items-center justify-center rounded-lg border border-slate-200 p-6 text-center">
                        <Leaf className={`mb-2 size-8 ${ulezCompliant ? 'text-green-500' : 'text-red-500'}`} />
                        <p className="font-bold">{ulezCompliant ? 'ULEZ Compliant' : 'Not ULEZ Compliant'}</p>
                        <p className="mt-1 text-xs text-slate-400">
                            {ulezCompliant
                                ? 'This vehicle meets the emissions standards for the London Ultra Low Emission Zone.'
                                : 'This vehicle may be subject to daily ULEZ charges in London.'}
                        </p>
                    </div>
                </div>

                {!isPremium && (
                    <div className="mt-6 grid gap-4 md:grid-cols-2">
                        <div>
                            <LockedCard title="Outstanding Finance" />
                            <p className="mt-2 text-xs italic text-slate-400">Active finance agreements may exist. Unlock to verify.</p>
                        </div>
                        <div>
                            <LockedCard title="Accident History" />
                            <p className="mt-2 text-xs italic text-slate-400">Total loss or Category S/N records may be present.</p>
                        </div>
                    </div>
                )}
            </div>

            {modal === 'login_required' && <LoginRequiredModal onClose={() => setModal(null)} />}
            {modal === 'credits_exhausted' && <CreditsExhaustedModal onClose={() => setModal(null)} />}
        </>
    );
}

function InfoCard({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs uppercase text-slate-400">{label}</p>
            <p className="mt-1 font-semibold text-[#151c27]">{value}</p>
        </div>
    );
}

function LockedCard({ title }: { title: string }) {
    return (
        <div className="rounded-lg border border-slate-200 p-6 text-center">
            <Lock className="mx-auto mb-2 size-5 text-slate-300" />
            <p className="font-semibold text-slate-600">{title}</p>
            <p className="text-xs text-slate-400">Premium Only</p>
        </div>
    );
}

ShowReport.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;