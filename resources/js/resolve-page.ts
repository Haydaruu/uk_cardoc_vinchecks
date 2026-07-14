import { createElement } from 'react';
import type { ResolvedComponent } from '@inertiajs/react';
import AuthLayout from '@/layouts/auth-layout';
import BaseLayout from './layouts/base-layout';

const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });

export function resolvePage(name: string) {
    const page = pages[`./pages/${name}.tsx`] as {
        default: ResolvedComponent;
    };

    if (!page) {
        throw new Error(`page not found: ${name}`);
    }

    if (!page.default.layout) {
        const Layout = name.startsWith('auth/') ? AuthLayout : BaseLayout;

        page.default.layout = (children: React.ReactNode) =>
            createElement(Layout, null, children);
    }

    return page;
}
