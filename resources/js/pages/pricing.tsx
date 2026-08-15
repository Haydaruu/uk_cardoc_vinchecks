import { Head, usePage } from '@inertiajs/react';
import PricingCard from '@/components/marketing/pricing-card';
import BaseLayout from '@/layouts/base-layout';
import { register } from '@/routes';
import { Check, Shield } from 'lucide-react';

type PageProps = {
    auth: {
        user: { id: number; role: string; name: string; email: string; credits: number; is_premium: boolean } | null;
    };
};

function Pricing() {
    const { props } = usePage<PageProps>();
    const isLoggedIn = !!props.auth.user;

    const pricingPlans = [
        {
            tier: 'Single Report',
            title: '1 Credit',
            pricePerReport: '£19.99',
            totalPrice: '£19.99',
            bullets: [
                "You'll get 5 premium reports",
                'Full premium vehicle report',
            ],
            ctaLabel: 'Get 1 Credit',
            ctaHref: isLoggedIn ? '/checkout?plan=1-credit' : register.url(),
        },
        {
            tier: 'Best Value',
            title: '5 Credits',
            pricePerReport: '£14.00',
            totalPrice: '£69.99',
            bullets: [
                "You'll get 5 premium reports",
                'Full premium vehicle report',
            ],
            ctaLabel: 'Get 5 Credits',
            ctaHref: isLoggedIn ? '/checkout?plan=5-credits' : register.url(),
            highlighted: true,
            badge: 'Most Popular',
        },
        {
            tier: 'Bulk Pack',
            title: '10 Credits',
            pricePerReport: '£12.00',
            totalPrice: '£119.99',
            bullets: [
                "You'll get 10 premium reports",
                'Full premium vehicle report',
            ],
            ctaLabel: 'Get 10 Credits',
            ctaHref: isLoggedIn ? '/checkout?plan=10-credits' : register.url(),
        },
    ];

    return (
        <>
            <Head title="Pricing" />

            {/* Hero */}
            <section className="bg-white py-20 text-center">
                <div className="mx-auto max-w-[800px] px-6 md:px-10">
                    <span className="inline-block rounded-full bg-surface-container px-4 py-1.5 text-[10px] font-bold uppercase tracking-widest text-primary-container">
                        Transparent Pricing
                    </span>
                    <h1 className="mt-6 text-[clamp(1.8rem,4vw,2.75rem)] font-black tracking-tight text-primary-container">
                        Precision vehicle history for total peace of mind.
                    </h1>
                    <p className="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-slate-500">
                        Buy credits in bulk and save — every credit unlocks one full premium
                        report sourced directly from DVLA, HPI, and VOSA databases.
                    </p>
                </div>
            </section>

            {/* Pricing Cards */}
            <section className="bg-white pb-20">
                <div className="mx-auto grid max-w-[1200px] gap-6 px-6 md:grid-cols-3 md:px-10">
                    {pricingPlans.map((plan) => (
                        <PricingCard key={plan.title} {...plan} />
                    ))}
                </div>
                <p className="mt-8 text-center text-xs text-slate-400">
                    VAT may apply. Credits never expire.
                </p>
            </section>

            {/* Trust Section — tidak berubah */}
            <section className="border-t border-slate-100 bg-white py-16">
                <div className="mx-auto grid max-w-[1200px] gap-6 px-6 md:grid-cols-3 md:px-10">
                    <div className="rounded-sm bg-primary-container p-8 text-white">
                        <h3 className="text-lg font-bold">Official Data Sources</h3>
                        <p className="mt-3 text-sm leading-relaxed text-slate-300">
                            Our system integrates directly with UK government and insurance
                            industry databases to provide real-time updates.
                        </p>
                        <div className="mt-6 flex gap-3">
                            {['DVLA', 'HPI', 'VOSA'].map((source) => (
                                <div
                                    key={source}
                                    className="rounded-sm bg-white/10 px-4 py-2 text-[10px] font-bold uppercase tracking-widest text-white/70"
                                >
                                    {source}
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="rounded-sm border border-slate-200 bg-white p-8">
                        <div className="mb-4 flex size-10 items-center justify-center rounded-full bg-secondary/10">
                            <Check size={20} className="text-secondary" />
                        </div>
                        <h3 className="text-lg font-bold text-primary-container">HPI Clear</h3>
                        <p className="mt-3 text-sm leading-relaxed text-slate-500">
                            Recognized industry-standard verification for all high-level reports.
                        </p>
                    </div>

                    <div className="rounded-sm border border-slate-200 bg-white p-8">
                        <div className="mb-4 flex size-10 items-center justify-center rounded-full bg-secondary/10">
                            <Shield size={20} className="text-secondary" />
                        </div>
                        <h3 className="text-lg font-bold text-primary-container">Secure Payment</h3>
                        <p className="mt-3 text-sm leading-relaxed text-slate-500">
                            Encrypted transactions via Stripe and PayPal with VAT receipts.
                        </p>
                    </div>
                </div>
            </section>

            {/* Visual Feature — tidak berubah */}
            <section className="relative overflow-hidden">
                <div
                    className="absolute inset-0 bg-cover bg-center"
                    style={{
                        backgroundImage:
                            "url('https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?w=1600&q=80')",
                    }}
                />
                <div className="absolute inset-0 bg-primary-container/85" />
                <div className="relative mx-auto max-w-[1200px] px-6 py-24 md:px-10">
                    <div className="sovereign-line mb-6 w-12" />
                    <p className="mb-4 text-sm text-slate-300">
                        Built with British precision for the UK secondary market.
                    </p>
                    <p className="max-w-lg text-lg font-medium leading-relaxed text-white">
                        Every report is generated using the latest data sync technology to ensure
                        you never miss a critical detail about your potential purchase.
                    </p>
                </div>
            </section>
        </>
    );
}

Pricing.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default Pricing;