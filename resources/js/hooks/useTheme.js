import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'tahfiz-theme';
const CHANGE_EVENT = 'tahfiz:theme-changed';

/**
 * Light / dark theme toggle persisted to localStorage.
 *
 * Every consumer keeps the same theme in sync: the class and storage writes
 * drive the CSS, and a custom event lets component instances that read the
 * theme in JS (e.g. the recharts palette) re-render on toggle.
 */
export default function useTheme() {
    const [theme, setTheme] = useState(() => {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'light' || stored === 'dark') return stored;
        return 'light';
    });

    useEffect(() => {
        document.documentElement.classList.toggle('dark', theme === 'dark');
        localStorage.setItem(STORAGE_KEY, theme);
        window.dispatchEvent(new CustomEvent(CHANGE_EVENT, { detail: { theme } }));
    }, [theme]);

    useEffect(() => {
        const sync = (event) => {
            const next = event?.detail?.theme ?? localStorage.getItem(STORAGE_KEY);
            if (next === 'light' || next === 'dark') {
                setTheme(next);
            }
        };

        const syncStorage = (event) => {
            if (event.key === STORAGE_KEY) sync(event);
        };

        window.addEventListener(CHANGE_EVENT, sync);
        window.addEventListener('storage', syncStorage);

        return () => {
            window.removeEventListener(CHANGE_EVENT, sync);
            window.removeEventListener('storage', syncStorage);
        };
    }, []);

    const toggleTheme = useCallback(() => {
        setTheme((t) => (t === 'dark' ? 'light' : 'dark'));
    }, []);

    return { theme, toggleTheme };
}
