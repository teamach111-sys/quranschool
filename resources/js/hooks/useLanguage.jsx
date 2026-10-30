import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { translations } from '../i18n/translations';

const LanguageContext = createContext(null);

export function LanguageProvider({ children }) {
    const [lang, setLang] = useState('fr');

    const isRTL = lang === 'ar';
    const t = translations[lang];

    useEffect(() => {
        document.documentElement.setAttribute('lang', lang);
        document.documentElement.setAttribute('dir', isRTL ? 'rtl' : 'ltr');
    }, [lang, isRTL]);

    const toggleLang = useCallback(() => {
        setLang((l) => (l === 'fr' ? 'ar' : 'fr'));
    }, []);

    return (
        <LanguageContext.Provider value={{ lang, setLang, toggleLang, isRTL, t }}>
            {children}
        </LanguageContext.Provider>
    );
}

export function useLanguage() {
    const ctx = useContext(LanguageContext);
    if (!ctx) throw new Error('useLanguage must be used within LanguageProvider');
    return ctx;
}
