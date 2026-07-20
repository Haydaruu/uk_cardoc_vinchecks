import { createInertiaApp } from '@inertiajs/react';
import { defaultLayout, title } from './inertia-shared';
import { resolvePage } from './resolve-page';

createInertiaApp({
    title: (title) => `${title} - UkcarDoc`,
    resolve: resolvePage,
    layout: defaultLayout,
    progress: {
        color: '#bb001a',
    },
});
