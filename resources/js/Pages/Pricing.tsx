import { Head, Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import AppLayout from '@/Layouts/AppLayout';
import CreditsModal from '@/Components/CreditsModal';
import { intlLocale, readSavedLang } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Currency = 'USD' | 'EUR' | 'GBP' | 'DZD';

type PackId = string;

type YieldItem = {
    value: string;
    label: string;
    icon: 'image' | 'video' | 'videoAlt' | 'music' | 'mic';
};

type Pack = {
    id: PackId;
    name: string;
    dzd: number;
    tokens: number;
    tagline: string;
    popular?: boolean;
    best?: boolean;
    accent: string;
    check: string;
    btn: string;
    yields: YieldItem[];
    perks: string[];
};

const CURRENCIES: Record<Currency, { label: string; name: string; symbol: string; flag: string; rateFromDzd: number }> = {
    DZD: { label: 'DZD', name: 'Algerian Dinar', symbol: 'DZD', flag: '🇩🇿', rateFromDzd: 1 },
    USD: { label: 'USD', name: 'US Dollar', symbol: '$', flag: '🇺🇸', rateFromDzd: 1 / 134 },
    EUR: { label: 'EUR', name: 'Euro', symbol: '€', flag: '🇪🇺', rateFromDzd: 0.92 / 134 },
    GBP: { label: 'GBP', name: 'British Pound', symbol: '£', flag: '🇬🇧', rateFromDzd: 0.79 / 134 },
};

/** Only DZD is live for SofizPay right now — keep others defined for later. */
const ENABLED_CURRENCIES: Currency[] = ['DZD'];

/** UI chrome only — price/tokens/name always come from token_packages. */
const PACK_META: Record<
    string,
    {
        taglineKey: string;
        perkKeys: string[];
        popular?: boolean;
        best?: boolean;
        accent: string;
        check: string;
        btn: string;
    }
> = {
    starter: {
        taglineKey: 'taglines.starter',
        accent: 'border-white/10 hover:border-white/20',
        check: 'text-emerald-400',
        btn: 'border border-white/12 bg-white/[0.05] text-white hover:bg-white/[0.09]',
        perkKeys: ['perks.allModels', 'perks.4k', 'perks.prioritySupport', 'perks.fastQueue', 'perks.bulk'],
    },
    creator: {
        taglineKey: 'taglines.creator',
        accent: 'border-sky-500/40 hover:border-sky-400/60',
        check: 'text-emerald-400',
        btn: 'border border-white/12 bg-white/[0.05] text-white hover:bg-white/[0.09]',
        perkKeys: ['perks.allModels', 'perks.4k', 'perks.prioritySupport', 'perks.fastQueue', 'perks.bulk'],
    },
    pro: {
        popular: true,
        taglineKey: 'taglines.pro',
        accent: 'border-[#FF5733]/55 ring-2 ring-[#FF5733]/20 hover:border-[#FF5733]/70',
        check: 'text-[#FF8A65]',
        btn: 'border border-white/12 bg-white/[0.05] text-white hover:bg-white/[0.09]',
        perkKeys: ['perks.allModels', 'perks.4k', 'perks.prioritySupport', 'perks.fastQueue', 'perks.bulk'],
    },
    business: {
        best: true,
        taglineKey: 'taglines.business',
        accent: 'border-amber-400/45 hover:border-amber-300/65',
        check: 'text-amber-400',
        btn: 'border border-white/12 bg-white/[0.05] text-white hover:bg-white/[0.09]',
        perkKeys: ['perks.allModels', 'perks.4k', 'perks.dedicatedSupport', 'perks.fastQueue', 'perks.bulk', 'perks.api'],
    },
};

const DEFAULT_META = {
    taglineKey: 'taglines.default',
    accent: 'border-white/10 hover:border-white/20',
    check: 'text-emerald-400',
    btn: 'border border-white/12 bg-white/[0.05] text-white hover:bg-white/[0.09]',
    perkKeys: ['perks.allModels', 'perks.hd'],
};

const FEATURE_ROWS: { key: string; packs: Record<string, boolean> }[] = [
    { key: 'perks.allModels', packs: { starter: true, creator: true, pro: true, business: true } },
    { key: 'perks.hd', packs: { starter: true, creator: true, pro: true, business: true } },
    { key: 'perks.4k', packs: { starter: true, creator: true, pro: true, business: true } },
    { key: 'perks.fastQueue', packs: { starter: true, creator: true, pro: true, business: true } },
    { key: 'perks.bulk', packs: { starter: true, creator: true, pro: true, business: true } },
    { key: 'perks.prioritySupport', packs: { starter: true, creator: true, pro: true, business: true } },
    { key: 'perks.dedicatedSupport', packs: { starter: false, creator: false, pro: false, business: true } },
    { key: 'perks.api', packs: { starter: false, creator: false, pro: false, business: true } },
];

const MODEL_TABS = [
    {
        id: 'image',
        labelKey: 'tabImage',
        hint: 'Lab · text-to-image',
        models: [
            { name: 'Nano Banana / Gemini Flash', cost: 'from ~15 tok' },
            { name: 'Nano Banana Pro', cost: 'from ~34 tok' },
            { name: 'Seedream 4', cost: 'from ~10 tok' },
            { name: 'Flux family', cost: 'from ~15 tok' },
            { name: 'GPT Image', cost: 'from ~40 tok' },
            { name: 'Grok Imagine', cost: 'from ~35 tok' },
        ],
    },
    {
        id: 'video',
        labelKey: 'tabVideo',
        hint: 'Lab · text / image-to-video',
        models: [
            { name: 'Kling 2.6', cost: 'from ~103 tok' },
            { name: 'Kling 2.5 Turbo', cost: 'from ~125 tok' },
            { name: 'Wan 2.6', cost: 'from ~100 tok' },
            { name: 'Seedance Pro', cost: 'from ~100 tok' },
            { name: 'Veo 3.1', cost: 'from ~400 tok' },
            { name: 'Kling 3.0', cost: 'from ~475 tok' },
        ],
    },
    {
        id: 'audio',
        labelKey: 'tabAudio',
        hint: 'Voiceover & music',
        models: [
            { name: 'ElevenLabs TTS', cost: 'from ~15 tok' },
            { name: 'Minimax Speech', cost: 'from ~35 tok' },
            { name: 'Suno V4', cost: 'from ~100 tok' },
            { name: 'Suno V5', cost: 'from ~150 tok' },
        ],
    },
    {
        id: 'tools',
        labelKey: 'tabTools',
        hint: 'Upscale & utilities',
        models: [
            { name: 'Video Upscaler', cost: 'per run' },
            { name: 'Video Enhancer', cost: 'per run' },
            { name: 'Background Remover', cost: 'from ~5 tok' },
            { name: 'Image Upscaler', cost: 'from ~5 tok' },
        ],
    },
] as const;

const FAQ_IDS = [1, 2, 3, 4, 5, 6] as const;

const fadeUp = {
    hidden: { opacity: 0, y: 24 },
    show: (i: number) => ({
        opacity: 1,
        y: 0,
        transition: { delay: 0.06 * i, duration: 0.45, ease: [0.22, 1, 0.36, 1] },
    }),
};

export default function Pricing() {
    const { t, i18n } = useTranslation('pricing');
    const { t: tc } = useTranslation('common');
    const { props } = usePage<PageProps>();
    const user = props.auth.user;
    const locale = intlLocale((i18n.language as 'en' | 'fr' | 'ar') || readSavedLang());
    const [currency, setCurrency] = useState<Currency>('DZD');
    const [modelTab, setModelTab] = useState<(typeof MODEL_TABS)[number]['id']>('image');
    const [openFaq, setOpenFaq] = useState<number | null>(0);
    const [creditsOpen, setCreditsOpen] = useState(false);
    const [busyPack, setBusyPack] = useState<PackId | null>(null);
    const [notice, setNotice] = useState<{ type: 'success' | 'error' | 'info'; text: string } | null>(null);

    const PACKS = useMemo(() => {
        const packages = props.tokenPackages ?? [];
        return packages.map((p) => {
            const meta = PACK_META[p.slug] ?? DEFAULT_META;
            const tokens = Number(p.tokens);
            return {
                id: p.slug,
                name: p.name,
                dzd: Number(p.price_dzd),
                tokens,
                tagline: t(meta.taglineKey),
                popular: 'popular' in meta ? meta.popular : undefined,
                best: 'best' in meta ? meta.best : undefined,
                accent: meta.accent,
                check: meta.check,
                btn: meta.btn,
                perks: meta.perkKeys.map((key) => t(key)),
                yields: [
                    { value: Math.max(0, Math.floor(tokens / 15) * 2).toLocaleString(locale), label: t('yields.imagesNano'), icon: 'image' as const },
                    { value: Math.max(0, Math.floor(tokens / 104) * 2).toLocaleString(locale), label: t('yields.videosKling'), icon: 'video' as const },
                    { value: Math.max(0, Math.floor(tokens / 400) * 2).toLocaleString(locale), label: t('yields.videosVeo'), icon: 'videoAlt' as const },
                    { value: Math.max(0, Math.floor(tokens / 42) * 2).toLocaleString(locale), label: t('yields.songsSuno'), icon: 'music' as const },
                    { value: Math.max(0, Math.floor(tokens / 10) * 2).toLocaleString(locale), label: t('yields.voiceovers'), icon: 'mic' as const },
                ],
            } satisfies Pack;
        });
    }, [props.tokenPackages, t, locale]);

    // Show the SofizPay return result, then strip the query so a refresh won't repeat it.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const result = params.get('payment');
        if (!result) return;

        const message = params.get('message') ?? '';
        const tokens = params.get('tokens');

        if (result === 'success') {
            const fallback = tokens
                ? `${t('notices.paymentOk')} ${t('notices.tokensAdded', { count: Number(tokens).toLocaleString(locale) })}`
                : t('notices.paymentOk');
            setNotice({ type: 'success', text: message || fallback });
        } else if (result === 'failed' || result === 'error') {
            setNotice({ type: 'error', text: message || t('notices.paymentFailed') });
        }

        params.delete('payment');
        params.delete('message');
        params.delete('tokens');
        const clean = window.location.pathname + (params.toString() ? `?${params.toString()}` : '');
        window.history.replaceState({}, '', clean);
    }, [t, locale]);

    async function startCheckout(pack: Pack) {
        if (!user) {
            window.location.href = '/register';
            return;
        }

        // SofizPay settles in Algerian Dinar only.
        if (currency !== 'DZD') {
            setCurrency('DZD');
            setNotice({ type: 'info', text: t('notices.switchDzd') });
            return;
        }

        if (busyPack) return;
        setBusyPack(pack.id);
        setNotice(null);

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const res = await fetch('/billing/sofizpay/create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ pack: pack.id }),
            });
            const data = (await res.json().catch(() => ({}))) as { checkout_url?: string; message?: string };

            if (res.ok && data.checkout_url) {
                window.location.href = data.checkout_url;
                return;
            }

            setNotice({ type: 'error', text: data.message || t('notices.checkoutError') });
        } catch {
            setNotice({ type: 'error', text: t('notices.networkError') });
        } finally {
            setBusyPack(null);
        }
    }

    const cur = CURRENCIES[currency];
    const activeModels = MODEL_TABS.find((tab) => tab.id === modelTab) ?? MODEL_TABS[0];
    const proPack = PACKS.find((p) => p.id === 'pro') ?? PACKS[Math.min(2, Math.max(0, PACKS.length - 1))] ?? null;

    const formatPrice = (dzd: number) => {
        const value = dzd * cur.rateFromDzd;
        if (currency === 'DZD') {
            return { amount: Math.round(value).toLocaleString(locale), suffix: 'DZD' };
        }
        const rounded = value % 1 < 0.05 || value % 1 > 0.95 ? Math.round(value).toString() : value.toFixed(2);
        return { amount: `${cur.symbol}${rounded}`, suffix: '' };
    };

    const proPrice = proPack ? formatPrice(proPack.dzd) : { amount: '—', suffix: '' };
    const ctaHref = user ? '/lab' : '/register';

    return (
        <AppLayout>
            <Head title={t('title')} />
            <div className="relative -mx-4 -my-4 overflow-hidden sm:-mx-5 lg:-mx-6 lg:-my-5 xl:-mx-8">
                {/* Atmosphere */}
                <div className="pointer-events-none absolute inset-x-0 top-0 h-[70vh] overflow-hidden">
                    <div className="absolute -left-24 top-10 h-72 w-72 rounded-full bg-[#FF5733]/20 blur-[110px]" />
                    <div className="absolute right-0 top-24 h-80 w-80 rounded-full bg-amber-500/10 blur-[120px]" />
                    <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_top,rgba(255,87,51,0.12),transparent_55%)]" />
                </div>

                <div className="relative mx-auto max-w-7xl px-4 pb-20 pt-8 sm:px-6 lg:px-8 lg:pt-12">
                    {/* Hero — asymmetric */}
                    <div className="grid items-end gap-10 lg:grid-cols-[1.15fr_0.85fr]">
                        <motion.div initial="hidden" animate="show" variants={fadeUp} custom={0}>
                            <p className="mb-4 inline-flex items-center gap-2 rounded-full border border-[#FF5733]/30 bg-[#FF5733]/10 px-3.5 py-1.5 text-[12px] font-semibold tracking-wide text-[#FF8A65]">
                                <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-[#FF5733]" />
                                {t('badge')}
                            </p>
                            <h1 className="font-[family-name:Outfit,sans-serif] text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-[3.4rem] lg:leading-[1.05]">
                                {t('heroTitle')}
                                <span className="mt-1 block bg-gradient-to-r from-[#FF8A65] via-[#FF5733] to-amber-300 bg-clip-text text-transparent">
                                    {t('heroTitleAccent')}
                                </span>
                            </h1>
                            <p className="mt-4 max-w-xl text-[15px] leading-relaxed text-white/50 sm:text-base">
                                {t('heroSub')}
                            </p>
                        </motion.div>

                        <motion.div
                            initial="hidden"
                            animate="show"
                            variants={fadeUp}
                            custom={1}
                            className="relative"
                        >
                            <div className="relative overflow-hidden rounded-[28px] border border-white/10 bg-gradient-to-br from-white/[0.07] to-white/[0.02] p-6 shadow-[0_30px_80px_-40px_rgba(255,87,51,0.55)] backdrop-blur-xl">
                                <div className="absolute -right-10 -top-10 h-40 w-40 rounded-full bg-[#FF5733]/25 blur-3xl" />
                                <div className="relative">
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.22em] text-white/35">{t('mostLoved')}</p>
                                    <div className="mt-3 flex items-center gap-2">
                                        <span className="rounded-full bg-gradient-to-r from-[#FF6A45] to-[#E24216] px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white">
                                            {proPack?.name ?? 'Pro'}
                                        </span>
                                        <span className="text-sm text-white/55">
                                            {proPack
                                                ? `${t('tokens', { count: proPack.tokens.toLocaleString(locale) })} · ${t('everythingUnlocked')}`
                                                : t('everythingUnlocked')}
                                        </span>
                                    </div>
                                    <div className="mt-6 flex items-end justify-between gap-4 border-t border-white/[0.06] pt-5">
                                        <div>
                                            <p className="text-[12px] text-white/40">{t('startsFrom')}</p>
                                            <p className="mt-1 font-[family-name:Outfit,sans-serif] text-2xl font-bold text-white">{t('proPack')}</p>
                                        </div>
                                        <p className="font-[family-name:Outfit,sans-serif] text-3xl font-bold tabular-nums text-[#FF8A65]">
                                            {proPrice.amount}
                                            {proPrice.suffix ? <span className="ms-1 text-base font-semibold text-white/45">{proPrice.suffix}</span> : null}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </motion.div>
                    </div>

                    {/* Free runway — guests only */}
                    {!user && (
                    <motion.section
                        initial="hidden"
                        whileInView="show"
                        viewport={{ once: true, margin: '-40px' }}
                        variants={fadeUp}
                        custom={0}
                        className="relative mt-12 overflow-hidden rounded-[24px] border border-emerald-400/20 bg-[#0b120e]"
                    >
                        <div className="absolute inset-0 bg-[radial-gradient(circle_at_0%_50%,rgba(16,185,129,0.22),transparent_45%)]" />
                        <div className="absolute inset-0 bg-[radial-gradient(circle_at_100%_50%,rgba(255,87,51,0.12),transparent_40%)]" />
                        <div className="relative flex flex-col items-start justify-between gap-6 p-6 sm:flex-row sm:items-center sm:p-8">
                            <div className="flex items-center gap-5">
                                <div className="flex h-16 w-16 shrink-0 flex-col items-center justify-center rounded-2xl border border-emerald-400/25 bg-emerald-500/10">
                                    <span className="font-[family-name:Outfit,sans-serif] text-2xl font-black text-white">50</span>
                                    <span className="text-[10px] font-semibold uppercase tracking-wider text-emerald-300">{tc('tokens')}</span>
                                </div>
                                <div>
                                    <p className="font-[family-name:Outfit,sans-serif] text-lg font-semibold text-white">{t('freeRibbonTitle')}</p>
                                    <p className="mt-1 max-w-md text-sm text-white/50">
                                        {t('freeRibbonSub')}
                                    </p>
                                </div>
                            </div>
                            <Link
                                href="/register"
                                className="inline-flex h-11 shrink-0 items-center gap-2 rounded-xl bg-emerald-500 px-6 text-sm font-semibold text-white shadow-[0_12px_30px_-12px_rgba(16,185,129,0.85)] transition hover:brightness-110"
                            >
                                {t('claimFree')}
                                <ArrowIcon />
                            </Link>
                        </div>
                    </motion.section>
                    )}

                    {/* Packs — standard equal rectangles */}
                    <section className="mt-14">
                        <div className="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <h2 className="font-[family-name:Outfit,sans-serif] text-2xl font-bold text-white sm:text-3xl">{t('choosePack')}</h2>
                                <p className="mt-1 text-sm text-white/45">{t('choosePackSub')}</p>
                            </div>

                            {/* Currency — DZD only for now (SofizPay) */}
                            <div className="flex flex-col items-start gap-1.5 lg:items-end">
                                <span className="text-[11px] font-medium uppercase tracking-wider text-white/35">{t('showPricesIn')}</span>
                                <div className="relative flex gap-1 rounded-full border border-white/10 bg-black/30 p-1">
                                    {ENABLED_CURRENCIES.map((code) => {
                                        const info = CURRENCIES[code];
                                        const active = currency === code;
                                        return (
                                            <button
                                                key={code}
                                                type="button"
                                                onClick={() => setCurrency(code)}
                                                className="relative flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-semibold transition"
                                            >
                                                {active && (
                                                    <motion.span
                                                        layoutId="currency-pill"
                                                        transition={{ type: 'spring', stiffness: 380, damping: 30 }}
                                                        className="absolute inset-0 rounded-full bg-gradient-to-b from-[#FF6A45] to-[#E24216] shadow-[0_8px_20px_-10px_rgba(255,87,51,0.9)]"
                                                    />
                                                )}
                                                <span className={`relative text-sm leading-none ${active ? '' : 'grayscale'}`}>{info.flag}</span>
                                                <span className={`relative ${active ? 'text-white' : 'text-white/55 hover:text-white'}`}>
                                                    {info.label}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        </div>

                        {notice && (
                            <div
                                className={`mb-5 flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm ${
                                    notice.type === 'success'
                                        ? 'border-emerald-400/30 bg-emerald-500/10 text-emerald-200'
                                        : notice.type === 'error'
                                          ? 'border-rose-400/30 bg-rose-500/10 text-rose-200'
                                          : 'border-sky-400/30 bg-sky-500/10 text-sky-200'
                                }`}
                            >
                                <span className="mt-0.5 shrink-0">
                                    {notice.type === 'success' ? '✅' : notice.type === 'error' ? '⚠️' : 'ℹ️'}
                                </span>
                                <span className="flex-1">{notice.text}</span>
                                <button
                                    type="button"
                                    onClick={() => setNotice(null)}
                                    className="shrink-0 text-white/40 transition hover:text-white"
                                    aria-label="Dismiss"
                                >
                                    ✕
                                </button>
                            </div>
                        )}

                        <div className="grid grid-cols-1 gap-5 pt-3 md:grid-cols-2 xl:grid-cols-4">
                            {PACKS.map((pack, i) => (
                                <PackCard
                                    key={pack.id}
                                    pack={pack}
                                    price={formatPrice(pack.dzd)}
                                    index={i}
                                    user={!!user}
                                    busy={busyPack === pack.id}
                                    onBuy={() => startCheckout(pack)}
                                    locale={locale}
                                />
                            ))}
                        </div>
                    </section>

                    {/* Compare — interactive chips, not Virali table clone */}
                    <section className="mt-16">
                        <div className="mb-6 text-center sm:text-start">
                            <h2 className="font-[family-name:Outfit,sans-serif] text-2xl font-bold text-white sm:text-3xl">{t('compareTitle')}</h2>
                            <p className="mt-1 text-sm text-white/45">{t('compareSub')}</p>
                        </div>

                        <div className="overflow-hidden rounded-[22px] border border-white/10 bg-white/[0.02]">
                            <div
                                className="grid gap-0 border-b border-white/[0.06] bg-white/[0.03] px-3 py-3 text-[11px] font-semibold uppercase tracking-wider text-white/40 sm:px-5 sm:text-xs"
                                style={{ gridTemplateColumns: `1.4fr repeat(${Math.max(PACKS.length, 1)}, 0.7fr)` }}
                            >
                                <span className="ps-1">{t('feature')}</span>
                                {PACKS.map((pack) => (
                                    <span key={pack.id} className="text-center text-white/70">
                                        {pack.name}
                                    </span>
                                ))}
                            </div>
                            {FEATURE_ROWS.map((row, i) => (
                                <motion.div
                                    key={row.key}
                                    initial={{ opacity: 0, x: -8 }}
                                    whileInView={{ opacity: 1, x: 0 }}
                                    viewport={{ once: true }}
                                    transition={{ delay: i * 0.04 }}
                                    className={`grid items-center gap-0 px-3 py-3.5 sm:px-5 ${
                                        i % 2 === 0 ? 'bg-transparent' : 'bg-white/[0.015]'
                                    }`}
                                    style={{ gridTemplateColumns: `1.4fr repeat(${Math.max(PACKS.length, 1)}, 0.7fr)` }}
                                >
                                    <span className="pe-2 text-[13px] text-white/75">{t(row.key)}</span>
                                    {PACKS.map((pack) => (
                                        <span key={pack.id} className="flex justify-center">
                                            {row.packs[pack.id] ? (
                                                <span className="flex h-6 w-6 items-center justify-center rounded-full bg-[#FF5733]/15 text-[#FF8A65]">
                                                    <CheckIcon />
                                                </span>
                                            ) : (
                                                <span className="h-1.5 w-1.5 rounded-full bg-white/15" />
                                            )}
                                        </span>
                                    ))}
                                </motion.div>
                            ))}
                        </div>
                    </section>

                    {/* Models — tabs */}
                    <section className="mt-16">
                        <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h2 className="font-[family-name:Outfit,sans-serif] text-2xl font-bold text-white sm:text-3xl">{t('modelGuideTitle')}</h2>
                                <p className="mt-1 text-sm text-white/45">{t('modelGuideSub')}</p>
                            </div>
                            <div className="flex flex-wrap gap-1.5 rounded-full border border-white/10 bg-black/30 p-1">
                                {MODEL_TABS.map((tab) => (
                                    <button
                                        key={tab.id}
                                        type="button"
                                        onClick={() => setModelTab(tab.id)}
                                        className={`rounded-full px-3.5 py-1.5 text-[12px] font-semibold transition ${
                                            modelTab === tab.id
                                                ? 'bg-white text-black'
                                                : 'text-white/55 hover:text-white'
                                        }`}
                                    >
                                        {t(tab.labelKey)}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <AnimatePresence mode="wait">
                            <motion.div
                                key={activeModels.id}
                                initial={{ opacity: 0, y: 12 }}
                                animate={{ opacity: 1, y: 0 }}
                                exit={{ opacity: 0, y: -8 }}
                                transition={{ duration: 0.28 }}
                                className="rounded-[22px] border border-white/10 bg-gradient-to-br from-white/[0.05] to-transparent p-5 sm:p-6"
                            >
                                <p className="mb-4 text-[12px] font-medium uppercase tracking-[0.18em] text-white/35">{activeModels.hint}</p>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {activeModels.models.map((m) => (
                                        <div
                                            key={m.name}
                                            className="flex items-center justify-between gap-3 rounded-xl border border-white/[0.06] bg-black/25 px-3.5 py-3 transition hover:border-[#FF5733]/35 hover:bg-[#FF5733]/5"
                                        >
                                            <span className="text-sm text-white/85">{m.name}</span>
                                            <span className="shrink-0 text-[12px] tabular-nums text-white/40">{m.cost}</span>
                                        </div>
                                    ))}
                                </div>
                            </motion.div>
                        </AnimatePresence>
                    </section>

                    {/* FAQ — two columns */}
                    <section className="mt-16">
                        <div className="mb-6">
                            <h2 className="font-[family-name:Outfit,sans-serif] text-2xl font-bold text-white sm:text-3xl">{t('faqTitle')}</h2>
                            <p className="mt-1 text-sm text-white/45">{t('faqSub')}</p>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            {FAQ_IDS.map((id, i) => {
                                const open = openFaq === i;
                                const q = t(`faqs.q${id}`);
                                const a = t(`faqs.a${id}`);
                                return (
                                    <motion.div
                                        key={id}
                                        layout
                                        className={`overflow-hidden rounded-2xl border transition ${
                                            open ? 'border-[#FF5733]/35 bg-[#FF5733]/[0.06]' : 'border-white/10 bg-white/[0.02]'
                                        }`}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => setOpenFaq(open ? null : i)}
                                            className="flex w-full items-center justify-between gap-3 px-4 py-4 text-start"
                                        >
                                            <span className="text-sm font-semibold text-white">{q}</span>
                                            <span className={`shrink-0 text-white/40 transition ${open ? 'rotate-45' : ''}`}>+</span>
                                        </button>
                                        <AnimatePresence initial={false}>
                                            {open && (
                                                <motion.div
                                                    initial={{ height: 0, opacity: 0 }}
                                                    animate={{ height: 'auto', opacity: 1 }}
                                                    exit={{ height: 0, opacity: 0 }}
                                                    transition={{ duration: 0.22 }}
                                                    className="overflow-hidden"
                                                >
                                                    <p className="px-4 pb-4 text-[13px] leading-relaxed text-white/50">{a}</p>
                                                </motion.div>
                                            )}
                                        </AnimatePresence>
                                    </motion.div>
                                );
                            })}
                        </div>
                    </section>

                    {/* CTA */}
                    <motion.section
                        initial={{ opacity: 0, y: 20 }}
                        whileInView={{ opacity: 1, y: 0 }}
                        viewport={{ once: true }}
                        className="relative mt-16 overflow-hidden rounded-[28px] border border-[#FF5733]/25"
                    >
                        <div className="absolute inset-0 bg-gradient-to-br from-[#FF5733]/25 via-[#1a0c08] to-[#0a0a0c]" />
                        <div className="absolute -right-20 top-0 h-64 w-64 rounded-full bg-amber-400/20 blur-[100px]" />
                        <div className="relative px-6 py-12 text-center sm:px-10 sm:py-14">
                            <p className="font-[family-name:Outfit,sans-serif] text-[12px] font-semibold tracking-[0.24em] text-[#FF8A65]">RIMBOAI</p>
                            <h2 className="mt-2 font-[family-name:Outfit,sans-serif] text-3xl font-bold text-white sm:text-4xl">
                                {t('ctaTitle')}
                            </h2>
                            <p className="mx-auto mt-3 max-w-lg text-sm text-white/50">
                                {t('ctaSub')}
                            </p>
                            <div className="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
                                <Link
                                    href={ctaHref}
                                    className="inline-flex h-11 items-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A45] to-[#E24216] px-7 text-sm font-semibold text-white shadow-[0_14px_36px_-14px_rgba(255,87,51,0.95)] transition hover:brightness-110"
                                >
                                    {user ? t('backToLab') : t('createAccount')}
                                    <ArrowIcon />
                                </Link>
                                <Link
                                    href="/lab"
                                    className="inline-flex h-11 items-center rounded-xl border border-white/15 bg-white/[0.04] px-7 text-sm font-semibold text-white transition hover:bg-white/[0.08]"
                                >
                                    {t('browseModels')}
                                </Link>
                            </div>
                        </div>
                    </motion.section>

                    <p className="mt-10 text-center text-[12px] text-white/30">
                        © {new Date().getFullYear()} RIMBOAI · {t('footerNote')}
                    </p>
                </div>
            </div>

            <CreditsModal open={creditsOpen} onClose={() => setCreditsOpen(false)} />
        </AppLayout>
    );
}

function PackCard({
    pack,
    price,
    index,
    user,
    busy,
    onBuy,
    locale,
}: {
    pack: Pack;
    price: { amount: string; suffix: string };
    index: number;
    user: boolean;
    busy: boolean;
    onBuy: () => void;
    locale: string;
}) {
    const { t } = useTranslation('pricing');

    return (
        <motion.article
            initial={{ opacity: 0, y: 22 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ delay: 0.07 * index, duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
            whileHover={{ y: -4, transition: { duration: 0.2 } }}
            className={`relative flex h-full flex-col rounded-2xl border bg-[#101014]/90 p-6 backdrop-blur-sm transition ${pack.accent}`}
        >
            {pack.popular && (
                <span className="absolute -top-3 start-1/2 z-10 inline-flex -translate-x-1/2 whitespace-nowrap rounded-full bg-gradient-to-r from-[#FF6A45] to-[#E24216] px-3.5 py-1 text-[10px] font-bold uppercase tracking-wider text-white shadow-lg shadow-[#FF5733]/40">
                    {t('mostPopular')}
                </span>
            )}
            {pack.best && (
                <span className="absolute -top-3 start-1/2 z-10 inline-flex -translate-x-1/2 whitespace-nowrap rounded-full bg-gradient-to-r from-amber-400 to-yellow-300 px-3.5 py-1 text-[10px] font-bold uppercase tracking-wider text-black shadow-lg shadow-amber-500/30">
                    {t('bestValue')}
                </span>
            )}

            <div className="pt-2 text-center">
                <h3 className="font-[family-name:Outfit,sans-serif] text-xl font-semibold text-white">{pack.name}</h3>
                <div className="mt-4 flex items-baseline justify-center gap-1.5">
                    <span className="font-[family-name:Outfit,sans-serif] text-4xl font-bold tabular-nums text-white md:text-[2.6rem]">
                        {price.amount}
                    </span>
                    {price.suffix ? <span className="text-sm text-white/45">{price.suffix}</span> : null}
                </div>
                <p className="mt-1.5 text-base font-medium text-[#FF8A65]">{t('tokens', { count: pack.tokens.toLocaleString(locale) })}</p>
            </div>

            <div className="my-5 h-px w-full bg-white/[0.08]" />

            <div className="flex-1">
                <p className="mb-3 text-start text-[12px] font-medium text-white/45">{t('whatYouCanCreate')}</p>
                <div className="space-y-2.5">
                    {pack.yields.map((y) => (
                        <div key={y.label} className="flex items-center gap-2.5">
                            <YieldIcon type={y.icon} />
                            <span className="text-start text-[13px] text-white/80">
                                <strong className="font-semibold text-white">{y.value}</strong> {y.label}
                            </span>
                        </div>
                    ))}
                </div>

                <p className="mt-4 text-start text-[13px] italic text-white/40">{pack.tagline}</p>

                <div className="my-5 h-px w-full bg-white/[0.08]" />

                <ul className="space-y-2">
                    {pack.perks.map((perk) => (
                        <li key={perk} className={`flex items-center gap-2 text-[13px] text-white/60`}>
                            <span className={pack.check}>
                                <CheckIcon />
                            </span>
                            {perk}
                        </li>
                    ))}
                </ul>
            </div>

            <PackCta user={user} busy={busy} onBuy={onBuy} className={pack.btn} />
        </motion.article>
    );
}

function YieldIcon({ type }: { type: YieldItem['icon'] }) {
    const wrap =
        type === 'image'
            ? 'bg-[#FF5733]/15 text-[#FF8A65]'
            : type === 'video'
              ? 'bg-sky-500/15 text-sky-400'
              : type === 'videoAlt'
                ? 'bg-violet-500/15 text-violet-400'
                : type === 'music'
                  ? 'bg-rose-500/15 text-rose-400'
                  : 'bg-cyan-500/15 text-cyan-400';

    return (
        <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${wrap}`}>
            {type === 'image' && (
                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <rect width="18" height="18" x="3" y="3" rx="2" />
                    <circle cx="9" cy="9" r="2" />
                    <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                </svg>
            )}
            {(type === 'video' || type === 'videoAlt') && (
                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5" />
                    <rect x="2" y="6" width="14" height="12" rx="2" />
                </svg>
            )}
            {type === 'music' && (
                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M9 18V5l12-2v13" />
                    <circle cx="6" cy="18" r="3" />
                    <circle cx="18" cy="16" r="3" />
                </svg>
            )}
            {type === 'mic' && (
                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                    <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                    <line x1="12" x2="12" y1="19" y2="22" />
                </svg>
            )}
        </span>
    );
}

function PackCta({ user, busy, onBuy, className }: { user: boolean; busy: boolean; onBuy: () => void; className: string }) {
    const { t } = useTranslation('pricing');
    const base =
        'mt-6 flex h-11 w-full items-center justify-center gap-2 rounded-xl text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60';

    if (user) {
        return (
            <button type="button" onClick={onBuy} disabled={busy} className={`${base} ${className}`}>
                {busy ? (
                    <>
                        <Spinner />
                        {t('starting')}
                    </>
                ) : (
                    <>
                        {t('getStarted')}
                        <ArrowIcon />
                    </>
                )}
            </button>
        );
    }

    return (
        <Link href="/register" className={`${base} ${className}`}>
            {t('getStarted')}
            <ArrowIcon />
        </Link>
    );
}

function Spinner() {
    return (
        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" />
        </svg>
    );
}

function CheckIcon() {
    return (
        <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5">
            <path strokeLinecap="round" strokeLinejoin="round" d="M20 6 9 17l-5-5" />
        </svg>
    );
}

function ArrowIcon() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
        </svg>
    );
}
