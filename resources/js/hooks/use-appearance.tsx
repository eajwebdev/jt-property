import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import type { SharedData } from '@/types';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const applyTheme = (appearance: Appearance) => {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);
};

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance;
    applyTheme(currentAppearance || 'system');
};

export function initializeTheme() {
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';

    applyTheme(savedAppearance);

    // Add the event listener for system theme changes...
    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

// The logged-in user's saved choice wins over whatever this browser remembered.
export function syncAppearance(mode: Appearance) {
    localStorage.setItem('appearance', mode);
    applyTheme(mode);
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');
    const { auth } = usePage<SharedData>().props;
    const loggedIn = Boolean(auth?.user);

    const updateAppearance = (mode: Appearance) => {
        setAppearance(mode);
        syncAppearance(mode);

        if (loggedIn) {
            router.patch(route('appearance.update'), { appearance: mode }, { preserveScroll: true, preserveState: true });
        }
    };

    useEffect(() => {
        const savedAppearance = (localStorage.getItem('appearance') as Appearance | null) || 'system';
        setAppearance(savedAppearance);
        applyTheme(savedAppearance);

        return () => mediaQuery.removeEventListener('change', handleSystemThemeChange);
    }, []);

    return { appearance, updateAppearance };
}
