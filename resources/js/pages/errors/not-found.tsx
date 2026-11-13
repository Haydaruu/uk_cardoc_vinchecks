import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CircleHelp,
    Home,
    LayoutDashboard,
    Search,
    ShieldCheck,
} from 'lucide-react';

type ErrorPageProps = {
    auth?: {
        user?: {
            id: number;
            name: string;
        } | null;
    };
};

function RadarIllustration() {
    return (
        <div className="relative mx-auto aspect-square w-full max-w-[390px]">
            <div className="absolute inset-0 rounded-full bg-[#EAF0FF]/70 blur-3xl" />

            <div className="absolute inset-[7%] rounded-full border border-[#00205B]/[0.06]" />
            <div className="absolute inset-[17%] rounded-full border border-[#00205B]/[0.08]" />
            <div className="absolute inset-[27%] rounded-full border border-[#00205B]/[0.10]" />

            <div className="absolute inset-[37%] rounded-full border border-[#00205B]/[0.12]" />

            <svg
                viewBox="0 0 400 400"
                className="absolute inset-0 h-full w-full"
                fill="none"
                aria-hidden="true"
            >
                <defs>
                    <linearGradient id="radarSweep" x1="200" y1="200" x2="200" y2="35">
                        <stop offset="0%" stopColor="#C8102E" stopOpacity="0.22" />
                        <stop offset="100%" stopColor="#C8102E" stopOpacity="0" />
                    </linearGradient>
                    <clipPath id="radarClip">
                        <circle cx="200" cy="200" r="165" />
                    </clipPath>
                </defs>

                <g clipPath="url(#radarClip)">
                    <path
                        d="M200 200 L200 35 A165 165 0 0 1 365 200 Z"
                        fill="url(#radarSweep)"
                    />
                    <path
                        d="M200 200 L365 200"
                        stroke="#00205B"
                        strokeOpacity="0.13"
                        strokeDasharray="4 6"
                    />
                    <path
                        d="M200 35 L200 365"
                        stroke="#00205B"
                        strokeOpacity="0.13"
                        strokeDasharray="4 6"
                    />
                    <path
                        d="M35 200 H365"
                        stroke="#00205B"
                        strokeOpacity="0.13"
                        strokeDasharray="4 6"
                    />
                    <circle cx="200" cy="200" r="165" stroke="#00205B" strokeOpacity="0.12" />
                    <circle cx="200" cy="200" r="110" stroke="#00205B" strokeOpacity="0.12" />
                    <circle cx="200" cy="200" r="55" stroke="#00205B" strokeOpacity="0.12" />
                </g>

                <circle cx="200" cy="200" r="5" fill="#00205B" />
                <circle cx="200" cy="200" r="12" stroke="#C8102E" strokeOpacity="0.35" />

                <circle cx="282" cy="116" r="6" fill="#C8102E" />
                <circle cx="282" cy="116" r="13" stroke="#C8102E" strokeOpacity="0.25" />

                <circle cx="113" cy="257" r="4" fill="#00205B" fillOpacity="0.5" />

                <path
                    d="M200 200 L282 116"
                    stroke="#C8102E"
                    strokeWidth="1.5"
                    strokeOpacity="0.55"
                    strokeDasharray="4 5"
                />

                <path
                    d="M200 200 L200 35"
                    stroke="#C8102E"
                    strokeWidth="2"
                    strokeOpacity="0.65"
                />
            </svg>

            <div className="absolute top-[8%] right-[3%] rounded-xl border border-white bg-white p-3 shadow-lg shadow-[#00205B]/10">
                <Search className="size-5 text-[#C8102E]" />
            </div>

            <div className="absolute bottom-[13%] left-[3%] rounded-xl border border-white bg-white p-3 shadow-lg shadow-[#00205B]/10">
                <ShieldCheck className="size-5 text-[#00205B]" />
            </div>

            <div className="absolute right-[12%] bottom-[9%] h-2 w-2 rounded-full bg-[#C8102E]" />
            <div className="absolute top-[18%] left-[12%] h-2 w-2 rounded-full bg-[#00205B]/30" />
        </div>
    );
}

export default function NotFound() {
    const { auth } = usePage<ErrorPageProps>().props;
    const isAuthenticated = Boolean(auth?.user);

    return (
        <>
            <Head title="404 — Page Not Found" />

            <main className="relative flex min-h-screen flex-col overflow-hidden bg-[#F8F8FF] text-[#00205B]">
                <div className="pointer-events-none absolute inset-0 overflow-hidden">
                    <div className="absolute -top-40 -right-32 h-[420px] w-[420px] rounded-full bg-[#EAF0FF]/70 blur-3xl" />
                    <div className="absolute -bottom-48 -left-32 h-[420px] w-[420px] rounded-full bg-[#FCECEF]/50 blur-3xl" />
                </div>

                <div className="relative z-10 mx-auto flex w-full max-w-6xl flex-1 flex-col px-5 py-8 sm:px-8 lg:px-12">
                    <header className="flex items-center justify-between">
                        <Link href="/" className="flex items-center gap-2.5">
                            <div className="flex size-10 items-center justify-center rounded-xl bg-[#00205B] text-sm font-black text-white">
                                UK
                            </div>
                            <span className="text-lg font-black tracking-tight">
                                UKCar<span className="text-[#C8102E]">Doc</span>
                            </span>
                        </Link>

                        <span className="rounded-full border border-[#00205B]/10 bg-white/80 px-3 py-1.5 text-[10px] font-bold tracking-[0.16em] text-[#00205B]/55 uppercase">
                            Error page
                        </span>
                    </header>

                    <section className="grid flex-1 items-center gap-8 py-12 lg:grid-cols-2 lg:gap-14">
                        <div className="order-2 text-center lg:order-1 lg:text-left">
                            <div className="inline-flex items-center gap-2 rounded-full border border-[#C8102E]/15 bg-[#C8102E]/[0.06] px-3 py-1.5 text-[10px] font-bold tracking-[0.15em] text-[#C8102E] uppercase">
                                <span className="size-1.5 rounded-full bg-[#C8102E]" />
                                Page unavailable
                            </div>

                            <div className="relative mt-5 inline-block">
                                <span className="text-[104px] leading-none font-black tracking-[-0.09em] text-[#00205B]/[0.07] sm:text-[140px]">
                                    404
                                </span>
                            </div>

                            <h1 className="-mt-2 text-3xl leading-tight font-black tracking-tight sm:text-4xl lg:text-5xl">
                                Lost on the road?
                                <br />
                                <span className="text-[#C8102E]">We can help.</span>
                            </h1>

                            <p className="mx-auto mt-5 max-w-md text-sm leading-7 text-slate-500 lg:mx-0">
                                The page you are looking for may have been moved,
                                removed, or is temporarily unavailable. Let's get
                                you back on track.
                            </p>

                            <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row lg:justify-start">
                                <Link
                                    href="/"
                                    className="inline-flex h-12 items-center justify-center gap-2 rounded-lg bg-[#C8102E] px-6 text-sm font-bold text-white shadow-lg shadow-[#C8102E]/15 transition hover:bg-[#A80D27] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#C8102E]"
                                >
                                    <Home className="size-4" />
                                    Back to Homepage
                                </Link>

                                {isAuthenticated ? (
                                    <Link
                                        href="/dashboard"
                                        className="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-[#00205B]/10 bg-white px-6 text-sm font-bold text-[#00205B] transition hover:bg-[#EAF0FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#00205B]"
                                    >
                                        <LayoutDashboard className="size-4" />
                                        Go to Dashboard
                                    </Link>
                                ) : (
                                    <Link
                                        href="/auth-page"
                                        className="inline-flex h-12 items-center justify-center gap-2 rounded-lg border border-[#00205B]/10 bg-white px-6 text-sm font-bold text-[#00205B] transition hover:bg-[#EAF0FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#00205B]"
                                    >
                                        <ArrowRight className="size-4" />
                                        Sign In
                                    </Link>
                                )}
                            </div>
                        </div>

                        <div className="order-1 lg:order-2">
                            <RadarIllustration />
                        </div>
                    </section>

                    <section className="grid gap-3 border-t border-[#00205B]/[0.08] pt-6 pb-4 sm:grid-cols-3">
                        <Link
                            href="/"
                            className="group flex items-center gap-3 rounded-xl border border-[#00205B]/[0.07] bg-white/80 p-4 transition hover:-translate-y-0.5 hover:border-[#00205B]/20 hover:shadow-lg hover:shadow-[#00205B]/[0.04]"
                        >
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#EAF0FF]">
                                <Home className="size-5 text-[#00205B]" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-bold">Homepage</p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Explore UKCarDoc
                                </p>
                            </div>
                            <ArrowRight className="size-4 text-slate-400 transition group-hover:translate-x-1" />
                        </Link>

                        <Link
                            href="/pricing"
                            className="group flex items-center gap-3 rounded-xl border border-[#00205B]/[0.07] bg-white/80 p-4 transition hover:-translate-y-0.5 hover:border-[#00205B]/20 hover:shadow-lg hover:shadow-[#00205B]/[0.04]"
                        >
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#FCECEF]">
                                <Search className="size-5 text-[#C8102E]" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-bold">View Pricing</p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Explore report options
                                </p>
                            </div>
                            <ArrowRight className="size-4 text-slate-400 transition group-hover:translate-x-1" />
                        </Link>

                        <Link
                            href="/support"
                            className="group flex items-center gap-3 rounded-xl border border-[#00205B]/[0.07] bg-white/80 p-4 transition hover:-translate-y-0.5 hover:border-[#00205B]/20 hover:shadow-lg hover:shadow-[#00205B]/[0.04]"
                        >
                            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#EAF0FF]">
                                <CircleHelp className="size-5 text-[#00205B]" />
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-bold">Need Help?</p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Contact our support team
                                </p>
                            </div>
                            <ArrowRight className="size-4 text-slate-400 transition group-hover:translate-x-1" />
                        </Link>
                    </section>

                    <footer className="flex flex-col items-center justify-between gap-3 border-t border-[#00205B]/[0.08] pt-5 text-center text-xs text-slate-400 sm:flex-row sm:text-left">
                        <p>
                            © {new Date().getFullYear()} UKCarDoc. All rights
                            reserved.
                        </p>
                        <Link
                            href="/terms-and-conditions"
                            className="transition hover:text-[#00205B]"
                        >
                            Terms & Conditions
                        </Link>
                    </footer>
                </div>
            </main>
        </>
    );
}
