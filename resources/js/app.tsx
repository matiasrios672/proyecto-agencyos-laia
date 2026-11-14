import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx', { eager: false });

if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

createInertiaApp({
    title: (title) => (title ? `${title} · Agency OS` : 'Agency OS'),
    resolve: async (name) => {
        const loader = pages[`./pages/${name}.tsx`];
        if (!loader) throw new Error(`Página no encontrada: ${name}`);
        return (await loader()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: 'var(--primary)', showSpinner: false },
});
