import { Head, Link } from '@inertiajs/react';
import { ShieldCheck, FileCheck2, Gavel, Siren, Crosshair, Zap, ArrowRight } from 'lucide-react';
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
                        <div className="mb-6 inline-flex items-center gap-2 font-label-sm text-label-sm uppercase tracking-widest text-white/60">
                            <span className="h-px w-8 bg-secondary" />
                            Corporate Identity
                        </div>
                        <h1 className="font-h1 text-h1 mb-6 text-white">
                            Institutional Assurance in Automotive Data
                        </h1>
                        <p className="font-body-lg text-body-lg max-w-2xl text-white/70">
                            UKcardoc provides the most comprehensive and reliable vehicle history reports in the
                            United Kingdom, powered by direct official data citations.
                        </p>
                    </div>
                </div>
            </section>

            {/* Our Mission */}
            <section className="py-section-padding bg-surface-container-lowest">
                <div className="mx-auto max-w-container-max px-gutter">
                    <div className="grid grid-cols-1 items-center gap-16 md:grid-cols-2">
                        <div>
                            <h2 className="font-h2 text-h2 mb-6 text-primary">Our Mission</h2>
                            <div className="sovereign-line" />
                            <p className="font-body-lg text-body-lg border-l-4 border-surface-container-high py-2 pl-6 italic text-on-surface-variant">
                                "To empower every British motorist and automotive professional with the truth behind
                                every registration plate."
                            </p>
                            <p className="mt-6 text-on-surface-variant">
                                We believe that transparency in the secondary car market is not just a preference,
                                but a fundamental right. By operating at the intersection of public registry data
                                and advanced algorithmic analysis, we eliminate asymmetry of information.
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
            <section className="py-section-padding bg-surface">
                <div className="mx-auto max-w-container-max px-gutter text-center">
                    <span className="mb-4 block font-label-sm text-label-sm uppercase tracking-widest text-primary">
                        Official Citations
                    </span>
                    <h2 className="font-h2 text-h2 mb-12 text-primary">
                        Data Sourced Directly From Institutional Registries
                    </h2>
                    <div className="grid grid-cols-2 gap-8 md:grid-cols-4">
                        {dataPartners.map(({ icon: Icon, label }) => (
                            <div
                                key={label}
                                className="flex flex-col items-center justify-center rounded border border-surface-variant bg-surface-container-lowest p-8 opacity-70 shadow-[0_4px_20px_rgba(0,32,91,0.04)] grayscale transition-all duration-300 hover:opacity-100 hover:grayscale-0"
                            >
                                <Icon className="mb-4 size-9 text-primary" strokeWidth={1.5} />
                                <span className="text-center font-label-sm text-label-sm text-primary">{label}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* Why UKcardoc */}
            <section className="py-section-padding bg-surface-container-lowest">
                <div className="mx-auto max-w-container-max px-gutter">
                    <div className="mb-16 text-center">
                        <h2 className="font-h2 text-h2 text-primary">Why Choose UKcardoc</h2>
                        <div className="sovereign-line mx-auto w-24" />
                    </div>
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                        {pillars.map(({ icon: Icon, title, description, accent }) => (
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
                                <h3 className="font-h3 text-h3 mb-3 text-primary">{title}</h3>
                                <p className="text-on-surface-variant">{description}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* CTA */}
            <section className="bg-primary relative overflow-hidden py-section-padding">
                <div
                    className="absolute inset-0 opacity-10"
                    style={{
                        backgroundImage:
                            'repeating-linear-gradient(45deg, transparent, transparent 10px, #ffffff 10px, #ffffff 11px)',
                    }}
                />
                <div className="relative z-10 mx-auto max-w-container-max px-gutter text-center">
                    <h2 className="font-h2 text-h2 mb-8 text-white">Ready to verify your next vehicle?</h2>
                    <Link
                        href="/"
                        className="inline-flex items-center gap-2 rounded bg-secondary px-8 py-4 font-h3 text-h3 text-white shadow-[inset_0_2px_0_rgba(255,255,255,0.3)] transition-colors hover:bg-secondary-container"
                    >
                        Start Check
                        <ArrowRight className="size-5" />
                    </Link>
                </div>
            </section>
        </>
    );
}

AboutUs.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;