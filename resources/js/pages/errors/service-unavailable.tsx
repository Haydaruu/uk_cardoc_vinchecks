import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    Activity,
    CarFront,
    CircleHelp,
    Home,
    RefreshCw,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';
type QuickLinkProps = {
    href: string;
    icon: ReactNode;
    title: string;
    description: string;
};

function QuickLink({ href, icon, title, description }: QuickLinkProps) {
    return (
        <Link
            href={href}
            className="group flex items-start gap-3 rounded-xl border border-slate-100 bg-white p-4 transition-all duration-200 hover:-translate-y-1 hover:border-blue-100 hover:shadow-lg hover:shadow-blue-950/5"
        >
            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#F0F3FF] text-[#00205B] transition-colors group-hover:bg-[#00205B] group-hover:text-white">
                {icon}
            </div>

            <div className="min-w-0 flex-1">
                <h3 className="text-sm font-bold text-[#00205B]">{title}</h3>
                <p className="mt-1 text-xs leading-relaxed text-slate-500">
                    {description}
                </p>
            </div>

            <ArrowRight
                size={16}
                className="mt-1 shrink-0 text-slate-300 transition-all group-hover:translate-x-1 group-hover:text-[#C8102E]"
            />
        </Link>
    );
}

function MaintenanceIllustration() {
    return (
        <div className="relative flex min-h-[320px] w-full items-center justify-center md:min-h-[440px]">
            <div className="absolute size-64 rounded-full bg-blue-100/70 blur-3xl" />
            <div className="absolute right-8 bottom-10 size-36 rounded-full bg-red-100/60 blur-3xl" />

            <span className="absolute select-none text-[190px] font-black tracking-tighter text-[#00205B]/[0.035] md:text-[260px]">
                503
            </span>

            <div className="relative z-10 flex size-64 items-center justify-center rounded-[2rem] border border-white bg-white/80 shadow-[0_24px_80px_rgba(0,32,91,0.10)] backdrop-blur-sm md:size-80">
                <div className="absolute inset-5 rounded-[1.5rem] border border-dashed border-blue-100" />

                <div className="relative flex size-48 items-center justify-center rounded-full border border-blue-100 md:size-56">
                    <div className="absolute inset-4 rounded-full border border-dashed border-slate-200" />
                    <div className="absolute inset-8 rounded-full border border-blue-100" />

                    <div className="absolute top-1/2 left-1/2 h-[1px] w-[80%] -translate-x-1/2 -translate-y-1/2 bg-blue-100" />
                    <div className="absolute top-1/2 left-1/2 h-[80%] w-[1px] -translate-x-1/2 -translate-y-1/2 bg-blue-100" />

                    <div className="relative z-10 flex size-20 items-center justify-center rounded-2xl bg-[#00205B] text-white shadow-lg shadow-blue-950/20 md:size-24">
                        <Activity size={40} strokeWidth={1.6} />
                    </div>

                    <span className="absolute top-5 left-1/2 size-2.5 -translate-x-1/2 rounded-full bg-[#C8102E] ring-4 ring-red-100" />
                    <span className="absolute right-7 bottom-9 size-2 rounded-full bg-slate-400" />
                    <span className="absolute top-9 left-7 size-2 rounded-full bg-slate-400" />
                </div>

                <div className="absolute -right-3 top-10 flex size-12 items-center justify-center rounded-2xl bg-[#C8102E] text-white shadow-lg">
                    <Wrench size={22} />
                </div>

                <div className="absolute -bottom-3 -left-3 flex items-center gap-2 rounded-xl border border-slate-100 bg-white px-4 py-3 shadow-lg">
                    <ShieldCheck className="text-emerald-600" size={19} />
                    <span className="text-xs font-semibold text-[#00205B]">
                        Your Data Is Protected
                    </span>
                </div>
            </div>

            <div className="absolute top-8 left-4 hidden items-center gap-2 rounded-full border border-blue-100 bg-white/90 px-3 py-2 text-xs font-medium text-[#00205B] shadow-sm sm:flex">
                <Activity size={14} />
                Temporary Service Interruption
            </div>

            <div className="absolute right-4 bottom-8 hidden items-center gap-2 rounded-full border border-red-100 bg-white/90 px-3 py-2 text-xs font-medium text-[#C8102E] shadow-sm sm:flex">
                <span className="size-2 rounded-full bg-[#C8102E]" />
                HTTP 503
            </div>
        </div>
    );
}

export default function ServiceUnavailable() {
    return (
        <>
            <Head title="503 - Service Unavailable" />

            <main className="relative min-h-screen overflow-hidden bg-[#F8F9FF] px-5 py-8 text-[#151C27] md:px-10">
                <div className="pointer-events-none absolute -top-40 -right-40 size-96 rounded-full bg-blue-100/40 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-40 -left-40 size-96 rounded-full bg-red-100/30 blur-3xl" />

                <div className="relative mx-auto w-full max-w-6xl">
                    <header className="flex items-center justify-between border-b border-slate-200/70 pb-5">
                        <Link href="/" className="flex items-center gap-2.5">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-[#00205B] text-white shadow-md">
                                <CarFront size={22} />
                            </div>
                            <span className="text-lg font-black tracking-tight text-[#00205B]">
                                UKCar<span className="text-[#C8102E]">Doc</span>
                            </span>
                        </Link>

                        <span className="rounded-full border border-blue-100 bg-white px-3 py-1.5 text-[10px] font-bold tracking-[0.16em] text-[#00205B] uppercase">
                            Service Notice
                        </span>
                    </header>

                    <section className="grid items-center gap-8 py-12 md:grid-cols-2 md:gap-12 md:py-16">
                        <div className="order-2 md:order-1">
                            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-red-100 bg-red-50 px-3 py-2 text-xs font-bold tracking-wider text-[#C8102E] uppercase">
                                <span className="size-2 animate-pulse rounded-full bg-[#C8102E]" />
                                Service Interruption · 503
                            </div>

                            <div className="mb-3 text-[104px] leading-none font-black tracking-[-0.08em] text-[#00205B] md:text-[128px]">
                                503
                            </div>

                            <h1 className="max-w-lg text-3xl leading-tight font-bold tracking-tight text-[#00205B] md:text-4xl">
                                We&apos;ll be back on the road.
                            </h1>

                            <p className="mt-5 max-w-lg text-base leading-relaxed text-slate-500 md:text-lg">
                                UKCarDoc is temporarily unable to process your
                                request. This may be due to maintenance or a
                                temporary service interruption. Please try
                                again shortly.
                            </p>

                            <div className="mt-6 rounded-xl border border-slate-100 bg-white p-5 shadow-sm">
                                <div className="flex items-center gap-3">
                                    <div className="flex size-10 items-center justify-center rounded-lg bg-[#F0F3FF] text-[#00205B]">
                                        <Wrench size={20} />
                                    </div>
                                    <div>
                                        <h2 className="text-sm font-bold text-[#00205B]">
                                            Temporary Service Unavailability
                                        </h2>
                                        <p className="mt-1 text-xs text-slate-400">
                                            HTTP Error 503
                                        </p>
                                    </div>
                                </div>

                                <div className="my-4 h-px bg-slate-100" />

                                <p className="text-sm leading-relaxed text-slate-600">
                                    We apologize for the inconvenience. Your
                                    account information and saved reports remain
                                    protected.
                                </p>
                            </div>

                            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                                <button
                                    type="button"
                                    onClick={() => window.location.reload()}
                                    className="inline-flex min-h-12 items-center justify-center gap-2 rounded-lg bg-[#C8102E] px-6 py-3 text-sm font-bold text-white shadow-md shadow-red-900/10 transition-all hover:-translate-y-0.5 hover:bg-[#A80D26]"
                                >
                                    <RefreshCw size={17} />
                                    Try Again
                                </button>

                                <Link
                                    href="/"
                                    className="inline-flex min-h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-6 py-3 text-sm font-bold text-[#00205B] transition-colors hover:border-blue-200 hover:bg-blue-50"
                                >
                                    <Home size={17} />
                                    Return Home
                                </Link>
                            </div>
                        </div>

                        <div className="order-1 md:order-2">
                            <MaintenanceIllustration />
                        </div>
                    </section>

                    <section className="border-t border-slate-200/70 pt-8">
                        <div className="mb-5 flex items-center justify-between gap-4">
                            <h2 className="text-sm font-bold tracking-wide text-[#00205B]">
                                While you&apos;re here
                            </h2>
                            <span className="text-xs text-slate-400">
                                Quick navigation
                            </span>
                        </div>

                        <div className="grid gap-3 md:grid-cols-3">
                            <QuickLink
                                href="/"
                                icon={<Home size={19} />}
                                title="Homepage"
                                description="Return to the UKCarDoc homepage."
                            />
                            <QuickLink
                                href="/pricing"
                                icon={<CarFront size={19} />}
                                title="Vehicle Reports"
                                description="Explore our vehicle history report options."
                            />
                            <QuickLink
                                href="/support"
                                icon={<CircleHelp size={19} />}
                                title="Help Centre"
                                description="Contact support if you need assistance."
                            />
                        </div>
                    </section>

                    <footer className="mt-10 flex flex-col items-center justify-between gap-3 border-t border-slate-200/70 pt-6 text-xs text-slate-400 sm:flex-row">
                        <span>
                            © {new Date().getFullYear()} UKCarDoc. All rights
                            reserved.
                        </span>
                        <Link
                            href="/terms-and-conditions"
                            className="font-medium transition-colors hover:text-[#00205B]"
                        >
                            Terms & Conditions
                        </Link>
                    </footer>
                </div>
            </main>
        </>
    );
}
