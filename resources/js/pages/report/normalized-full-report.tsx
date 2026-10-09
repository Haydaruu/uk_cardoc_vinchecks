import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Download, Loader2, Share2 } from 'lucide-react';

import NormalizedReportPreview from '@/components/report/normalized-report-preview';
import BaseLayout from '@/layouts/base-layout';
import type { NormalizedReport } from '@/types/normalized-report';

type Props = {
    report: {
        id: number;
        report_type: 'premium';
        generated_at?: string | null;
        pdf_url?: string | null;
        data: NormalizedReport;
    };
};

export default function NormalizedFullReport({
    report,
}: Props) {
    const vehicle = report.data.vehicle;

    const [isDownloading, setIsDownloading] = useState(false);

    async function handlePdfDownload() {
        if (isDownloading) {
            return;
        }

        setIsDownloading(true);

        try{
            const response = await fetch(report.pdf_url ?? `/report/${report.id}/pdf`, 
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-cache',
                },
            );

            if (!response.ok) {
                throw new Error(`PDF download failed: ${response.status}`);
            }

            const blob = await response.blob();
            const contentDisposition = response.headers.get('Content-Disposition');
            const filenameMatch = contentDisposition?.match(/filename="([^"]+)"/i);
            const filename = filenameMatch?.[1] ?? `vehicle-report-${report.id}.pdf`;
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;

            document.body.appendChild(link);

            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        } catch (error) {
            console.error('Error downloading PDF:', error);
            window.alert('Unable to download the PDF report. Please try again.');
        } finally {
            setIsDownloading(false);
        }
    }

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
                        onClick={handlePdfDownload}
                        disabled={isDownloading}
                        className="flex items-center justify-center gap-2 rounded-md border-2 border-primary-container px-8 py-3 text-sm font-bold uppercase tracking-wider text-primary-container transition-all hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70"
                    >
                        {isDownloading ? (
                            <>
                                <Loader2 className="size-4 animate-spin" />

                                Generating PDF...
                            </>
                        ) : (
                            <>
                                <Download className="size-4" />

                                Download PDF Report
                            </>
                        )}
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