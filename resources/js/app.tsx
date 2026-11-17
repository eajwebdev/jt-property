import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { Appearance, initializeTheme, syncAppearance } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#0B7A48', // brand green
    },
});

// Per-user theme: the saved value from the database wins on every page load/login.
router.on('navigate', (event) => {
    const saved = (event.detail.page.props.auth as { user?: { appearance?: Appearance } } | undefined)?.user?.appearance;
    if (saved) syncAppearance(saved);
});

// This will set light / dark mode on load...
initializeTheme();
