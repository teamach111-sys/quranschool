import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'tahfiz-theme';

/**
 * Light / "Soft Dusk" dark mode toggle persisted to localStorage.
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
    }, [theme]);

    const toggleTheme = useCallback(() => {
        setTheme((t) => (t === 'dark' ? 'light' : 'dark'));
    }, []);

    return { theme, toggleTheme };
}
