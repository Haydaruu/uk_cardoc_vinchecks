import { usePage } from '@inertiajs/react';
import type { PropsWithChildren} from 'react';
import React, { useState } from 'react';

import AdminHeader from '@/components/admin/admin-header';
import AdminSidebar from '@/components/admin/admin-sidebar';

interface AdminLayoutProps extends PropsWithChildren {
    title: string;
    subtitle?: string;
}

export default function AdminLayout({
    title,
    subtitle,
    children,
}: AdminLayoutProps) {
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const flash = (usePage().props as any).flash;

    return (
        <div className="flex min-h-screen flex-col bg-surface font-body text-on-surface">
            {/* Sidebar */}
            <AdminSidebar
                isOpen={sidebarOpen}
                onClose={() => setSidebarOpen(false)}
            />

            {/* Main Area */}
            <div className="flex flex-1 flex-col lg:pl-64">
                {/* Header */}
                <AdminHeader
                    title={title}
                    subtitle={subtitle}
                    onToggleSidebar={() => setSidebarOpen(true)}
                />

                {/* Flash Messages */}
                {flash?.success && (
                    <div className="mx-4 mt-4 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-800 lg:mx-8">
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash?.error && (
                    <div className="mx-4 mt-4 flex items-center justify-between rounded-lg border border-error/30 bg-error-container/40 p-3 text-xs text-error lg:mx-8">
                        <span>{flash.error}</span>
                    </div>
                )}

                {/* Page Content */}
                <main className="flex-1 p-4 lg:p-8">{children}</main>
            </div>
        </div>
    );
}
