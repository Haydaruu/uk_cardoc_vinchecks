import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CarFront,
    CircleHelp,
    Clock3,
    Home,
    LockKeyhole,
    RefreshCw,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import type { ReactNode } from 'react';

type ErrorPageProps = {
    auth: {
        user: {
            id: number | string;
            name: string;
            email: string;
            role: string;
        } | null;
    };
};

function SessionIllustration() {
    return (
        <div className="relative flex min-h-[320px] w-full items-center justify-center md:min-h-[440px]">
            <div className="absolute size-64 rounded-full bg-blue-100/70 blur-3xl" />
            <div className="absolute right-8 bottom-10 size-36 rounded-full bg-red-100/60 blur-3xl" />

            <span className="absolute select-none text-[190px] font-black tracking-tighter text-[#00205B]/[0.035] md:text-[260px]">
                419
            </span>

            <div className="relative z-10 flex size-64 items-center justify-center rounded-[2rem] border border-white bg-white/80 shadow-[0_24px_80px_rgba(0,32,91,0.10)] backdrop-blur-sm md:size-80">
                <div className="absolute inset-5 rounded-[1.5rem] border border-dashed border-blue-100" />

                <div className="relative flex size-48 items-center justify-center rounded-full border-[8px] border-[#E7EEFE] md:size-56">
                    <div className="absolute inset-0 rounded-full border-[8px] border-transparent border-t-[#C8102E] border-r-[#00205B] rotate-45" />

                    <div className="flex size-36 flex-col items-center justify-center rounded-2xl bg-white shadow-[0_12px_32px_rgba(0,13,47,0.10)] md:size-40">
                        <div className="mb-3 flex size-14 items-center justify-center rounded-xl bg-[#F0F3FF] text-[#C8102E]">
                            <Clock3 size={30} />
                        </div>

                        <span className="font-mono text-xl font-black tracking-widest text-[#C8102E]">
                            00:00
                        </span>
                        <span className="mt-1 text-[10px] font-bold tracking-widest text-slate-400 uppercase">
                            Session Ended
                        </span>
                    </div>
                </div>

                <div className="absolute -right-3 top-10 flex size-12 items-center justify-center rounded-2xl bg-[#C8102E] text-white shadow-lg">
                    <LockKeyhole size={22} />
                </div>

                <div className="absolute -bottom-3 -left-3 flex items-center gap-2 rounded-xl border border-slate-100 bg-white px-4 py-3 shadow-lg">
                    <ShieldCheck className="text-emerald-600" size={19} />
                    <span className="text-xs font-semibold text-[#00205B]">
                        Your Data Is Safe
                    </span>
                </div>
            </div>

            <div className="absolute top-8 left-4 hidden items-center gap-2 rounded-full border border-blue-100 bg-white/90 px-3 py-2 text-xs font-medium text-[#00205B] shadow-sm sm:flex">
                <RefreshCw size={14} />
                Session Refresh Required
            </div>

            <div className="absolute right-4 bottom-8 hidden items-center gap-2 rounded-full border border-red-100 bg-white/90 px-3 py-2 text-xs font-medium text-[#C8102E] shadow-sm sm:flex">
                <span className="size-2 rounded-full bg-[#C8102E]" />
                Security Timeout
            </div>
        </div>
    );
}

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

export default function SessionExpired() {
    const { auth } = usePage<ErrorPageProps>().props;
    const isAuthenticated = Boolean(auth?.user);

    return (
        <>
            <Head title="419 - Session Expired" />

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
                            Error Page
                        </span>
                    </header>

                    <section className="grid items-center gap-8 py-12 md:grid-cols-2 md:gap-12 md:py-16">
                        <div className="order-2 md:order-1">
                            <div className="mb-6 inline-flex items-center gap-2 rounded-full border border-red-100 bg-red-50 px-3 py-2 text-xs font-bold tracking-wider text-[#C8102E] uppercase">
                                <span className="size-2 animate-pulse rounded-full bg-[#C8102E]" />
                                Security Timeout · 419
                            </div>

                            <div className="mb-3 text-[104px] leading-none font-black tracking-[-0.08em] text-[#00205B] md:text-[128px]">
                                419
                            </div>

                            <h1 className="max-w-lg text-3xl leading-tight font-bold tracking-tight text-[#00205B] md:text-4xl">
                                Your session has expired.
                            </h1>

                            <p className="mt-5 max-w-lg text-base leading-relaxed text-slate-500 md:text-lg">
                                Your session has expired for security reasons.
                                Please sign in again to continue using UKCarDoc.
                            </p>

                            <div className="mt-6 flex items-start gap-3 rounded-xl border-l-4 border-[#C8102E] bg-white p-4 shadow-sm">
                                <ShieldCheck className="mt-0.5 shrink-0 text-[#C8102E]" size={21} />
                                <p className="text-sm leading-relaxed text-slate-600">
                                    Your saved report progress and account
                                    credits remain secure. If you were
                                    submitting a payment or report request,
                                    check its status before trying again.
                                </p>
                            </div>

                            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                                <Link
                                    href={isAuthenticated ? '/dashboard' : '/auth-page'}
                                    className="inline-flex min-h-12 items-center justify-center gap-2 rounded-lg bg-[#C8102E] px-6 py-3 text-sm font-bold text-white shadow-md shadow-red-900/10 transition-all hover:-translate-y-0.5 hover:bg-[#A80D26]"
                                >
                                    <RefreshCw size={17} />
                                    Sign In Again
                                </Link>

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
                            <SessionIllustration />
                        </div>
                    </section>

                    <section className="border-t border-slate-200/70 pt-8">
                        <div className="mb-5 flex items-center justify-between gap-4">
                            <h2 className="text-sm font-bold tracking-wide text-[#00205B]">
                                Continue with UKCarDoc
                            </h2>
                            <span className="text-xs text-slate-400">
                                Quick navigation
                            </span>
                        </div>

                        <div className="grid gap-3 md:grid-cols-3">
                            <QuickLink
                                href="/pricing"
                                icon={<CarFront size={19} />}
                                title="Check Vehicle History"
                                description="Explore vehicle history report options."
                            />
                            <QuickLink
                                href={isAuthenticated ? '/dashboard' : '/auth-page'}
                                icon={<UserRound size={19} />}
                                title="My Account"
                                description="Sign in to access your account and saved reports."
                            />
                            <QuickLink
                                href="/support"
                                icon={<CircleHelp size={19} />}
                                title="Help Centre"
                                description="Find assistance and answers to common questions."
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
