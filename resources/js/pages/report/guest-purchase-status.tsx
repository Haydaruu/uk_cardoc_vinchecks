import { Head, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { CheckCircle2, Clock3, CreditCard, LoaderCircle, RefreshCw } from 'lucide-react';

import BaseLayout from '@/layouts/base-layout';

type Props = {
    purchase: {
        status: 'pending' | 'processing' | 'completed' | 'failed';
        reportId: number;
        registrationNumber: string | null;
        reportUrl: string | null;
        checkoutUrl: string;
    };
    paymentStatus?: 'pending' | 'success';
    cancelled?: boolean;
};

export default function GuestPurchaseStatus({ purchase, paymentStatus, cancelled }: Props) {
    useEffect(() => {
        if (purchase.status !== 'processing') return;
        const timer = window.setInterval(() => {
            router.reload({ only: ['purchase', 'paymentStatus'], preserveScroll: true });
        }, 4000);
        return () => window.clearInterval(timer);
    }, [purchase.status]);

    const isComplete = purchase.status === 'completed';

    return (
        <>
            <Head title="Your vehicle report" />
            <main className="mx-auto flex min-h-[70vh] max-w-2xl items-center px-5 py-12">
                <section className="w-full rounded-xl border border-outline-variant bg-white p-7 text-center shadow-sm sm:p-10">
                    <div className="mx-auto mb-5 flex size-14 items-center justify-center rounded-full bg-surface-container">
                        {isComplete ? (
                            <CheckCircle2 className="size-7 text-green-600" />
                        ) : purchase.status === 'processing' ? (
                            <LoaderCircle className="size-7 animate-spin text-primary" />
                        ) : (
                            <CreditCard className="size-7 text-primary" />
                        )}
                    </div>

                    <h1 className="text-2xl font-bold text-primary">
                        {isComplete ? 'Your full report is ready' :
                            purchase.status === 'processing' ? 'Preparing your full report' :
                            purchase.status === 'failed' ? 'We could not finish your report' :
                            'Complete your full report purchase'}
                    </h1>

                    <p className="mx-auto mt-3 max-w-lg text-sm leading-6 text-on-surface-variant">
                        {isComplete
                            ? 'The full report for ' + (purchase.registrationNumber ?? 'your vehicle') + ' is ready to view.'
                            : purchase.status === 'processing'
                                ? 'Your payment has been confirmed. We are retrieving the full vehicle history now. This page will update automatically.'
                                : purchase.status === 'failed'
                                    ? 'Your payment may have completed, but report generation needs attention. Please try again shortly or contact support if this continues.'
                                    : 'You can pay securely without creating an account. You will return here after checkout.'}
                    </p>

                    {cancelled && purchase.status === 'pending' && (
                        <p className="mt-4 rounded-lg bg-surface-container p-3 text-sm text-on-surface-variant">
                            Checkout was cancelled. No payment was confirmed.
                        </p>
                    )}

                    {paymentStatus === 'pending' && (
                        <p className="mt-4 rounded-lg bg-surface-container p-3 text-sm text-on-surface-variant">
                            We have not received payment confirmation yet. If you just paid, please wait a moment.
                        </p>
                    )}

                    <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
                        {isComplete && purchase.reportUrl ? (
                            <a href={purchase.reportUrl} className="inline-flex items-center justify-center gap-2 rounded-md bg-secondary px-6 py-3 text-sm font-bold text-on-secondary hover:bg-secondary-container">
                                <CheckCircle2 className="size-4" />
                                View full report
                            </a>
                        ) : purchase.status === 'processing' ? (
                            <span className="inline-flex items-center justify-center gap-2 rounded-md bg-surface-container px-6 py-3 text-sm font-semibold text-on-surface-variant">
                                <Clock3 className="size-4" />
                                Processing report…
                            </span>
                        ) : (
                            <button
                                type="button"
                                onClick={() => router.post(purchase.checkoutUrl)}
                                className="inline-flex items-center justify-center gap-2 rounded-md bg-secondary px-6 py-3 text-sm font-bold text-on-secondary hover:bg-secondary-container"
                            >
                                <CreditCard className="size-4" />
                                {purchase.status === 'failed' ? 'Try checkout again' : 'Continue to secure checkout'}
                            </button>
                        )}

                        {purchase.status === 'processing' && (
                            <button
                                type="button"
                                onClick={() => router.reload({ only: ['purchase', 'paymentStatus'] })}
                                className="inline-flex items-center justify-center gap-2 rounded-md border border-outline-variant px-6 py-3 text-sm font-semibold text-primary"
                            >
                                <RefreshCw className="size-4" />
                                Refresh status
                            </button>
                        )}
                    </div>

                    <p className="mt-6 text-xs text-outline">
                        Reference: report #{purchase.reportId} · No account required
                    </p>
                </section>
            </main>
        </>
    );
}

GuestPurchaseStatus.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;
