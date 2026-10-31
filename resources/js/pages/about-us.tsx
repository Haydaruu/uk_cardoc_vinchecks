import { Head, Link } from '@inertiajs/react';
import {
    ShieldCheck,
    FileCheck2,
    Gavel,
    Siren,
    Crosshair,
    Zap,
    ArrowRight,
} from 'lucide-react';
import BaseLayout from '@/layouts/base-layout';

const dataPartners = [
    { icon: ShieldCheck, label: 'DVLA' },
    { icon: FileCheck2, label: 'MOT History' },
    { icon: Gavel, label: 'MIAFTR' },
    { icon: Siren, label: 'Police National Computer' },
];

const pillars = [
    {
        icon: Crosshair,
        title: 'Precision Engineering',
        description:
            'Our algorithms cross-reference billions of data points to ensure absolutely no detail is missed during our comprehensive vehicle evaluations.',
        accent: 'primary',
    },
    {
        icon: Zap,
        title: 'Unrivaled Speed',
        description:
            'Real-time checks delivered in seconds. We operate on high-availability infrastructure to provide instant answers when you are standing on the forecourt.',
        accent: 'primary',
    },
    {
        icon: ShieldCheck,
        title: 'Institutional Trust',
        description:
            "The 'Sovereign Assurance' standard for vehicle provenance. We adhere to the strictest data protection and accuracy standards in the UK automotive sector.",
        accent: 'secondary',
    },
];

export default function AboutUs() {
    return (
        <>
            <Head title="About Us" />

            {/* Hero */}
            <section className="bg-primary py-section-padding">
                <div className="mx-auto max-w-container-max px-gutter">
                    <div className="max-w-3xl">
                        <div className="font-label-sm mb-6 inline-flex items-center gap-2 text-label-sm tracking-widest text-white/60 uppercase">
                            <span className="h-px w-8 bg-secondary" />
                            Corporate Identity
                        </div>
                        <h1 className="font-h1 mb-6 text-h1 text-white">
                            Institutional Assurance in Automotive Data
                        </h1>
                        <p className="font-body-lg max-w-2xl text-body-lg text-white/70">
                            UKcardoc provides the most comprehensive and
                            reliable vehicle history reports in the United
                            Kingdom, powered by direct official data citations.
                        </p>
                    </div>
                </div>
            </section>

            {/* Our Mission */}
            <section className="bg-surface-container-lowest py-section-padding">
                <div className="mx-auto max-w-container-max px-gutter">
                    <div className="grid grid-cols-1 items-center gap-16 md:grid-cols-2">
                        <div>
                            <h2 className="font-h2 mb-6 text-h2 text-primary">
                                Our Mission
                            </h2>
                            <div className="sovereign-line" />
                            <p className="font-body-lg border-l-4 border-surface-container-high py-2 pl-6 text-body-lg text-on-surface-variant italic">
                                "To empower every British motorist and
                                automotive professional with the truth behind
                                every registration plate."
                            </p>
                            <p className="mt-6 text-on-surface-variant">
                                We believe that transparency in the secondary
                                car market is not just a preference, but a
                                fundamental right. By operating at the
                                intersection of public registry data and
                                advanced algorithmic analysis, we eliminate
                                asymmetry of information.
                            </p>
                        </div>
                        <div className="relative aspect-video overflow-hidden rounded-xl border border-surface-variant shadow-sm">
                            <img
                                src="https://images.unsplash.com/photo-1497366216548-37526070297c?w=1200&q=80"
                                alt="Modern corporate office analyzing vehicle data"
                                className="h-full w-full object-cover"
                            />
                        </div>
                    </div>
                </div>
            </section>

            {/* Official Data Partners */}
            <section className="bg-surface py-section-padding">
                <div className="mx-auto max-w-container-max px-gutter text-center">
                    <span className="font-label-sm mb-4 block text-label-sm tracking-widest text-primary uppercase">
                        Official Citations
                    </span>
                    <h2 className="font-h2 mb-12 text-h2 text-primary">
                        Data Sourced Directly From Institutional Registries
                    </h2>
                    <div className="grid grid-cols-2 gap-8 md:grid-cols-4">
                        {dataPartners.map(({ icon: Icon, label }) => (
                            <div
                                key={label}
                                className="flex flex-col items-center justify-center rounded border border-surface-variant bg-surface-container-lowest p-8 opacity-70 shadow-[0_4px_20px_rgba(0,32,91,0.04)] grayscale transition-all duration-300 hover:opacity-100 hover:grayscale-0"
                            >
                                <Icon
                                    className="mb-4 size-9 text-primary"
                                    strokeWidth={1.5}
                                />
                                <span className="font-label-sm text-center text-label-sm text-primary">
                                    {label}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Why UKcardoc */}
            <section className="bg-surface-container-lowest py-section-padding">
                <div className="mx-auto max-w-container-max px-gutter">
                    <div className="mb-16 text-center">
                        <h2 className="font-h2 text-h2 text-primary">
                            Why Choose UKcardoc
                        </h2>
                        <div className="sovereign-line mx-auto w-24" />
                    </div>
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                        {pillars.map(
                            ({ icon: Icon, title, description, accent }) => (
                                <div
                                    key={title}
                                    className="flex flex-col rounded-lg border border-surface-variant bg-surface p-8 shadow-[0_4px_20px_rgba(0,32,91,0.04)] transition-colors hover:border-primary"
                                >
                                    <div
                                        className={`mb-6 flex size-12 items-center justify-center rounded ${
                                            accent === 'secondary'
                                                ? 'bg-secondary text-white'
                                                : 'bg-primary-container text-white'
                                        }`}
                                    >
                                        <Icon className="size-6" />
                                    </div>
                                    <h3 className="font-h3 mb-3 text-h3 text-primary">
                                        {title}
                                    </h3>
                                    <p className="text-on-surface-variant">
                                        {description}
                                    </p>
                                </div>
                            ),
                        )}
                    </div>
                </div>
            </section>

            {/* CTA */}
            <section className="relative isolate overflow-hidden bg-primary py-16 md:py-24">
                {/* Soft ambient glow — replaces the striped background */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute -top-32 -right-24 -z-10 size-96 rounded-full bg-secondary/15 blur-3xl"
                />
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute -bottom-40 left-1/4 -z-10 size-80 rounded-full bg-white/5 blur-3xl"
                />

                <div className="mx-auto max-w-container-max px-gutter">
                    <div className="grid items-center gap-10 md:grid-cols-[minmax(0,1fr)_auto] md:gap-16">
                        <div className="max-w-2xl">
                            <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-4 py-2">
                                <span className="size-2 rounded-full bg-secondary" />
                                <span className="text-xs font-semibold tracking-[0.16em] text-white/70 uppercase">
                                    Vehicle Intelligence
                                </span>
                            </div>

                            <h2 className="font-h2 mb-5 max-w-xl text-h2 text-white">
                                Ready to verify your next vehicle?
                            </h2>

                            <p className="max-w-xl text-base leading-7 text-white/70 md:text-lg">
                                Get the vehicle history information you need to
                                make a more informed decision before your next
                                purchase.
                            </p>

                            <div className="mt-8 flex flex-wrap items-center gap-4">
                                <Link
                                    href="/"
                                    className="group inline-flex items-center gap-3 rounded-lg bg-secondary px-6 py-4 font-semibold text-white shadow-lg shadow-black/10 transition-all duration-200 hover:-translate-y-0.5 hover:bg-secondary-container focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-primary focus-visible:outline-none"
                                >
                                    Start a Check
                                    <ArrowRight className="size-5 transition-transform duration-200 group-hover:translate-x-1" />
                                </Link>

                                <Link
                                    href="/pricing"
                                    className="inline-flex items-center gap-2 rounded-lg border border-white/20 px-6 py-4 font-semibold text-white/85 transition-colors hover:border-white/40 hover:bg-white/5 hover:text-white"
                                >
                                    View Pricing
                                </Link>
                            </div>
                        </div>

                        <div className="hidden md:flex md:justify-center">
                            <div className="flex size-40 items-center justify-center rounded-3xl border border-white/10 bg-white/[0.04] shadow-[0_24px_80px_rgba(0,0,0,0.18)]">
                                <div className="flex size-24 items-center justify-center rounded-2xl border border-white/10 bg-white/[0.05]">
                                    <ShieldCheck
                                        className="size-12 text-white/90"
                                        strokeWidth={1.25}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </>
    );
}

AboutUs.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;
