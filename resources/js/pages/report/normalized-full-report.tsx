import { Head } from '@inertiajs/react';
import { Download, Share2 } from 'lucide-react';

import NormalizedReportPreview from '@/components/report/normalized-report-preview';
import BaseLayout from '@/layouts/base-layout';
import type { NormalizedReport } from '@/types/normalized-report';

type Props = {
    report: {
        id: number;
        report_type: 'premium';
        generated_at?: string | null;
        data: NormalizedReport;
    };
};

export default function NormalizedFullReport({
    report,
}: Props) {
    const vehicle = report.data.vehicle;

    return (
        <>
            <Head
                title={`${vehicle.make ?? 'Vehicle'} ${
                    vehicle.model ?? ''
                } — Full Report`}
            />

            <main className="mx-auto max-w-[1200px] px-4 py-8 md:px-6 lg:px-0">
                <NormalizedReportPreview
                    report={report.data}
                />

                <div className="mt-10 flex flex-col justify-center gap-4 sm:flex-row">
                    <button
                        type="button"
                        className="flex items-center justify-center gap-2 rounded-md border-2 border-primary-container px-8 py-3 text-sm font-bold uppercase tracking-wider text-primary-container transition-all hover:bg-slate-50"
                    >
                        <Download className="size-4" />

                        Download PDF Report
                    </button>

                    <button
                        type="button"
                        className="flex items-center justify-center gap-2 rounded-md bg-secondary px-8 py-3 text-sm font-bold uppercase tracking-wider text-on-secondary shadow-md transition-transform hover:bg-secondary-container active:scale-[0.98]"
                    >
                        <Share2 className="size-4" />

                        Share Report Link
                    </button>
                </div>
            </main>
        </>
    );
}

NormalizedFullReport.layout = (
    page: React.ReactNode,
) => <BaseLayout>{page}</BaseLayout>;