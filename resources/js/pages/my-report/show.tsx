import { Head, router, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { Lock } from 'lucide-react';
import BaseLayout from '@/layouts/base-layout';
import LoginRequiredModal from '@/components/report/login-required-modal';
import CreditExhaustedModal from '@/components/report/credits-exhausted-modal';

type PageProps = {
    flash: {modal?: 'login_required' | 'credit_exhausted' };
}
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
            tax?: { taxStatus: string };
            mot?: { motStatus: string; motDueDate: string };
        };
    };
};

export default function ShowReport({ report }: ReportProps) {
    const { data } = report;
    const isPremium = report.report_type === 'premium';
    const { props } = usePage<PageProps>();
    const [ modal, setModal] = useState<string | null>(null);

    useEffect(() => {
        if (props.flash?.modal) setModal(props.flash.modal);
    }, [props.flash?.modal])

    function handleUnlock() {
        router.post(`/reports/${report.id}/unclock`)
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

                <div className="grid gap-4 md:grid-cols-3">
                    <InfoCard label="Fuel Type" value={data.fuelType} />
                    <InfoCard label="Engine" value={`${data.engineCapacity}cc`} />
                    <InfoCard label="Year" value={String(data.yearOfManufacture)} />
                </div>

                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    <InfoCard label="Tax Status" value={data.tax?.taxStatus ?? 'Unknown'} />
                    <InfoCard label="MOT Status" value={data.mot?.motStatus ?? 'Unknown'} />
                </div>

                {!isPremium && (
                    <div className="mt-6 grid gap-4 md:grid-cols-2">
                        <LockedCard title="Outstanding Finance" />
                        <LockedCard title="Accident History" />
                    </div>
                )}
            </div>

            {modal === 'login_required' && <LoginRequiredModal onClose={() => setModal(null)} />}
            {modal === 'credits_exhausted' && <CreditExhaustedModal onClose={() => setModal(null)} />}
        </>
    );
}

function InfoCard({ label, value }: { label: string; value: string }) {
     return (
        <div className="rounded-lg border border-slate-200 p-4">
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