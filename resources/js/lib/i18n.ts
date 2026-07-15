import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import enCommon from '../locales/en/common.json';
import enNav from '../locales/en/nav.json';
import enAuth from '../locales/en/auth.json';
import enSettings from '../locales/en/settings.json';
import enHome from '../locales/en/home.json';
import enLab from '../locales/en/lab.json';
import enPricing from '../locales/en/pricing.json';
import enBilling from '../locales/en/billing.json';
import enTools from '../locales/en/tools.json';
import enHistory from '../locales/en/history.json';
import enTrends from '../locales/en/trends.json';
import enInnovation from '../locales/en/innovation.json';
import enErrors from '../locales/en/errors.json';

import frCommon from '../locales/fr/common.json';
import frNav from '../locales/fr/nav.json';
import frAuth from '../locales/fr/auth.json';
import frSettings from '../locales/fr/settings.json';
import frHome from '../locales/fr/home.json';
import frLab from '../locales/fr/lab.json';
import frPricing from '../locales/fr/pricing.json';
import frBilling from '../locales/fr/billing.json';
import frTools from '../locales/fr/tools.json';
import frHistory from '../locales/fr/history.json';
import frTrends from '../locales/fr/trends.json';
import frInnovation from '../locales/fr/innovation.json';
import frErrors from '../locales/fr/errors.json';

import arCommon from '../locales/ar/common.json';
import arNav from '../locales/ar/nav.json';
import arAuth from '../locales/ar/auth.json';
import arSettings from '../locales/ar/settings.json';
import arHome from '../locales/ar/home.json';
import arLab from '../locales/ar/lab.json';
import arPricing from '../locales/ar/pricing.json';
import arBilling from '../locales/ar/billing.json';
import arTools from '../locales/ar/tools.json';
import arHistory from '../locales/ar/history.json';
import arTrends from '../locales/ar/trends.json';
import arInnovation from '../locales/ar/innovation.json';
import arErrors from '../locales/ar/errors.json';

export type AppLang = 'en' | 'fr' | 'ar';

export const APP_LANG_COOKIE = 'app_lang';
export const APP_LANG_STORAGE_KEY = 'app_lang';

export const LANGUAGES: { code: AppLang; label: string; short: string }[] = [
    { code: 'en', label: 'English', short: 'EN' },
    { code: 'fr', label: 'French', short: 'FR' },
    { code: 'ar', label: 'Arabic', short: 'AR' },
];

const NAMESPACES = [
    'common',
    'nav',
    'auth',
    'settings',
    'home',
    'lab',
    'pricing',
    'billing',
    'tools',
    'history',
    'trends',
    'innovation',
    'errors',
] as const;

function isAppLang(value: string | null | undefined): value is AppLang {
    return value === 'en' || value === 'fr' || value === 'ar';
}

export function readSavedLang(): AppLang {
    if (typeof window === 'undefined') return 'en';
    const saved = window.localStorage.getItem(APP_LANG_STORAGE_KEY);
    return isAppLang(saved) ? saved : 'en';
}

export function syncLangCookie(lang: AppLang): void {
    if (typeof document === 'undefined') return;
    const maxAge = 60 * 60 * 24 * 365;
    document.cookie = `${APP_LANG_COOKIE}=${lang};path=/;max-age=${maxAge};SameSite=Lax`;
}

/** Apply document lang/dir, persist, sync cookie, and update i18next. */
export function applyLanguage(lang: AppLang): void {
    if (typeof window !== 'undefined') {
        window.localStorage.setItem(APP_LANG_STORAGE_KEY, lang);
    }
    if (typeof document !== 'undefined') {
        document.documentElement.lang = lang;
        document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
    }
    syncLangCookie(lang);
    if (i18n.language !== lang) {
        void i18n.changeLanguage(lang);
    }
}

export function intlLocale(lang: AppLang = readSavedLang()): string {
    if (lang === 'ar') return 'ar-DZ';
    if (lang === 'fr') return 'fr-DZ';
    return 'en';
}

const resources = {
    en: {
        common: enCommon,
        nav: enNav,
        auth: enAuth,
        settings: enSettings,
        home: enHome,
        lab: enLab,
        pricing: enPricing,
        billing: enBilling,
        tools: enTools,
        history: enHistory,
        trends: enTrends,
        innovation: enInnovation,
        errors: enErrors,
    },
    fr: {
        common: frCommon,
        nav: frNav,
        auth: frAuth,
        settings: frSettings,
        home: frHome,
        lab: frLab,
        pricing: frPricing,
        billing: frBilling,
        tools: frTools,
        history: frHistory,
        trends: frTrends,
        innovation: frInnovation,
        errors: frErrors,
    },
    ar: {
        common: arCommon,
        nav: arNav,
        auth: arAuth,
        settings: arSettings,
        home: arHome,
        lab: arLab,
        pricing: arPricing,
        billing: arBilling,
        tools: arTools,
        history: arHistory,
        trends: arTrends,
        innovation: arInnovation,
        errors: arErrors,
    },
};

const initialLang = typeof window !== 'undefined' ? readSavedLang() : 'en';

if (!i18n.isInitialized) {
    void i18n.use(initReactI18next).init({
        resources,
        lng: initialLang,
        fallbackLng: 'en',
        ns: [...NAMESPACES],
        defaultNS: 'common',
        interpolation: { escapeValue: false },
        returnNull: false,
    });
}

export default i18n;
