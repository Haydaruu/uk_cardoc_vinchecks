import { Link, usePage } from '@inertiajs/react';
import { Menu, X, Star, Gem, Crown } from 'lucide-react';
import { useState } from 'react';
import ProfileDropdown from '@/components/profile-dropdown-menu';

const GuestNavItems = [
    { label: 'About Us', href: '/about-us' },
    { label: 'Pricing', href: '/pricing' },
    { label: 'Support', href: '/support' },
] as const;

const UserNavItems = [
    { label: 'About Us', href: '/about-us' },
    { label: 'Pricing', href: '/pricing' },
    { label: 'Support', href: '/support' },
    { label: 'My Report', href: '/my-report' },
] as const;

type SharedProps = {
    auth: {
        user: {
            name: string;
            credits: number;
            is_premium: boolean;
            subscription_plan: string | null;
        } | null;
    };
};

function isActive(currentUrl: string, href: string): boolean {
    if (href === '/') {
        return currentUrl === '/' || currentUrl === '';
    }

    return currentUrl.startsWith(href);
}

export default function SiteNavbar() {
    const { url, props } = usePage<SharedProps>();
    const { auth } = props;

    const navItems = auth.user ? UserNavItems : GuestNavItems;
    const [mobileOpen, setMobileOpen] = useState(false);

    const planName = auth.user?.subscription_plan?.toLowerCase() ?? '';

    const subscriptionTier = !auth.user?.is_premium
        ? null
        : planName.includes('max')
          ? { label: 'PREMIUM MAX', Icon: Crown }
          : planName.includes('plus')
            ? { label: 'PREMIUM PLUS', Icon: Gem }
            : { label: 'PREMIUM', Icon: Star };

    const TierIcon = subscriptionTier?.Icon;

    return (
        <header className="sticky top-0 z-50 border-b border-slate-100 bg-white/95 backdrop-blur-sm">
            <div className="mx-auto flex h-16 max-w-[1200px] items-center justify-between px-6 md:px-10">
                {/* Brand */}
                <Link
                    href={auth.user ? '/dashboard' : '/'}
                    className="font-sans text-lg font-black tracking-tight text-primary-container"
                >
                    UKCARDOC
                </Link>

                {/* Desktop Navigation */}
                <nav className="hidden items-center gap-8 md:flex">
                    {navItems.map((item) => {
                        const active = isActive(url, item.href);

                        return (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`relative text-sm font-medium transition-colors ${
                                    active
                                        ? 'text-primary-container'
                                        : 'text-slate-600 hover:text-primary-container'
                                }`}
                            >
                                {item.label}

                                {active && (
                                    <span className="absolute -bottom-[21px] left-0 h-0.5 w-full bg-secondary" />
                                )}
                            </Link>
                        );
                    })}
                </nav>

                {/* Desktop Account Area */}
                <div className="hidden items-center gap-5 md:flex">
                    {!auth.user ? (
                        <>
                            <Link
                                href="/login"
                                className="text-sm font-medium text-slate-600 transition-colors hover:text-primary-container"
                            >
                                Login
                            </Link>

                            <Link
                                href="/register"
                                className="rounded-sm bg-secondary px-5 py-2.5 text-[11px] font-bold tracking-widest text-white uppercase transition-colors hover:bg-secondary-container"
                            >
                                Create Account
                            </Link>
                        </>
                    ) : (
                        <>
                            <div className="flex items-center gap-2">
                                {/* Subscription Badge */}
                                {subscriptionTier && TierIcon ? (
                                    <span className="inline-flex items-center gap-1.5 rounded-sm bg-secondary px-3 py-2 text-[10px] font-bold tracking-widest text-white uppercase">
                                        <TierIcon className="size-3.5" />
                                        {subscriptionTier.label}
                                    </span>
                                ) : (
                                    <span className="rounded-sm bg-slate-100 px-3 py-2 text-[10px] font-bold tracking-widest text-primary-container uppercase">
                                        Standard Access
                                    </span>
                                )}

                                {/* Credit Badge */}
                                <span className="rounded-sm border border-primary-container/20 bg-primary-container/5 px-3 py-2 text-[10px] font-bold tracking-wide text-primary-container uppercase">
                                    {auth.user.credits}{' '}
                                    {auth.user.credits === 1
                                        ? 'CREDIT'
                                        : 'CREDITS'}
                                </span>
                            </div>

                            {!auth.user.is_premium && (
                                <Link
                                    href="/pricing"
                                    className="rounded-sm bg-secondary px-5 py-2.5 text-[11px] font-bold tracking-widest text-white uppercase transition-colors hover:bg-secondary-container"
                                >
                                    Top Up
                                </Link>
                            )}

                            <ProfileDropdown name={auth.user.name} />
                        </>
                    )}
                </div>

                {/* Mobile Menu Toggle */}
                <button
                    type="button"
                    className="inline-flex items-center justify-center rounded-sm p-2 text-primary-container md:hidden"
                    onClick={() => setMobileOpen((open) => !open)}
                    aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                    aria-expanded={mobileOpen}
                >
                    {mobileOpen ? <X size={22} /> : <Menu size={22} />}
                </button>
            </div>

            {/* Mobile Menu */}
            {mobileOpen && (
                <div className="border-t border-slate-100 bg-white px-6 py-4 md:hidden">
                    <nav className="flex flex-col gap-1">
                        {navItems.map((item) => {
                            const active = isActive(url, item.href);

                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    onClick={() => setMobileOpen(false)}
                                    className={`rounded-sm px-3 py-2.5 text-sm font-medium transition-colors ${
                                        active
                                            ? 'bg-slate-50 text-primary-container'
                                            : 'text-slate-600 hover:bg-slate-50'
                                    }`}
                                >
                                    {item.label}
                                </Link>
                            );
                        })}
                    </nav>

                    <div className="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4">
                        {!auth.user ? (
                            <>
                                <Link
                                    href="/login"
                                    onClick={() => setMobileOpen(false)}
                                    className="px-3 py-2 text-sm font-medium text-slate-600"
                                >
                                    Login
                                </Link>

                                <Link
                                    href="/register"
                                    onClick={() => setMobileOpen(false)}
                                    className="rounded-sm bg-secondary px-5 py-3 text-center text-[11px] font-bold tracking-widest text-white uppercase transition-colors hover:bg-secondary-container"
                                >
                                    Create Account
                                </Link>
                            </>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center gap-2 px-1">
                                    {subscriptionTier && TierIcon ? (
                                        <span className="inline-flex items-center gap-1.5 rounded-sm bg-secondary px-3 py-2 text-[10px] font-bold tracking-widest text-white uppercase">
                                            <TierIcon className="size-3.5" />
                                            {subscriptionTier.label}
                                        </span>
                                    ) : (
                                        <span className="rounded-sm bg-slate-100 px-3 py-2 text-[10px] font-bold tracking-widest text-primary-container uppercase">
                                            Standard Access
                                        </span>
                                    )}

                                    <span className="rounded-sm border border-primary-container/20 bg-primary-container/5 px-3 py-2 text-[10px] font-bold tracking-wide text-primary-container uppercase">
                                        {auth.user.credits}{' '}
                                        {auth.user.credits === 1
                                            ? 'CREDIT'
                                            : 'CREDITS'}
                                    </span>
                                </div>

                                {!auth.user.is_premium && (
                                    <Link
                                        href="/pricing"
                                        onClick={() => setMobileOpen(false)}
                                        className="rounded-sm bg-secondary px-5 py-3 text-center text-[11px] font-bold tracking-widest text-white uppercase transition-colors hover:bg-secondary-container"
                                    >
                                        Top Up
                                    </Link>
                                )}
                            </>
                        )}
                    </div>
                </div>
            )}
        </header>
    );
}
