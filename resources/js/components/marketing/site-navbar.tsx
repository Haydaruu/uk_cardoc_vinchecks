import { Link, usePage } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';
import { login, register } from '@/routes';

const navItems = [
    { label: 'Vehicle Check', href: '/' },
    { label: 'Pricing', href: '/pricing' },
    { label: 'Support', href: '/support' },
    { label: 'My Reports', href: '/my-report' },
] as const;

function isActive(currentUrl: string, href: string): boolean {
    if (href === '/') {
        return currentUrl === '/' || currentUrl === '';
    }

    return currentUrl.startsWith(href);
}

export default function SiteNavbar() {
    const { url } = usePage();
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <header className="sticky top-0 z-50 border-b border-slate-100 bg-white/95 backdrop-blur-sm">
            <div className="mx-auto flex h-16 max-w-[1200px] items-center justify-between px-6 md:px-10">
                <Link
                    href="/"
                    className="font-sans text-lg font-black tracking-tight text-primary-container"
                >
                    UKCARDOC
                </Link>

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

                <div className="hidden items-center gap-5 md:flex">
                    <Link
                        href={login()}
                        className="text-sm font-medium text-slate-600 transition-colors hover:text-primary-container"
                    >
                        Login
                    </Link>
                    <Link
                        href={register()}
                        className="rounded-sm bg-secondary px-5 py-2.5 text-[11px] font-bold uppercase tracking-widest text-white transition-colors hover:bg-secondary-container"
                    >
                        Create Account
                    </Link>
                </div>

                <button
                    type="button"
                    className="inline-flex items-center justify-center rounded-sm p-2 text-primary-container md:hidden"
                    onClick={() => setMobileOpen((open) => !open)}
                    aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                >
                    {mobileOpen ? <X size={22} /> : <Menu size={22} />}
                </button>
            </div>

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
                        <Link
                            href={login()}
                            onClick={() => setMobileOpen(false)}
                            className="px-3 py-2 text-sm font-medium text-slate-600"
                        >
                            Login
                        </Link>
                        <Link
                            href={register()}
                            onClick={() => setMobileOpen(false)}
                            className="rounded-sm bg-secondary px-5 py-3 text-center text-[11px] font-bold uppercase tracking-widest text-white"
                        >
                            Create Account
                        </Link>
                    </div>
                </div>
            )}
        </header>
    );
}
