import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import AppProviders from '@/app/AppProviders';

const appName = import.meta.env.VITE_APP_NAME || 'AutoWave';

createInertiaApp({
    // Public tenant websites (body[data-site]) carry the business's own title, without the product name.
    title: (title) => (document.body.dataset.site ? title : title ? `${title} · ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.jsx`, import.meta.glob('./pages/**/*.jsx')),
    setup({ el, App, props }) {
        createRoot(el).render(
            <AppProviders>
                <App {...props} />
            </AppProviders>,
        );
    },
    progress: {
        color: '#4f46e5',
    },
});
