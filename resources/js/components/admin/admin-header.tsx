import { Link } from '@inertiajs/react';
import React from 'react';

interface HeaderProps {
    title: string;
    subtitle?: string;
    onToggleSidebar: () => void;
}

export default function AdminHeader({
    title,
    subtitle,
    onToggleSidebar,
}: HeaderProps) {
    return (
        <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-outline-variant bg-white px-4 shadow-xs lg:px-8">
            {/* Left Title / Hamburger */}
            <div className="flex items-center gap-3">
                <button
                    onClick={onToggleSidebar}
                    type="button"
                    className="rounded-lg p-2 text-on-surface hover:bg-surface-container lg:hidden"
                    aria-label="Open sidebar"
                >
                    ☰
                </button>
                <div>
                    <h1 className="font-sans text-base leading-none font-bold text-on-surface lg:text-lg">
                        {title}
                    </h1>
                    {subtitle && (
                        <p className="mt-1 text-xs leading-none text-on-surface-variant">
                            {subtitle}
                        </p>
                    )}
                </div>
            </div>

            {/* Right Status & Quick Links */}
            <div className="flex items-center gap-4">
                <Link
                    href="/"
                    className="flex items-center gap-1 rounded-md border border-outline-variant px-2.5 py-1.5 text-xs font-medium text-on-surface-variant transition-colors hover:bg-surface-container hover:text-primary"
                    target="_blank"
                >
                    <span>View Site</span>
                    <span className="text-[10px]">↗</span>
                </Link>

                <div className="hidden items-center gap-2 border-l border-outline-variant pl-4 sm:flex">
                    <span className="h-2 w-2 animate-pulse rounded-full bg-emerald-500" />
                    <span className="text-xs font-semibold text-on-surface-variant">
                        Console Active
                    </span>
                </div>
            </div>
        </header>
    );
}
