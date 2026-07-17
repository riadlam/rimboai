import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import { applyLanguage, LANGUAGES, readSavedLang, type AppLang } from '@/lib/i18n';
import type { PageProps } from '@/types';

export default function Settings() {
    const { t } = useTranslation('settings');
    const { t: tc } = useTranslation('common');
    const { props } = usePage<PageProps>();
    const user = props.auth.user;

    const emailLocal = user?.email?.split('@')[0] || 'user';
    const [username, setUsername] = useState(emailLocal);
    const [displayName, setDisplayName] = useState(user?.name || '');
    const [bio, setBio] = useState('');
    const [email] = useState(user?.email || '');
    const [language, setLanguage] = useState<AppLang>(readSavedLang);
    const [currency, setCurrency] = useState('DZD');
    const [savedFlash, setSavedFlash] = useState(false);

    useEffect(() => {
        window.localStorage.setItem('app_currency', currency);
    }, [currency]);

    const saveProfile = () => {
        setSavedFlash(true);
        window.setTimeout(() => setSavedFlash(false), 1800);
    };

    const initials = (displayName || user?.name || 'U').slice(0, 2).toUpperCase();

    const currencies = [
        { code: 'DZD', label: t('currencyDzd') },
    ];

    return (
        <AppLayout>
            <Head title={tc('settings')} />
            <div className="mx-auto max-w-5xl [&_button]:cursor-pointer">
                <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="font-[family-name:Outfit,sans-serif] text-2xl font-semibold text-white">
                            {t('title')}
                        </h1>
                        <p className="mt-1 text-sm text-white/45">{t('subtitle')}</p>
                    </div>
                    <button
                        type="button"
                        onClick={saveProfile}
                        className="inline-flex items-center justify-center rounded-xl bg-[#FF5733] px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-[#ff6a4a]"
                    >
                        {savedFlash ? tc('saved') : tc('save')}
                    </button>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Profile — spans full width fields in nested grid */}
                    <Section title={t('profileSettings')} className="lg:col-span-2">
                        <div className="grid gap-5 md:grid-cols-[auto_1fr]">
                            <div className="flex flex-col items-center gap-3 md:items-start">
                                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-[#6366f1] via-[#a855f7] to-[#ec4899] text-lg font-semibold text-white">
                                    {initials}
                                </div>
                                <div className="text-center md:text-start">
                                    <p className="text-sm font-medium text-white">{t('profile')}</p>
                                    <p className="truncate text-xs text-white/40">@{username}</p>
                                </div>
                                <label className="flex w-full max-w-[220px] cursor-pointer flex-col items-center rounded-xl border border-dashed border-white/15 bg-white/[0.03] px-3 py-3 text-center transition hover:border-white/25 hover:bg-white/[0.05]">
                                    <input type="file" accept="image/*" className="hidden" />
                                    <span className="text-xs text-white/70">{t('uploadHint')}</span>
                                    <span className="mt-0.5 text-[11px] text-white/35">{t('orFromHistory')}</span>
                                </label>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label={t('username')}>
                                    <input
                                        value={username}
                                        onChange={(e) => setUsername(e.target.value)}
                                        className={inputClass}
                                    />
                                </Field>
                                <Field label={t('displayName')}>
                                    <input
                                        value={displayName}
                                        onChange={(e) => setDisplayName(e.target.value)}
                                        className={inputClass}
                                    />
                                </Field>
                                <Field label={t('email')} className="sm:col-span-2">
                                    <input value={email} readOnly className={`${inputClass} opacity-70`} />
                                </Field>
                                <Field label={t('bio')} className="sm:col-span-2">
                                    <textarea
                                        value={bio}
                                        onChange={(e) => setBio(e.target.value)}
                                        rows={2}
                                        placeholder={t('bioPlaceholder')}
                                        className={`${inputClass} resize-none`}
                                    />
                                </Field>
                            </div>
                        </div>
                    </Section>

                    {/* Preferences */}
                    <Section title={t('preferences')}>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                            <Field label={t('language')}>
                                <select
                                    value={language}
                                    onChange={(e) => {
                                        const next = e.target.value as AppLang;
                                        setLanguage(next);
                                        applyLanguage(next);
                                    }}
                                    className={inputClass}
                                >
                                    {LANGUAGES.map((l) => (
                                        <option key={l.code} value={l.code} className="bg-zinc-900">
                                            {l.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label={t('currency')}>
                                <select
                                    value={currency}
                                    onChange={(e) => setCurrency(e.target.value)}
                                    className={inputClass}
                                >
                                    {currencies.map((c) => (
                                        <option key={c.code} value={c.code} className="bg-zinc-900">
                                            {c.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                        </div>
                        <p className="mt-3 text-xs text-white/35">{t('prefsSavedAuto')}</p>
                    </Section>

                    {/* Account + Danger stacked in one column */}
                    <div className="grid gap-4">
                        <Section title={t('account')}>
                            {user ? (
                                <button
                                    type="button"
                                    onClick={() => router.post('/logout')}
                                    className="flex w-full items-center justify-between rounded-xl bg-white/[0.04] px-3 py-2.5 text-sm text-white/80 transition hover:bg-white/[0.08] hover:text-white"
                                >
                                    {t('logout')}
                                    <svg className="h-4 w-4 text-white/30 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="m9 18 6-6-6-6" />
                                    </svg>
                                </button>
                            ) : (
                                <p className="text-sm text-white/40">{t('notSignedIn')}</p>
                            )}
                        </Section>

                        <Section title={t('dangerZone')} danger>
                            <button
                                type="button"
                                className="w-full rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-2.5 text-sm font-medium text-rose-300 transition hover:bg-rose-500/20"
                            >
                                {t('deleteAccount')}
                            </button>
                        </Section>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

const inputClass =
    'w-full rounded-xl border border-white/10 bg-zinc-900/80 px-3 py-2 text-sm text-white outline-none transition placeholder:text-white/30 focus:border-[#FF5733]/50 focus:ring-2 focus:ring-[#FF5733]/20';

function Section({
    title,
    children,
    danger,
    className = '',
}: {
    title: string;
    children: ReactNode;
    danger?: boolean;
    className?: string;
}) {
    return (
        <section
            className={`rounded-2xl border p-4 sm:p-5 ${
                danger ? 'border-rose-500/20 bg-rose-500/[0.04]' : 'border-white/[0.06] bg-zinc-900/40'
            } ${className}`}
        >
            <h2
                className={`mb-3 font-[family-name:Outfit,sans-serif] text-sm font-semibold ${
                    danger ? 'text-rose-300' : 'text-white'
                }`}
            >
                {title}
            </h2>
            {children}
        </section>
    );
}

function Field({
    label,
    children,
    className = '',
}: {
    label: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <label className={`block ${className}`}>
            <span className="mb-1 block text-xs font-medium text-white/45">{label}</span>
            {children}
        </label>
    );
}
