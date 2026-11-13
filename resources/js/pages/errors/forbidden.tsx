import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CarFront,
    CircleHelp,
    Gauge,
    Home,
    LifeBuoy,
    LockKeyhole,
    ShieldAlert,
    ShieldCheck,
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

function SecurityIllustration() {
    return (
        <div className="relative flex min-h-[320px] w-full items-center justify-center md:min-h-[440px]">
            <div className="absolute h-64 w-64 rounded-full bg-blue-100/70 blur-3xl" />
            <div className="absolute right-8 bottom-10 h-36 w-36 rounded-full bg-red-100/60 blur-3xl" />

            <div className="absolute inset-0 flex items-center justify-center">
                <span className="select-none text-[180px] font-black tracking-tighter text-[#00205B]/[0.035] md:text-[260px]">
                    403
                </span>
            </div>

            <div className="relative z-10 flex size-64 items-center justify-center rounded-[2rem] border border-white bg-white/80 shadow-[0_24px_80px_rgba(0,32,91,0.10)] backdrop-blur-sm md:size-80">
                <div className="absolute inset-5 rounded-[1.5rem] border border-dashed border-blue-100" />

                <div className="relative flex size-44 items-center justify-center rounded-full bg-[#F4F6FC] md:size-56">
                    <div className="absolute inset-3 rounded-full border border-blue-100" />
                    <div className="absolute inset-7 rounded-full border border-blue-100/80" />

                    <svg
                        viewBox="0 0 160 160"
                        className="relative z-10 size-32 md:size-40"
                        fill="none"
                        xmlns="http://www.w3.org/2000/svg"
                        aria-hidden="true"
                    >
                        <path
                            d="M80 13L126 33V76C126 109 80 140 80 140C80 140 34 109 34 76V33L80 13Z"
                            fill="#FFFFFF"
                            stroke="#00205B"
                            strokeWidth="4"
                            strokeLinejoin="round"
                        />
                        <path
                            d="M80 23L116 39V75C116 101 80 126 80 126C80 126 44 101 44 75V39L80 23Z"
                            stroke="#B9C9E8"
                            strokeWidth="2"
                        />
                        <path
                            d="M55 65H105M55 91H105"
                            stroke="#DCE5F5"
                            strokeWidth="2"
                            strokeDasharray="4 5"
                        />
                        <rect
                            x="61"
                            y="69"
                            width="38"
                            height="32"
                            rx="5"
                            fill="#00205B"
                        />
                        <path
                            d="M69 69V59C69 52.925 73.925 48 80 48C86.075 48 91 52.925 91 59V69"
                            stroke="#00205B"
                            strokeWidth="5"
                            strokeLinecap="round"
                        />
                        <circle cx="80" cy="82" r="4" fill="#FFFFFF" />
                        <path
                            d="M80 86V92"
                            stroke="#FFFFFF"
                            strokeWidth="3"
                            strokeLinecap="round"
                        />
                        <circle cx="80" cy="116" r="5" fill="#C8102E" />
                        <circle
                            cx="80"
                            cy="116"
                            r="10"
                            stroke="#C8102E"
                            strokeOpacity=".25"
                            strokeWidth="2"
                        />
                    </svg>
                </div>

                <div className="absolute -right-3 top-10 flex size-12 items-center justify-center rounded-2xl bg-[#C8102E] text-white shadow-lg shadow-red-900/15">
                    <ShieldAlert size={23} />
                </div>

                <div className="absolute -bottom-3 -left-3 flex items-center gap-2 rounded-xl border border-slate-100 bg-white px-4 py-3 shadow-lg">
                    <ShieldCheck className="text-emerald-600" size={19} />
                    <span className="text-xs font-semibold text-[#00205B]">
                        Vehicle Data Protected
                    </span>
                </div>
            </div>

            <div className="absolute top-8 left-4 hidden items-center gap-2 rounded-full border border-blue-100 bg-white/90 px-3 py-2 text-xs font-medium text-[#00205B] shadow-sm sm:flex">
                <LockKeyhole size={14} />
                Restricted Access
            </div>

            <div className="absolute right-4 bottom-8 hidden items-center gap-2 rounded-full border border-red-100 bg-white/90 px-3 py-2 text-xs font-medium text-[#C8102E] shadow-sm sm:flex">
                <span className="size-2 rounded-full bg-[#C8102E]" />
                Authorization Required
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
                <h3 className="text-sm font-bold text-[#00205B]">
                    {title}
                </h3>
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

export default function Forbidden() {
    const { auth } = usePage<ErrorPageProps>().props;
    const isAuthenticated = Boolean(auth?.user);

    const primaryHref = isAuthenticated ? '/dashboard' : '/auth-page';
    const primaryLabel = isAuthenticated
        ? 'Back to Dashboard'
        : 'Sign In to Continue';

    return (
        <>
            <Head title="403 - Access Restricted" />

            <main className="relative min-h-screen overflow-hidden bg-[#F8F9FF] px-5 py-8 text-[#151C27] md:px-10">
                <div className="pointer-events-none absolute -top-40 -right-40 size-96 rounded-full bg-blue-100/40 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-40 -left-40 size-96 rounded-full bg-red-100/30 blur-3xl" />

                <div className="relative mx-auto w-full max-w-6xl">
                    <header className="flex items-center justify-between border-b border-slate-200/70 pb-5">
                        <Link
                            href="/"
                            className="flex items-center gap-2.5"
                        >
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
                                Security Protocol · 403 Forbidden
                            </div>

                            <div className="relative mb-3">
                                <span className="select-none text-[104px] leading-none font-black tracking-[-0.08em] text-[#00205B] md:text-[128px]">
                                    403
                                </span>
                            </div>

                            <h1 className="max-w-lg text-3xl leading-tight font-bold tracking-tight text-[#00205B] md:text-4xl">
                                This road is restricted.
                            </h1>

                            <p className="mt-5 max-w-lg text-base leading-relaxed text-slate-500 md:text-lg">
                                You do not have permission to access this
                                resource or vehicle report. Sign in with an
                                authorized account or contact our support team
                                if you believe this is a mistake.
                            </p>

                            <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                                <Link
                                    href={primaryHref}
                                    className="inline-flex min-h-12 items-center justify-center gap-2 rounded-lg bg-[#C8102E] px-6 py-3 text-sm font-bold text-white shadow-md shadow-red-900/10 transition-all hover:-translate-y-0.5 hover:bg-[#A80D26]"
                                >
                                    <Gauge size={18} />
                                    {primaryLabel}
                                </Link>

                                <Link
                                    href="/support"
                                    className="inline-flex min-h-12 items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-6 py-3 text-sm font-bold text-[#00205B] transition-colors hover:border-blue-200 hover:bg-blue-50"
                                >
                                    <LifeBuoy size={18} />
                                    Contact Support
                                </Link>
                            </div>
                        </div>

                        <div className="order-1 md:order-2">
                            <SecurityIllustration />
                        </div>
                    </section>

                    <section className="border-t border-slate-200/70 pt-8">
                        <div className="mb-5 flex items-center justify-between gap-4">
                            <h2 className="text-sm font-bold tracking-wide text-[#00205B]">
                                Where would you like to go?
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
                                title="Explore Reports"
                                description="Discover our vehicle history report options."
                            />

                            <QuickLink
                                href="/support"
                                icon={<CircleHelp size={19} />}
                                title="Need Help?"
                                description="Get assistance from our support team."
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
