import { createInertiaApp } from '@inertiajs/react';
import { defaultLayout, title } from './inertia-shared';
import { resolvePage } from './resolve-page';

createInertiaApp({
    title,
    resolve: resolvePage,
    layout: defaultLayout,
});
