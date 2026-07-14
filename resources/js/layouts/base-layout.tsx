import SiteFooter from '@/components/marketing/site-footer';
import SiteNavbar from '@/components/marketing/site-navbar';
import { PropsWithChildren } from 'react';

export default function BaseLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col">
            <SiteNavbar />
            <main className="flex-1">{children}</main>
            <SiteFooter />
        </div>
    );
}
