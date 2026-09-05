import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import PricingCard from '@/components/marketing/pricing-card';
import BaseLayout from '@/layouts/base-layout';
import { register } from '@/routes';

import {
    Check,
    Shield,
    Crown,
    Sparkles,
} from 'lucide-react';

type PricingMode = 'credits' | 'membership';

type PageProps = {
    auth: {
        user: {
            id: number;
            role: string;
            name: string;
            email: string;
            credits: number;
            is_premium: boolean;
        } | null;
    };
};

type MembershipPlan = {
    slug: string;
    tier: string;
    name: string;
    price: string;
    billingPeriod: string;
    credits: number;
    benefits: string[];
    highlighted?: boolean;
    badge?: string;
    available?: boolean;
};

function Pricing() {
    const { props } = usePage<PageProps>();

    const isLoggedIn = !!props.auth.user;

    const [pricingMode, setPricingMode] =
        useState<PricingMode>('credits');

    const [isSubscribing, setIsSubscribing] =
        useState(false);

    const subscribe = (plan: string) => {
        if (!isLoggedIn) {
            router.visit(register.url());
            return;
        }

        router.post(
            '/settings/subscription/checkout',
            { plan },
            {
                onStart: () => setIsSubscribing(true),
                onFinish: () => setIsSubscribing(false),
            },
        );
    };

    const creditPlans = [
        {
            tier: 'Single Report',
            title: '1 Credit',
            pricePerReport: '£19.99',
            totalPrice: '£19.99',
            bullets: [
                "You'll get 1 credit for a premium report",
                'Full premium vehicle report',
            ],
            ctaLabel: 'Get 1 Credit',
            ctaHref: isLoggedIn
                ? '/checkout?plan=1-credit'
                : register.url(),
        },

        {
            tier: 'Best Value',
            title: '5 Credits',
            pricePerReport: '£14.00',
            totalPrice: '£69.99',
            bullets: [
                "You'll get 5 credits for premium reports",
                'Full premium vehicle report',
            ],
            ctaLabel: 'Get 5 Credits',
            ctaHref: isLoggedIn
                ? '/checkout?plan=5-credits'
                : register.url(),
            highlighted: true,
            badge: 'Most Popular',
        },

        {
            tier: 'Regular Pack',
            title: '3 Credits',
            pricePerReport: '£18.00',
            totalPrice: '£53.99',
            bullets: [
                "You'll get 3 credits for premium reports",
                'Full premium vehicle report',
            ],
            ctaLabel: 'Get 3 Credits',
            ctaHref: isLoggedIn
                ? '/checkout?plan=3-credits'
                : register.url(),
        },
    ];

    const membershipPlans: MembershipPlan[] = [
        {
            slug: 'premium-monthly',
            tier: 'Monthly Plan',
            name: 'Premium',
            price: '£39.99',
            billingPeriod: 'month',
            credits: 15,
            benefits: [
                '15 credits every month',
                'Unused credits never expire',
                'Automatic monthly refill',
                'Cancel anytime',
            ],
            available: true,
        },

        {
            slug: 'premium-plus-monthly',
            tier: 'Best Value',
            name: 'Premium Plus',
            price: '£59.99',
            billingPeriod: 'month',
            credits: 25,
            benefits: [
                '25 credits every month',
                'Unused credits never expire',
                'Automatic monthly refill',
                'Cancel anytime',
            ],
            highlighted: true,
            badge: 'Most Popular',
            available: false,
        },

        {
            slug: 'premium-max-monthly',
            tier: 'Power User',
            name: 'Premium Max',
            price: '£89.99',
            billingPeriod: 'month',
            credits: 35,
            benefits: [
                '35 credits every month',
                'Unused credits never expire',
                'Automatic monthly refill',
                'Cancel anytime',
            ],
            available: false,
        },
    ];

    return (
        <>
            <Head title="Pricing" />

            {/* Hero */}
            <section className="bg-white pb-12 pt-20 text-center">
                <div className="mx-auto max-w-[800px] px-6 md:px-10">
                    <span className="inline-block rounded-full bg-surface-container px-4 py-1.5 text-[10px] font-bold uppercase tracking-widest text-primary-container">
                        Transparent Pricing
                    </span>

                    <h1 className="mt-6 text-[clamp(1.8rem,4vw,2.75rem)] font-black tracking-tight text-primary-container">
                        Precision vehicle history for total peace of mind.
                    </h1>

                    <p className="mx-auto mt-4 max-w-xl text-sm leading-relaxed text-slate-500">
                        {pricingMode === 'credits'
                            ? 'Buy credits when you need them — every credit unlocks one full premium vehicle report.'
                            : 'Get credits automatically every month with a flexible UKCarDoc membership.'}
                    </p>
                </div>
            </section>

            {/* Pricing Toggle */}
            <section className="bg-white pb-12">
                <div className="flex justify-center px-6">
                    <div className="inline-flex rounded-full border border-slate-200 bg-surface-container-low p-1.5 shadow-sm">

                        <button
                            type="button"
                            onClick={() =>
                                setPricingMode('credits')
                            }
                            className={`rounded-full px-6 py-2.5 text-sm font-bold transition-all ${
                                pricingMode === 'credits'
                                    ? 'bg-primary-container text-white shadow-sm'
                                    : 'text-slate-500 hover:text-primary-container'
                            }`}
                        >
                            Credit Packs
                        </button>

                        <button
                            type="button"
                            onClick={() =>
                                setPricingMode('membership')
                            }
                            className={`rounded-full px-6 py-2.5 text-sm font-bold transition-all ${
                                pricingMode === 'membership'
                                    ? 'bg-primary-container text-white shadow-sm'
                                    : 'text-slate-500 hover:text-primary-container'
                            }`}
                        >
                            Membership
                        </button>

                    </div>
                </div>
            </section>

            {/* Pricing Content */}
            <section className="min-h-[480px] bg-white pb-20">

                {/* Credit Packs */}
                {pricingMode === 'credits' && (
                    <div className="animate-in fade-in duration-200">

                        <div className="mx-auto grid max-w-[1200px] gap-6 px-6 md:grid-cols-3 md:px-10">
                            {creditPlans.map((plan) => (
                                <PricingCard
                                    key={plan.title}
                                    {...plan}
                                />
                            ))}
                        </div>

                        <p className="mt-8 text-center text-xs text-slate-400">
                            VAT may apply. Credits never expire.
                        </p>

                    </div>
                )}

               {/* Membership */}
                {pricingMode === 'membership' && (
                    <div className="animate-in fade-in duration-200">
                        <div className="mx-auto grid max-w-[1200px] gap-6 px-6 md:grid-cols-3 md:px-10">
                            {membershipPlans.map((plan) => (
                                <div
                                    key={plan.name}
                                    className={`relative flex flex-col rounded-sm border bg-white p-8 transition-shadow hover:shadow-md ${
                                        plan.highlighted
                                            ? 'border-secondary shadow-lg shadow-secondary/10'
                                            : 'border-slate-200'
                                    }`}
                                >
                                    {/* Badge */}
                                    {plan.badge && (
                                        <div className="absolute -top-px left-0 right-0 rounded-t-sm bg-secondary py-2 text-center text-[10px] font-bold uppercase tracking-widest text-white">
                                            {plan.badge}
                                        </div>
                                    )}

                                    <div className={plan.badge ? 'mt-6' : ''}>
                                        {/* Tier */}
                                        <div className="flex items-center gap-2">
                                            <Crown
                                                size={14}
                                                className={
                                                    plan.highlighted
                                                        ? 'text-secondary'
                                                        : 'text-slate-400'
                                                }
                                            />

                                            <p
                                                className={`text-[10px] font-bold uppercase tracking-widest ${
                                                    plan.highlighted
                                                        ? 'text-secondary'
                                                        : 'text-slate-400'
                                                }`}
                                            >
                                                {plan.tier}
                                            </p>
                                        </div>

                                        {/* Title */}
                                        <h3 className="mt-2 text-2xl font-black tracking-tight text-primary-container">
                                            {plan.name}
                                        </h3>

                                        {/* Premium Badge */}
                                        <div className="mt-3">
                                            <span className="inline-flex items-center gap-1.5 rounded-sm bg-surface-container px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest text-primary-container">
                                                <Crown size={11} />
                                                Premium Member
                                            </span>
                                        </div>

                                        {/* Price */}
                                        <div className="mt-4 flex items-baseline gap-2">
                                            <span className="text-4xl font-black tracking-tight text-primary-container">
                                                {plan.price}
                                            </span>

                                            <span className="text-sm text-slate-400">
                                                / {plan.billingPeriod}
                                            </span>
                                        </div>

                                        {/* Credits */}
                                        <div className="mt-2">
                                            <span className="text-sm font-semibold text-slate-600">
                                                {plan.credits} credits every month
                                            </span>
                                        </div>
                                    </div>

                                    {/* Benefits */}
                                    <ul className="mt-8 flex-1 space-y-3 border-t border-slate-100 pt-6">
                                        {plan.benefits.map((benefit) => (
                                            <li
                                                key={benefit}
                                                className="flex items-start gap-3"
                                            >
                                                <Check
                                                    size={16}
                                                    className="mt-0.5 shrink-0 text-secondary"
                                                />

                                                <span className="text-sm text-slate-600">
                                                    {benefit}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>

                                    {/* CTA */}
                                    
                                        <button
                                            type="button"
                                            disabled={isSubscribing}
                                            onClick={() => subscribe(plan.slug)}
                                            className={`mt-8 rounded-sm py-3.5 text-center text-[11px] font-bold uppercase tracking-widest transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${
                                                plan.highlighted
                                                    ? 'bg-secondary text-white hover:bg-secondary-container'
                                                    : 'border border-slate-800 text-slate-800 hover:bg-slate-50'
                                            }`}
                                        >
                                            {isSubscribing
                                                ? 'Redirecting...'
                                                : isLoggedIn
                                                ? 'Subscribe'
                                                : 'Create Account'}
                                        </button>
                                </div>
                            ))}
                        </div>

                        <p className="mt-8 text-center text-xs text-slate-400">
                            Monthly billing. Cancel anytime. Unused credits never expire.
                        </p>
                    </div>
                )}
            </section>

            {/* Trust Section */}
            <section className="border-t border-slate-100 bg-white py-16">
                <div className="mx-auto grid max-w-[1200px] gap-6 px-6 md:grid-cols-3 md:px-10">

                    <div className="rounded-sm bg-primary-container p-8 text-white">
                        <h3 className="text-lg font-bold">
                            Official Data Sources
                        </h3>

                        <p className="mt-3 text-sm leading-relaxed text-slate-300">
                            Our system integrates directly with UK government
                            and insurance industry databases to provide
                            real-time updates.
                        </p>

                        <div className="mt-6 flex gap-3">
                            {['DVLA', 'HPI', 'VOSA'].map(
                                (source) => (
                                    <div
                                        key={source}
                                        className="rounded-sm bg-white/10 px-4 py-2 text-[10px] font-bold uppercase tracking-widest text-white/70"
                                    >
                                        {source}
                                    </div>
                                ),
                            )}
                        </div>
                    </div>

                    <div className="rounded-sm border border-slate-200 bg-white p-8">
                        <div className="mb-4 flex size-10 items-center justify-center rounded-full bg-secondary/10">
                            <Check
                                size={20}
                                className="text-secondary"
                            />
                        </div>

                        <h3 className="text-lg font-bold text-primary-container">
                            HPI Clear
                        </h3>

                        <p className="mt-3 text-sm leading-relaxed text-slate-500">
                            Recognized industry-standard verification for all
                            high-level reports.
                        </p>
                    </div>

                    <div className="rounded-sm border border-slate-200 bg-white p-8">
                        <div className="mb-4 flex size-10 items-center justify-center rounded-full bg-secondary/10">
                            <Shield
                                size={20}
                                className="text-secondary"
                            />
                        </div>

                        <h3 className="text-lg font-bold text-primary-container">
                            Secure Payment
                        </h3>

                        <p className="mt-3 text-sm leading-relaxed text-slate-500">
                            Encrypted transactions via Stripe and PayPal with
                            VAT receipts.
                        </p>
                    </div>

                </div>
            </section>

            {/* Visual Feature */}
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
                        Built with British precision for the UK secondary
                        market.
                    </p>

                    <p className="max-w-lg text-lg font-medium leading-relaxed text-white">
                        Every report is generated using the latest data sync
                        technology to ensure you never miss a critical detail
                        about your potential purchase.
                    </p>
                </div>
            </section>
        </>
    );
}

Pricing.layout = (page: React.ReactNode) => (
    <BaseLayout>{page}</BaseLayout>
);

export default Pricing;