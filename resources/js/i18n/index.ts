import { createI18n } from 'vue-i18n';
import de from './de';
import en from './en';

export const SUPPORTED_LOCALES = ['de', 'en'] as const;
export type AppLocale = (typeof SUPPORTED_LOCALES)[number];

const STORAGE_KEY = 'schulquizz.locale';

function detectLocale(): AppLocale {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored && SUPPORTED_LOCALES.includes(stored as AppLocale)) {
        return stored as AppLocale;
    }
    // Fall back to the locale rendered by the server, then browser, then German.
    const htmlLang = document.documentElement.lang?.slice(0, 2);
    if (htmlLang && SUPPORTED_LOCALES.includes(htmlLang as AppLocale)) {
        return htmlLang as AppLocale;
    }
    const nav = navigator.language?.slice(0, 2);
    if (nav && SUPPORTED_LOCALES.includes(nav as AppLocale)) {
        return nav as AppLocale;
    }
    return 'de';
}

export const i18n = createI18n({
    legacy: false,
    globalInjection: true,
    locale: detectLocale(),
    fallbackLocale: 'de',
    messages: { de, en },
});

export function setLocale(locale: AppLocale): void {
    i18n.global.locale.value = locale;
    localStorage.setItem(STORAGE_KEY, locale);
    document.documentElement.lang = locale;
}
