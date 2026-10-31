import { Head, Link, useForm } from '@inertiajs/react';
import {
    ShieldCheck,
    FileText,
    History,
    Search,
    ArrowRight,
    CheckCircle,
    AlertTriangle,
} from 'lucide-react';
import { store } from '@/actions/App/Http/Controllers/VehicleCheckController';
import BaseLayout from '@/layouts/base-layout';

function Home() {
    const { data, setData, post, processing, errors } = useForm({
        registration_number: '',
    });

    function handleCheck(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        post(store().url);
    }

    return (
        <>
            <Head title="UK Vehicle History Check" />

            {/* ── HERO ── */}
            <section className="relative flex min-h-[600px] items-center overflow-hidden">
                {/* subtle grid bg */}
                <div
                    className="absolute inset-0 opacity-[0.03]"
                    style={{
                        backgroundImage: "url('/images/home/bg_home.webp')",
                        backgroundSize: '1280px 870px',
                    }}
                />

                <div className="relative mx-auto w-full max-w-[1200px] px-6 py-24 md:px-10">
                    {/* eyebrow */}
                    <p className="mb-4 text-[11px] font-bold tracking-[0.2em] text-secondary uppercase">
                        Official British Vehicle Data
                    </p>

                    {/* headline */}
                    <h1 className="mb-6 max-w-2xl text-[clamp(2.2rem,5vw,3.5rem)] leading-[1.1] font-black tracking-tight text-primary-container">
                        Check any UK vehicle history in seconds
                    </h1>

                    <p className="mb-10 max-w-xl text-base leading-relaxed text-slate-500">
                        Get instant access to MOT records, salvage data,
                        outstanding finance, and technical specifications
                        sourced directly from DVLA, HPI, and VOSA.
                    </p>

                    {/* Search bar */}
                    <form
                        onSubmit={handleCheck}
                        className="flex max-w-xl flex-col items-start gap-0 sm:flex-row"
                    >
                        <div className="w-full flex-1">
                            <div className="relative">
                                <Search className="absolute top-1/2 left-4 h-4 w-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    type="text"
                                    value={data.registration_number}
                                    onChange={(e) =>
                                        setData(
                                            'registration_number',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Enter Registration (e.g. AB12 CDE) or VIN"
                                    className="w-full rounded-sm border border-slate-200 bg-white py-4 pr-4 pl-11 text-sm text-slate-700 shadow-lg shadow-slate-200/60 placeholder:text-slate-300 focus:border-secondary focus:outline-none sm:rounded-l-sm sm:border-r-0"
                                />
                            </div>
                        </div>
                        <button
                            type="submit"
                            disabled={processing}
                            className="flex items-center justify-center gap-2 bg-secondary px-8 py-4 text-sm font-bold tracking-wider whitespace-nowrap text-white uppercase shadow-lg shadow-slate-200/60 transition-colors hover:bg-red-700 sm:rounded-r-sm"
                        >
                            {processing ? 'Checking...' : 'CHECK NOW'}{' '}
                            <ArrowRight size={15} />
                        </button>
                    </form>
                    {errors.registration_number && (
                        <p className="mt-2 max-w-xl text-xs text-red-600">
                            {errors.registration_number}
                        </p>
                    )}

                    {/* trust badges */}
                    <div className="mt-6 flex flex-wrap gap-5">
                        {['DVLA Real-time API', 'Encrypted Secure Reports'].map(
                            (t) => (
                                <span
                                    key={t}
                                    className="flex items-center gap-1.5 text-[12px] font-medium text-slate-400"
                                >
                                    <CheckCircle
                                        size={13}
                                        className="text-slate-400"
                                    />{' '}
                                    {t}
                                </span>
                            ),
                        )}
                    </div>
                </div>
            </section>

            {/* ── DATA PARTNERS ── */}
            <section className="border-y border-slate-100 bg-white py-8">
                <div className="mx-auto max-w-[1200px] px-6 md:px-10">
                    <div className="grid grid-cols-2 gap-8 md:grid-cols-4">
                        {[
                            { name: 'DVLA', sub: 'Verified Source' },
                            { name: 'HPI', sub: 'Data Partner' },
                            { name: 'VOSA', sub: 'Safety Records' },
                            { name: 'PNC', sub: 'Police National Computer' },
                        ].map((p) => (
                            <div
                                key={p.name}
                                className="flex flex-col items-center text-center"
                            >
                                <span className="text-[18px] font-black tracking-tight text-slate-800">
                                    {p.name}
                                </span>
                                <span className="mt-0.5 text-[10px] tracking-widest text-slate-400 uppercase">
                                    {p.sub}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* ── FEATURES ── */}
            <section className="bg-white py-24">
                <div className="mx-auto max-w-[1200px] px-6 md:px-10">
                    <div className="mb-14">
                        <div className="sovereign-line mb-6 w-8" />
                        <h2 className="mb-3 text-[clamp(1.5rem,3vw,2rem)] font-black tracking-tight text-primary-container">
                            Precision data for informed decisions
                        </h2>
                        <div className="flex flex-wrap items-end justify-between gap-4">
                            <p className="max-w-md text-sm leading-relaxed text-slate-500">
                                We provide the most comprehensive vehicle
                                intelligence in the UK, helping you avoid costly
                                mistakes and ensuring your safety on the road.
                            </p>
                            <a
                                href="#"
                                className="flex items-center gap-1.5 text-[11px] font-bold tracking-widest whitespace-nowrap text-slate-500 uppercase transition-colors hover:text-secondary"
                            >
                                EXPLORE FULL REPORT SCOPE{' '}
                                <ArrowRight size={12} />
                            </a>
                        </div>
                    </div>

                    <div className="grid gap-6 md:grid-cols-3">
                        {[
                            {
                                icon: (
                                    <History
                                        size={20}
                                        className="text-slate-600"
                                    />
                                ),
                                title: 'Full History Check',
                                desc: "Deep-dive into the vehicle's past including previous owners, color changes, number plate transfers, and scrapped status.",
                                tags: ['OWNERSHIP', 'TIMELINE'],
                            },
                            {
                                icon: (
                                    <ShieldCheck
                                        size={20}
                                        className="text-slate-600"
                                    />
                                ),
                                title: 'DVLA Verified Data',
                                desc: 'Official real-time data from the DVLA. Confirm engine size, BHP, fuel type, and current tax/MOT status instantly.',
                                tags: ['OFFICIAL', 'LIVE STATUS'],
                            },
                            {
                                icon: (
                                    <FileText
                                        size={20}
                                        className="text-slate-600"
                                    />
                                ),
                                title: 'Instant PDF Reports',
                                desc: 'Generate professional, high-resolution PDF reports that you can share with buyers or keep for your records. Export ready.',
                                tags: ['EXPORT', 'CLOUD ACCESS'],
                            },
                        ].map((card) => (
                            <div
                                key={card.title}
                                className="group rounded-sm border border-slate-100 p-7 transition-all hover:border-slate-200 hover:shadow-sm"
                            >
                                <div className="mb-5">{card.icon}</div>
                                <h3 className="mb-2 text-[15px] font-bold text-primary-container">
                                    {card.title}
                                </h3>
                                <p className="mb-5 text-[13px] leading-relaxed text-slate-500">
                                    {card.desc}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {card.tags.map((t) => (
                                        <span
                                            key={t}
                                            className="rounded-full border border-slate-200 px-2 py-0.5 text-[10px] font-bold tracking-widest text-slate-400 uppercase"
                                        >
                                            {t}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            {/* ── DASHBOARD PREVIEW ── */}
            <section className="bg-primary-container py-20">
                <div className="mx-auto grid max-w-[1200px] items-center gap-16 px-6 md:grid-cols-2 md:px-10">
                    {/* Left copy */}
                    <div>
                        <h2 className="mb-4 text-[clamp(1.5rem,3vw,2rem)] leading-tight font-black tracking-tight text-white">
                            Dashboard-grade clarity for every inspection
                        </h2>
                        <p className="mb-8 text-sm leading-relaxed text-slate-400">
                            The report is structured out of data. They are
                            context-mapped and positioned with data sets to
                            summarise the information from the data — everything
                            from insurance write-offs to outstanding finance at
                            a glance.
                        </p>
                        <div className="space-y-3">
                            <div className="flex items-start gap-3 rounded-sm border border-white/10 bg-white/5 px-4 py-3">
                                <CheckCircle
                                    size={15}
                                    className="mt-0.5 shrink-0 text-green-400"
                                />
                                <div>
                                    <p className="text-[12px] font-bold tracking-widest text-green-400 uppercase">
                                        Pass State
                                    </p>
                                    <p className="mt-0.5 text-[12px] text-slate-400">
                                        The vehicle meets all legal safety
                                        requirements.
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-start gap-3 rounded-sm border border-white/10 bg-white/5 px-4 py-3">
                                <AlertTriangle
                                    size={15}
                                    className="mt-0.5 shrink-0 text-amber-400"
                                />
                                <div>
                                    <p className="text-[12px] font-bold tracking-widest text-amber-400 uppercase">
                                        Advisory State
                                    </p>
                                    <p className="mt-0.5 text-[12px] text-slate-400">
                                        Potential issues detected requiring
                                        attention.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Right — sample report card */}
                    <div className="rounded-sm bg-white p-6 shadow-xl">
                        <div className="mb-5 flex items-start justify-between">
                            <div>
                                <p className="text-xl font-black tracking-tight text-primary-container">
                                    LR19 GKN
                                </p>
                                <p className="mt-0.5 text-[11px] tracking-widest text-slate-400 uppercase">
                                    Volkswagen Golf GTI
                                </p>
                            </div>
                            <span className="rounded-full border border-green-200 bg-green-50 px-2.5 py-1 text-[10px] font-bold tracking-widest text-green-600 uppercase">
                                VERIFIED
                            </span>
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            {[
                                {
                                    label: 'MOT Status',
                                    value: 'Valid until Oct 2025',
                                    color: 'text-green-600',
                                },
                                {
                                    label: 'Mileage',
                                    value: '34,102 Miles',
                                    color: 'text-slate-800',
                                },
                                {
                                    label: 'Write-Off',
                                    value: 'None Recorded',
                                    color: 'text-green-600',
                                },
                                {
                                    label: 'Finance',
                                    value: 'Outstanding',
                                    color: 'text-red-600',
                                },
                            ].map((item) => (
                                <div
                                    key={item.label}
                                    className="border-t border-slate-100 pt-3"
                                >
                                    <p className="mb-1 text-[10px] tracking-widest text-slate-400 uppercase">
                                        {item.label}
                                    </p>
                                    <p
                                        className={`text-[13px] font-bold ${item.color}`}
                                    >
                                        {item.value}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </section>

            {/* ── CTA BOTTOM ── */}
            <section className="bg-white py-24 text-center">
                <div className="mx-auto max-w-[600px] px-6">
                    <p className="mb-4 text-[11px] font-bold tracking-[0.2em] text-slate-400 uppercase">
                        Ready to verify your next vehicle?
                    </p>
                    <h2 className="mb-3 text-[clamp(1.5rem,3vw,2rem)] font-black tracking-tight text-primary-container">
                        Don't leave it to chance.
                    </h2>
                    <p className="mb-10 text-sm text-slate-500">
                        Get the full picture today with UKcardoc's premium
                        vehicle history platform.
                    </p>
                    <div className="flex flex-col justify-center gap-3 sm:flex-row">
                        <Link
                            href="/register"
                            className="rounded-sm bg-primary-container px-8 py-3.5 text-[12px] font-bold tracking-widest text-white uppercase transition-colors hover:bg-primary"
                        >
                            GET STARTED FREE
                        </Link>
                        <Link
                            href="/sample"
                            className="rounded-sm border border-slate-800 px-8 py-3.5 text-[12px] font-bold tracking-widest text-slate-800 uppercase transition-colors hover:bg-slate-50"
                        >
                            VIEW SAMPLE REPORT
                        </Link>
                    </div>
                </div>
            </section>
        </>
    );
}

Home.layout = (page: React.ReactNode) => <BaseLayout>{page}</BaseLayout>;

export default Home;
