// The language lives in `persisters/` because the choice is persisted. This
// re-export keeps existing `hooks/useLanguage` imports working.
export {
    LanguageProvider,
    useLanguage,
    getStoredLanguage,
    LANGUAGE_STORAGE_KEY,
    SUPPORTED_LANGUAGES,
} from '../persisters/languageswitch';
