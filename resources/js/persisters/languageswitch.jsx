import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { translations } from '../i18n/translations';

export const LANGUAGE_STORAGE_KEY = 'tahfiz-language';
export const SUPPORTED_LANGUAGES = ['fr', 'ar'];
const DEFAULT_LANGUAGE = 'fr';

/** Read the persisted language, falling back to the app default. */
export function getStoredLanguage() {
    try {
        const stored = window.localStorage.getItem(LANGUAGE_STORAGE_KEY);
        return SUPPORTED_LANGUAGES.includes(stored) ? stored : DEFAULT_LANGUAGE;
    } catch {
        return DEFAULT_LANGUAGE;
    }
}

function writeStoredLanguage(lang) {
    try {
        window.localStorage.setItem(LANGUAGE_STORAGE_KEY, lang);
    } catch {
        // Storage may be unavailable (private mode); in-memory state still works.
    }
}

const LanguageContext = createContext(null);

/**
 * Persistent language state.
 *
 * The chosen language lives in localStorage (`tahfiz-language`) so it survives
 * reloads and is shared by every tab. `<html lang>` and `<html dir>` are kept
 * in sync, and `storage` events keep other open tabs aligned.
 */
export function LanguageProvider({ children }) {
    const [lang, setLangState] = useState(getStoredLanguage);

    const isRTL = lang === 'ar';
    const t = translations[lang];

    const setLang = useCallback((next) => {
        setLangState((current) => {
            const resolved = typeof next === 'function' ? next(current) : next;
            const value = SUPPORTED_LANGUAGES.includes(resolved) ? resolved : DEFAULT_LANGUAGE;
            writeStoredLanguage(value);
            return value;
        });
    }, []);

    const toggleLang = useCallback(() => {
        setLang((current) => (current === 'fr' ? 'ar' : 'fr'));
    }, [setLang]);

    useEffect(() => {
        document.documentElement.setAttribute('lang', lang);
        document.documentElement.setAttribute('dir', isRTL ? 'rtl' : 'ltr');
        const title = document.querySelector('title');
        if (title?.dataset.titleFr && title.dataset.titleAr) {
            title.textContent = isRTL ? title.dataset.titleAr : title.dataset.titleFr;
            title.setAttribute('lang', lang);
        }
    }, [lang, isRTL]);

    // Keep every open tab in sync with the stored language.
    useEffect(() => {
        const onStorage = (event) => {
            if (event.key !== LANGUAGE_STORAGE_KEY) return;
            const next = getStoredLanguage();
            setLangState((current) => (current === next ? current : next));
        };

        window.addEventListener('storage', onStorage);
        return () => window.removeEventListener('storage', onStorage);
    }, []);

    const value = useMemo(
        () => ({ lang, setLang, toggleLang, isRTL, t }),
        [lang, setLang, toggleLang, isRTL, t]
    );

    return <LanguageContext.Provider value={value}>{children}</LanguageContext.Provider>;
}

export function useLanguage() {
    const ctx = useContext(LanguageContext);
    if (!ctx) throw new Error('useLanguage must be used within LanguageProvider');
    return ctx;
}
