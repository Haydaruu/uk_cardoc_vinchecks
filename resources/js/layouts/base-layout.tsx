import type { PropsWithChildren } from 'react';
import SiteFooter from '@/components/site-footer';
import SiteNavbar from '@/components/site-navbar';

export default function BaseLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col">
            <SiteNavbar />
            <main className="flex-1">{children}</main>
            <SiteFooter />
        </div>
    );
}
