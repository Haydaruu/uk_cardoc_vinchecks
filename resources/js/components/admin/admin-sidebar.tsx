import { Link, usePage } from '@inertiajs/react';
import React from 'react';

interface SidebarProps {
    isOpen: boolean;
    onClose: () => void;
}

export default function AdminSidebar({ isOpen, onClose }: SidebarProps) {
    const { url } = usePage();
    const auth = (usePage().props as any).auth;
    const user = auth?.user;
    const isSuperAdmin = user?.role === 'super_admin';

    const sharedNavItems = [
        { label: 'Overview', href: '/admin/dashboard', icon: '📊' },
        { label: 'Transactions', href: '/admin/transactions', icon: '💳' },
        { label: 'Reports', href: '/admin/reports', icon: '📄' },
        { label: 'Customers', href: '/admin/customers', icon: '👥' },
        { label: 'Subscriptions', href: '/admin/subscriptions', icon: '🔄' },
        { label: 'Analytics', href: '/admin/analytics', icon: '📈' },
    ];

    const superAdminNavItems = [
        {
            label: 'System Health',
            href: '/admin/system-health',
            icon: '🩺',
            badge: 'Active',
        },
        {
            label: 'System Configuration',
            href: '/admin/system-configuration',
            icon: '⚙️',
            badge: 'Active',
        },
        {
            label: 'Administration',
            href: '/admin/administration',
            icon: '🛡️',
            badge: 'Active',
        },
        {
            label: 'Provider Lab',
            href: '/admin/provider-lab',
            icon: '🧪',
            badge: 'Active',
        },
    ];

    const isCurrent = (href: string) => {
        if (href === '/admin/dashboard') {
            return url === '/admin/dashboard';
        }

        return url.startsWith(href);
    };

    return (
        <>
            {/* Mobile Backdrop */}
            {isOpen && (
                <div
                    onClick={onClose}
                    className="fixed inset-0 z-40 bg-black/50 lg:hidden"
                    aria-hidden="true"
                />
            )}

            {/* Sidebar Container */}
            <aside
                className={`fixed top-0 bottom-0 left-0 z-50 flex w-64 flex-col bg-[#000d2f] text-white transition-transform duration-200 ease-in-out lg:translate-x-0 ${
                    isOpen ? 'translate-x-0' : '-translate-x-full'
                }`}
            >
                {/* Brand / Logo */}
                <div className="flex h-16 items-center justify-between border-b border-white/10 px-6">
                    <Link
                        href="/admin/dashboard"
                        className="flex items-center gap-2.5"
                    >
                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-[#bb001a] font-sans text-base font-bold text-white">
                            VC
                        </span>
                        <div>
                            <span className="block font-sans text-sm font-extrabold tracking-wide text-white">
                                VinCheck Admin
                            </span>
                            <span className="block text-[10px] tracking-wider text-white/60 uppercase">
                                Operational Console
                            </span>
                        </div>
                    </Link>

                    <button
                        onClick={onClose}
                        className="rounded-md p-1 text-white/70 hover:text-white lg:hidden"
                        aria-label="Close sidebar"
                    >
                        ✕
                    </button>
                </div>

                {/* Role Status Tag */}
                <div className="flex items-center justify-between border-b border-white/10 bg-[#00205b]/60 px-6 py-3">
                    <span className="text-[11px] font-medium text-white/70">
                        Logged in role:
                    </span>
                    <span
                        className={`rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wider uppercase ${
                            isSuperAdmin
                                ? 'border border-amber-400/30 bg-amber-400/20 text-amber-300'
                                : 'border border-blue-400/30 bg-blue-400/20 text-blue-300'
                        }`}
                    >
                        {user?.role || 'Admin'}
                    </span>
                </div>

                {/* Nav Links */}
                <nav className="flex-1 space-y-6 overflow-y-auto px-4 py-4">
                    {/* Operational Menu */}
                    <div>
                        <span className="mb-2 block px-3 text-[10px] font-bold tracking-wider text-white/50 uppercase">
                            Operational
                        </span>
                        <ul className="space-y-1">
                            {sharedNavItems.map((item) => {
                                const active = isCurrent(item.href);

                                return (
                                    <li key={item.href}>
                                        <Link
                                            href={item.href}
                                            onClick={() => onClose()}
                                            className={`flex items-center gap-3 rounded-lg px-3 py-2 text-xs font-medium transition-colors ${
                                                active
                                                    ? 'border-l-4 border-[#bb001a] bg-[#00205b] font-semibold text-white'
                                                    : 'text-white/70 hover:bg-white/5 hover:text-white'
                                            }`}
                                        >
                                            <span className="text-base">
                                                {item.icon}
                                            </span>
                                            <span>{item.label}</span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    {/* Super Admin Special Menu */}
                    {isSuperAdmin && (
                        <div>
                            <span className="mb-2 block px-3 text-[10px] font-bold tracking-wider text-amber-400/70 uppercase">
                                Super Admin System
                            </span>
                            <ul className="space-y-1">
                                {superAdminNavItems.map((item) => {
                                    const active = isCurrent(item.href);

                                    return (
                                        <li key={item.href}>
                                            <Link
                                                href={item.href}
                                                onClick={() => onClose()}
                                                className={`flex items-center justify-between rounded-lg px-3 py-2 text-xs font-medium transition-colors ${
                                                    active
                                                        ? 'border-l-4 border-amber-400 bg-[#00205b] font-semibold text-white'
                                                        : 'text-white/70 hover:bg-white/5 hover:text-white'
                                                }`}
                                            >
                                                <div className="flex items-center gap-3">
                                                    <span className="text-base">
                                                        {item.icon}
                                                    </span>
                                                    <span>{item.label}</span>
                                                </div>
                                                {item.badge && (
                                                    <span className="rounded bg-white/10 px-1.5 py-0.5 font-mono text-[9px] text-white/80">
                                                        {item.badge}
                                                    </span>
                                                )}
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}
                </nav>

                {/* Footer User Info */}
                <div className="border-t border-white/10 bg-[#000d2f]/90 p-4">
                    <div className="flex items-center justify-between text-xs">
                        <div className="mr-2 truncate">
                            <span className="block truncate font-semibold text-white">
                                {user?.name || 'Administrator'}
                            </span>
                            <span className="block truncate text-[11px] text-white/50">
                                {user?.email || '-'}
                            </span>
                        </div>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="rounded p-1.5 text-white/70 transition-colors hover:bg-white/10 hover:text-white"
                            title="Sign out"
                        >
                            🚪
                        </Link>
                    </div>
                </div>
            </aside>
        </>
    );
}
