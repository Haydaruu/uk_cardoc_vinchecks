import { Head, router } from '@inertiajs/react';
import { CheckCircle2, CreditCard, ArrowRight, Download } from 'lucide-react';

type CheckoutSuccessProps = {
    order: {
        number: string;
        date: string;
        item: string;
        amount: string;
        currency: string;
        cardBrand: string | null;
        cardLast4: string | null;
    };
};

export default function CheckoutSuccess({ order }: CheckoutSuccessProps) {
    function handleDashboard() {
        router.visit('/');
    }

    return (
        <>
            <Head title="Payment Successful" />

            <div className="flex min-h-screen flex items-center justify-center py bg-background font-body-md text-on-background">
                {/* Main */}
                <main className="bg-pattern relative px-gutter py-section-padding">
                    <div className="absolute left-1/4 top-1/4 -z-10 size-96 rounded-full bg-primary-fixed-dim/20 blur-3xl mix-blend-multiply" />
                    <div className="absolute bottom-1/4 right-1/4 -z-10 size-80 rounded-full bg-secondary-fixed-dim/20 blur-3xl mix-blend-multiply" />

                    <div className="w-full max-w-2xl">
                        <div className="card-shadow overflow-hidden rounded-xl border border-outline-variant bg-surface-container-lowest">
                            <div className="sovereign-line" />

                            <div className="flex flex-col items-center p-8 text-center md:p-12">
                                <div className="relative mb-6 flex size-20 items-center justify-center rounded-full bg-surface-container-low">
                                    <div
                                        className="absolute inset-0 animate-ping rounded-full bg-secondary opacity-10"
                                        style={{ animationDuration: '3s' }}
                                    />
                                    <CheckCircle2 className="size-10 text-secondary" strokeWidth={2} />
                                </div>

                                <h1 className="font-h1 text-h1 mb-2 text-primary">Payment Successful</h1>
                                <p className="font-body-lg text-body-lg mb-8 text-on-surface-variant">
                                    Your credits have been added to your account.
                                </p>

                                <div className="mb-8 w-full rounded-lg border border-outline-variant/50 bg-surface-container-low p-6 text-left">
                                    <h2 className="font-label-sm text-label-sm mb-4 border-b border-outline-variant/30 pb-2 uppercase tracking-widest text-on-surface-variant">
                                        Transaction Summary
                                    </h2>
                                    <div className="grid grid-cols-1 gap-x-8 gap-y-4 md:grid-cols-2">
                                        <SummaryField label="Order Number" value={order.number} />
                                        <SummaryField label="Date" value={order.date} />
                                        <div className="flex flex-col md:col-span-2">
                                            <span className="font-label-sm text-label-sm text-on-surface-variant">
                                                Item
                                            </span>
                                            <span className="font-body-md text-body-md font-semibold text-primary">
                                                {order.item}
                                            </span>
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="font-label-sm text-label-sm text-on-surface-variant">
                                                Total Amount
                                            </span>
                                            <span className="font-h3 text-h3 text-primary">
                                                £{order.amount}
                                            </span>
                                        </div>
                                        {order.cardBrand && order.cardLast4 && (
                                            <div className="flex flex-col">
                                                <span className="font-label-sm text-label-sm text-on-surface-variant">
                                                    Payment Method
                                                </span>
                                                <div className="mt-1 flex items-center gap-2">
                                                    <CreditCard className="size-4 text-primary" />
                                                    <span className="font-body-md text-body-md font-semibold text-primary">
                                                        {order.cardBrand} ending in {order.cardLast4}
                                                    </span>
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <div className="flex w-full flex-col gap-4 md:flex-row">
                                    <button
                                        onClick={handleDashboard}
                                        className="group relative flex flex-1 items-center justify-center gap-2 overflow-hidden rounded bg-secondary px-6 py-4 font-label-sm text-label-sm text-on-secondary shadow-sm transition-colors duration-200 hover:bg-on-secondary-fixed-variant"
                                    >
                                        <div className="absolute left-0 top-0 h-px w-full bg-white/30" />
                                        <span>Go to Dashboard</span>
                                        <ArrowRight className="size-[18px]" />
                                    </button>
                                    {/* Download Receipt — belum ada generator PDF-nya, disable dulu buat MVP */}
                                    <button
                                        disabled
                                        title="Coming soon"
                                        className="flex flex-1 cursor-not-allowed items-center justify-center gap-2 rounded border border-primary/40 px-6 py-4 font-label-sm text-label-sm text-primary/40"
                                    >
                                        <Download className="size-[18px]" />
                                        <span>Download Receipt</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}

function SummaryField({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col">
            <span className="font-label-sm text-label-sm text-on-surface-variant">{label}</span>
            <span className="font-body-md text-body-md font-semibold text-primary">{value}</span>
        </div>
    );
}