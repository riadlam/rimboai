import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useLayoutEffect, useRef, useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import GoogleAuthButton from '@/Components/GoogleAuthButton';
import VideoThumb from '@/Components/VideoThumb';
import AppLayout from '@/Layouts/AppLayout';
import type { Brand, PageProps, Tool } from '@/types';
import { TemplateDetailModal, type TrendTemplate } from '@/Pages/Trends';
import type { InnovationPost } from '@/data/innovationPrompts';
import { trendWarmKey } from '@/lib/trendWarmVideo';

type HomeInnovationSection = {
    slug: string;
    name: string;
    posts: InnovationPost[];
};

type Props = {
    tools: Tool[];
    brands?: Brand[];
    trendTemplates?: TrendTemplate[];
    innovationSections?: HomeInnovationSection[];
};

/** Home hero demos (public disk → /storage/...). */
const HERO_VIDEO_SRC = '/storage/ai_icons/home_page_video.mp4';
const HERO_IMAGES_SRC = '/storage/ai_icons/gif_images.mp4';

const CREATE_TYPES = [
    {
        id: 'video',
        href: '/lab?type=text-to-video',
        media: { type: 'video' as const, src: HERO_VIDEO_SRC },
        accent: 'from-[#FF6A45] to-[#E24216]',
        glow: 'rgba(255,87,51,0.5)',
    },
    {
        id: 'image',
        href: '/lab?type=text-to-image',
        media: { type: 'video' as const, src: HERO_IMAGES_SRC },
        accent: 'from-[#a78bfa] to-[#6d28d9]',
        glow: 'rgba(139,92,246,0.35)',
    },
    {
        id: 'voice',
        href: '/lab?type=text-to-voice',
        media: { type: 'image' as const, src: '/storage/ai_icons/voice_home.webp' },
        accent: 'from-[#22d3ee] to-[#0e7490]',
        glow: 'rgba(6,182,212,0.35)',
    },
    {
        id: 'music',
        href: '/lab?type=text-to-music',
        media: { type: 'image' as const, src: '/storage/ai_icons/music_home.jpg' },
        accent: 'from-[#fbbf24] to-[#b45309]',
        glow: 'rgba(245,158,11,0.35)',
    },
] as const;

const ROTATING = ['today', 'rightNow', 'thatGoesViral', 'inSeconds'] as const;

/** Spring used for the hero card rotation morph (big slot <-> side stack). */
const HERO_SPRING = { type: 'spring' as const, stiffness: 220, damping: 28, mass: 0.9 };
/** How long each card stays in the big slot before rotating. */
const HERO_ROTATE_MS = 3000;

export default function Home({ tools, trendTemplates = [], innovationSections = [] }: Props) {
    const { t: ta } = useTranslation('auth');
    const { url, props } = usePage<PageProps>();
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
    const showLogin = !props.auth.user && new URLSearchParams(query).has('login');

    return (
        <AppLayout flush={showLogin}>
            <Head title={showLogin ? ta('signInTitle') : 'Home'} />
            {showLogin ? (
                <InlineLogin />
            ) : (
                <div className="-mx-4 -my-4 sm:-mx-5 lg:-mx-6 lg:-my-5 xl:-mx-8">
                    <Hero />

                    <div className="space-y-8 px-4 pb-16 pt-8 sm:px-5 sm:pt-0 lg:px-8">
                        <ToolRail tools={tools} />
                        <TrendRail templates={trendTemplates} />
                        {innovationSections.map((section) => (
                            <InnovationRail key={section.slug} section={section} />
                        ))}
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function InlineLogin() {
    const { t } = useTranslation('auth');
    const { t: th } = useTranslation('home');
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: true,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post('/login');
    };

    return (
        <div className="relative -mx-4 -my-3 flex min-h-[calc(100dvh-6rem)] w-[calc(100%+2rem)] flex-1 items-center justify-center overflow-hidden py-6 sm:-mx-5 sm:w-[calc(100%+2.5rem)] md:h-full md:min-h-0 lg:-mx-6 lg:-my-4 lg:w-[calc(100%+3rem)] xl:-mx-8 xl:w-[calc(100%+4rem)]">
            <div aria-hidden className="pointer-events-none absolute inset-0">
                <div className="absolute -left-32 top-0 h-96 w-96 rounded-full bg-[#FF5733]/20 blur-[120px]" />
                <div className="absolute -right-24 bottom-0 h-96 w-96 rounded-full bg-violet-600/15 blur-[130px]" />
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_50%_20%,rgba(255,255,255,0.045),transparent_48%)]" />
            </div>

            <motion.section
                initial={{ opacity: 0, y: 18, scale: 0.985 }}
                animate={{ opacity: 1, y: 0, scale: 1 }}
                transition={{ duration: 0.45, ease: [0.22, 1, 0.36, 1] }}
                className="scrollbar-thin relative max-h-full w-full max-w-[420px] overflow-y-auto rounded-[26px] border border-white/10 bg-[#101014]/95 p-6 shadow-[0_30px_100px_-32px_rgba(0,0,0,0.95)] backdrop-blur-xl sm:p-7"
            >
                <Link
                    href="/"
                    aria-label={th('signInClose')}
                    className="absolute end-4 top-4 flex h-9 w-9 items-center justify-center rounded-full text-white/35 transition hover:bg-white/[0.06] hover:text-white"
                >
                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                        <path strokeLinecap="round" d="M6 6l12 12M18 6 6 18" />
                    </svg>
                </Link>

                <div className="mb-5 text-center">
                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-[#FF7A55] to-[#E24216] font-[family-name:Outfit,sans-serif] text-xl font-extrabold text-white shadow-[0_16px_40px_-14px_rgba(255,87,51,0.9)]">
                        R
                    </div>
                    <p className="mt-1.5 font-[family-name:Outfit,sans-serif] text-[13px] font-semibold tracking-[0.2em] text-[#FF8A65]">
                        RIMBOAI
                    </p>
                </div>

                <GoogleAuthButton label={t('continueWithGoogle')} />

                <form onSubmit={submit} className="mt-4 space-y-3.5">
                    <label className="block">
                        <span className="mb-1.5 block text-xs font-medium text-white/65">{t('email')}</span>
                        <input
                            type="email"
                            value={data.email}
                            onChange={(event) => setData('email', event.target.value)}
                            autoComplete="email"
                            required
                            autoFocus
                            placeholder={t('emailPlaceholder')}
                            className="h-12 w-full rounded-xl border border-white/10 bg-black/25 px-4 text-sm text-white outline-none transition placeholder:text-white/20 focus:border-[#FF5733]/60 focus:ring-4 focus:ring-[#FF5733]/10"
                        />
                        {errors.email && <span className="mt-1.5 block text-xs text-rose-400">{errors.email}</span>}
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-medium text-white/65">{t('password')}</span>
                        <input
                            type="password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                            autoComplete="current-password"
                            required
                            placeholder={t('passwordPlaceholder')}
                            className="h-12 w-full rounded-xl border border-white/10 bg-black/25 px-4 text-sm text-white outline-none transition placeholder:text-white/20 focus:border-[#FF5733]/60 focus:ring-4 focus:ring-[#FF5733]/10"
                        />
                        {errors.password && <span className="mt-1.5 block text-xs text-rose-400">{errors.password}</span>}
                    </label>

                    <label className="flex cursor-pointer items-center gap-2.5 text-xs text-white/50">
                        <input
                            type="checkbox"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                            className="size-4 rounded border-white/15 bg-black/30 text-[#FF5733] focus:ring-[#FF5733]/30 focus:ring-offset-0"
                        />
                        {t('keepSignedIn')}
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-b from-[#FF6A45] to-[#E24216] text-sm font-semibold text-white shadow-[0_12px_30px_-12px_rgba(255,87,51,0.95)] transition hover:brightness-110 disabled:cursor-wait disabled:opacity-60"
                    >
                        {processing ? (
                            <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                        ) : (
                            <>
                                {t('signIn')}
                                <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14m-6-6 6 6-6 6" />
                                </svg>
                            </>
                        )}
                    </button>
                </form>
            </motion.section>
        </div>
    );
}

/* ---------------- Hero ---------------- */

function Hero() {
    const { t } = useTranslation('home');
    const [word, setWord] = useState(0);
    const [active, setActive] = useState(0);
    const [paused, setPaused] = useState(false);
    const featured = CREATE_TYPES[active];
    // Rotate the remaining cards so the order cycles like a wheel.
    const side = [1, 2, 3].map((o) => CREATE_TYPES[(active + o) % CREATE_TYPES.length]);

    const rotatorRef = useRef<HTMLDivElement>(null);
    const [box, setBox] = useState({ w: 0, h: 0 });
    const mRotatorRef = useRef<HTMLDivElement>(null);
    const [mBox, setMBox] = useState({ w: 0, h: 0 });

    useLayoutEffect(() => {
        const el = rotatorRef.current;
        if (!el) return;
        const measure = () => setBox({ w: el.clientWidth, h: el.clientHeight });
        measure();
        const ro = new ResizeObserver(measure);
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    useLayoutEffect(() => {
        const el = mRotatorRef.current;
        if (!el) return;
        const measure = () => setMBox({ w: el.clientWidth, h: el.clientHeight });
        measure();
        const ro = new ResizeObserver(measure);
        ro.observe(el);
        return () => ro.disconnect();
    }, []);

    useEffect(() => {
        const t = setInterval(() => setWord((w) => (w + 1) % ROTATING.length), 2600);
        return () => clearInterval(t);
    }, []);

    useEffect(() => {
        if (paused) return;
        const t = setInterval(() => setActive((a) => (a + 1) % CREATE_TYPES.length), HERO_ROTATE_MS);
        return () => clearInterval(t);
    }, [paused]);

    // Slot geometry (px) for the persistent-mount rotator — no remount, no video reload.
    const GAP = 12;
    const featuredW = box.w * 0.62;
    const sideLeft = featuredW + GAP;
    const sideW = Math.max(0, box.w - sideLeft);
    const sideH = Math.max(0, (box.h - GAP * 2) / 3);
    const rectFor = (id: string) => {
        if (id === featured.id) return { left: 0, top: 0, width: featuredW, height: box.h };
        const i = side.findIndex((s) => s.id === id);
        return { left: sideLeft, top: i * (sideH + GAP), width: sideW, height: sideH };
    };

    // Mobile slot geometry (px): big card on top, three compact cards in a row below.
    const M_GAP = 8;
    const M_BIG_H = 200;
    const M_SMALL_H = 116;
    const M_TOTAL_H = M_BIG_H + M_GAP + M_SMALL_H;
    const mSmallW = Math.max(0, (mBox.w - M_GAP * 2) / 3);
    const mRectFor = (id: string) => {
        if (id === featured.id) return { left: 0, top: 0, width: mBox.w, height: M_BIG_H };
        const i = side.findIndex((s) => s.id === id);
        return { left: i * (mSmallW + M_GAP), top: M_BIG_H + M_GAP, width: mSmallW, height: M_SMALL_H };
    };

    return (
        <section className="relative flex flex-col overflow-hidden px-4 md:min-h-[calc(100dvh-4rem)] sm:px-5 lg:px-8">
            {/* Atmosphere */}
            <div aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden">
                <motion.div
                    className="absolute -left-32 -top-40 h-[420px] w-[580px] rounded-full bg-gradient-to-br from-[#FF5733]/50 via-rose-500/25 to-transparent blur-[130px]"
                    animate={{ x: [0, 50, 0], y: [0, 28, 0], opacity: [0.45, 0.9, 0.45] }}
                    transition={{ duration: 10, repeat: Infinity, ease: 'easeInOut' }}
                />
                <motion.div
                    className="absolute left-[35%] -top-36 h-[360px] w-[500px] rounded-full bg-gradient-to-br from-violet-600/35 via-fuchsia-500/20 to-transparent blur-[120px]"
                    animate={{ x: [0, -40, 0], y: [0, 36, 0], opacity: [0.35, 0.75, 0.35] }}
                    transition={{ duration: 13, repeat: Infinity, ease: 'easeInOut', delay: 0.8 }}
                />
                <motion.div
                    className="absolute -right-24 -top-28 h-[400px] w-[520px] rounded-full bg-gradient-to-bl from-cyan-400/30 via-blue-600/20 to-transparent blur-[130px]"
                    animate={{ x: [0, -30, 0], y: [0, 22, 0], opacity: [0.4, 0.8, 0.4] }}
                    transition={{ duration: 12, repeat: Infinity, ease: 'easeInOut', delay: 0.4 }}
                />
                <motion.div
                    className="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-[#FF5733]/70 to-transparent"
                    animate={{ opacity: [0.25, 1, 0.25], scaleX: [0.7, 1, 0.7] }}
                    transition={{ duration: 3.2, repeat: Infinity }}
                />
                <Bubbles />
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_50%_0%,transparent_0%,#070708_78%)]" />
            </div>

            <div className="relative mx-auto flex w-full max-w-6xl flex-1 flex-col justify-start gap-3 pb-0 pt-5 sm:justify-center sm:gap-4 sm:py-4 lg:gap-5">
                {/* Headline */}
                <motion.div
                    initial={{ opacity: 0, y: 28, filter: 'blur(12px)' }}
                    animate={{ opacity: 1, y: 0, filter: 'blur(0px)' }}
                    transition={{ duration: 0.75, ease: [0.22, 1, 0.36, 1] }}
                    className="shrink-0 text-center"
                >
                    <h1 className="font-[family-name:Outfit,sans-serif] text-[28px] font-extrabold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-[52px] xl:text-[56px]">
                        {t('headline')}{' '}
                        <span className="relative inline-flex h-[1.12em] overflow-hidden align-bottom">
                            <AnimatePresence mode="popLayout">
                                <motion.span
                                    key={word}
                                    initial={{ y: '110%', opacity: 0 }}
                                    animate={{ y: '0%', opacity: 1 }}
                                    exit={{ y: '-110%', opacity: 0 }}
                                    transition={{ duration: 0.55, ease: [0.22, 1, 0.36, 1] }}
                                    className="bg-gradient-to-r from-[#FF8A65] via-amber-300 to-yellow-300 bg-clip-text text-transparent"
                                >
                                    {t(`rotating.${ROTATING[word]}`)}
                                </motion.span>
                            </AnimatePresence>
                        </span>
                        <span className="text-white">{t('headlineEnd')}</span>
                    </h1>
                    <motion.p
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        transition={{ delay: 0.35 }}
                        className="mx-auto mt-2 max-w-xl text-[13px] text-white/45 sm:text-sm"
                    >
                        {t('subtitle')}
                    </motion.p>
                </motion.div>

                {/* Mobile: cards stay mounted and morph between big slot and the row below (no reload) */}
                <div ref={mRotatorRef} className="relative md:hidden" style={{ height: M_TOTAL_H }}>
                    {mBox.w > 0 &&
                        CREATE_TYPES.map((c) => {
                            const isF = c.id === featured.id;
                            const rect = mRectFor(c.id);
                            return (
                                <motion.div
                                    key={c.id}
                                    className="absolute"
                                    style={{ zIndex: isF ? 20 : 10 }}
                                    initial={false}
                                    animate={{ left: rect.left, top: rect.top, width: rect.width, height: rect.height }}
                                    transition={HERO_SPRING}
                                >
                                    <CreateCard item={c} featured={isF} compact={!isF} autoPlay={c.media.type === 'video'} />
                                </motion.div>
                            );
                        })}
                </div>

                {/* Desktop: cards stay mounted and morph between big slot and side stack (no reload) */}
                <div
                    ref={rotatorRef}
                    className="relative hidden min-h-0 flex-1 md:block md:h-0 md:min-h-[220px] lg:min-h-[250px]"
                    onMouseEnter={() => setPaused(true)}
                    onMouseLeave={() => setPaused(false)}
                >
                    {/* Glow behind the big slot */}
                    <motion.div
                        aria-hidden
                        className="pointer-events-none absolute -z-0 rounded-[32px] blur-3xl"
                        style={{ backgroundColor: featured.glow }}
                        animate={{
                            left: rectFor(featured.id).left - 16,
                            top: rectFor(featured.id).top - 16,
                            width: rectFor(featured.id).width + 32,
                            height: rectFor(featured.id).height + 32,
                            opacity: [0.2, 0.5, 0.2],
                        }}
                        transition={{
                            left: HERO_SPRING,
                            top: HERO_SPRING,
                            width: HERO_SPRING,
                            height: HERO_SPRING,
                            opacity: { duration: 3.4, repeat: Infinity, ease: 'easeInOut' },
                        }}
                    />

                    {box.w > 0 &&
                        CREATE_TYPES.map((c) => {
                            const isF = c.id === featured.id;
                            const rect = rectFor(c.id);
                            return (
                                <motion.div
                                    key={c.id}
                                    className="absolute"
                                    style={{ zIndex: isF ? 20 : 10 }}
                                    initial={false}
                                    animate={{ left: rect.left, top: rect.top, width: rect.width, height: rect.height }}
                                    transition={HERO_SPRING}
                                >
                                    <CreateCard item={c} featured={isF} side={!isF} autoPlay={c.media.type === 'video'} />
                                </motion.div>
                            );
                        })}
                </div>

                {/* Rotation progress dots */}
                <div className="hidden items-center justify-center gap-2 md:flex">
                    {CREATE_TYPES.map((c, i) => (
                        <button
                            key={c.id}
                            type="button"
                            aria-label={`Show ${t(`createTypes.${c.id}.label`)}`}
                            onClick={() => setActive(i)}
                            className="group relative h-1.5 overflow-hidden rounded-full transition-all"
                            style={{ width: i === active ? 26 : 8 }}
                        >
                            <span className={`absolute inset-0 rounded-full ${i === active ? 'bg-white/20' : 'bg-white/15 group-hover:bg-white/30'}`} />
                            {i === active && !paused && (
                                <motion.span
                                    key={active}
                                    className="absolute inset-y-0 left-0 rounded-full bg-gradient-to-r from-[#FF6A45] to-[#FF5733]"
                                    initial={{ width: '0%' }}
                                    animate={{ width: '100%' }}
                                    transition={{ duration: HERO_ROTATE_MS / 1000, ease: 'linear' }}
                                />
                            )}
                            {i === active && paused && <span className="absolute inset-0 rounded-full bg-gradient-to-r from-[#FF6A45] to-[#FF5733]" />}
                        </button>
                    ))}
                </div>

                {/* Prompt */}
                <motion.button
                    type="button"
                    onClick={() => router.visit('/lab?type=text-to-video')}
                    initial={{ opacity: 0, y: 18 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ delay: 0.4, duration: 0.45 }}
                    whileHover={{ scale: 1.008 }}
                    whileTap={{ scale: 0.99 }}
                    className="group relative flex w-full shrink-0 items-center gap-3 overflow-hidden rounded-2xl border border-white/10 bg-white/[0.04] p-2.5 text-left backdrop-blur transition hover:border-[#FF5733]/40 sm:p-3.5"
                >
                    <span className="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/[0.07] to-transparent transition-transform duration-700 group-hover:translate-x-full" />
                    <span className="relative flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-[#FF6A45] to-[#E24216] text-white shadow-[0_10px_28px_-8px_rgba(255,87,51,0.95)] sm:h-10 sm:w-10">
                        <svg className="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13l0-8Z" />
                        </svg>
                    </span>
                    <span className="relative min-w-0 flex-1 truncate text-sm text-white/50">
                        {t('promptPlaceholder')}
                    </span>
                    <span className="relative hidden shrink-0 items-center gap-1.5 rounded-full bg-white px-4 py-2 text-xs font-semibold text-black transition group-hover:brightness-95 sm:inline-flex">
                        {t('generate')}
                        <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </span>
                </motion.button>

                {/* Mobile-only: quick jumps into each Lab mode */}
                <div className="grid grid-cols-2 gap-2.5 md:hidden">
                    {CREATE_TYPES.map((c, i) => (
                        <motion.button
                            key={c.id}
                            type="button"
                            onClick={() => router.visit(c.href)}
                            initial={{ opacity: 0, y: 14 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ delay: 0.48 + i * 0.05, duration: 0.4 }}
                            whileTap={{ scale: 0.97 }}
                            className="group relative flex items-center gap-3 overflow-hidden rounded-2xl border border-white/[0.08] bg-white/[0.035] p-3 text-left transition active:border-white/20"
                        >
                            <span
                                aria-hidden
                                className={`pointer-events-none absolute -end-6 -top-6 h-16 w-16 rounded-full bg-gradient-to-br ${c.accent} opacity-25 blur-2xl transition group-active:opacity-40`}
                            />
                            <span
                                className={`relative flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br ${c.accent} text-white shadow-[0_10px_24px_-12px_rgba(0,0,0,0.85)]`}
                            >
                                <CreateTypeIcon id={c.id} />
                            </span>
                            <span className="relative min-w-0 flex-1">
                                <span className="block text-[13px] font-semibold text-white">{t(`createTypes.${c.id}.label`)}</span>
                                <span className="mt-0.5 block truncate text-[11px] text-white/40">{t('openLab')}</span>
                            </span>
                            <svg
                                className="relative h-3.5 w-3.5 shrink-0 text-white/30 transition group-active:text-white/55"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                strokeWidth="2.2"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </motion.button>
                    ))}
                </div>
            </div>
        </section>
    );
}

function CreateTypeIcon({ id }: { id: string }) {
    if (id === 'image') {
        return (
            <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <rect width="18" height="18" x="3" y="3" rx="2" />
                <circle cx="9" cy="9" r="2" />
                <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
            </svg>
        );
    }
    if (id === 'voice') {
        return (
            <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                <line x1="12" x2="12" y1="19" y2="22" />
            </svg>
        );
    }
    if (id === 'music') {
        return (
            <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M9 18V5l12-2v13" />
                <circle cx="6" cy="18" r="3" />
                <circle cx="18" cy="16" r="3" />
            </svg>
        );
    }
    return (
        <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.934a.5.5 0 0 0-.752-.432L16 10.5" />
            <rect x="2" y="6" width="14" height="12" rx="2" />
        </svg>
    );
}

const BUBBLES = Array.from({ length: 22 }, (_, i) => {
    const size = 5 + ((i * 7) % 28);
    return {
        size,
        left: (i * 47) % 100,
        delay: (i % 7) * 0.75,
        duration: 8 + ((i * 3) % 11),
        drift: (i % 2 === 0 ? 1 : -1) * (12 + ((i * 5) % 34)),
        tint: ['bg-[#FF5733]/25', 'bg-amber-400/20', 'bg-violet-500/20', 'bg-cyan-400/20', 'bg-rose-500/20'][i % 5],
    };
});

function Bubbles() {
    return (
        <div className="absolute inset-0 overflow-hidden">
            {BUBBLES.map((b, i) => (
                <motion.span
                    key={i}
                    className={`absolute bottom-[-40px] rounded-full ${b.tint} ring-1 ring-white/10`}
                    style={{ left: `${b.left}%`, width: b.size, height: b.size }}
                    initial={{ y: 0, opacity: 0 }}
                    animate={{
                        y: [0, -620],
                        x: [0, b.drift, 0],
                        opacity: [0, 0.85, 0.85, 0],
                        scale: [0.6, 1, 1, 0.85],
                    }}
                    transition={{
                        duration: b.duration,
                        delay: b.delay,
                        repeat: Infinity,
                        ease: 'easeInOut',
                    }}
                />
            ))}
        </div>
    );
}

function CreateCard({
    item,
    compact = false,
    featured = false,
    side = false,
    autoPlay = false,
}: {
    item: (typeof CREATE_TYPES)[number];
    compact?: boolean;
    featured?: boolean;
    side?: boolean;
    autoPlay?: boolean;
}) {
    const { t } = useTranslation('home');
    const isVideo = item.media.type === 'video';
    const label = t(`createTypes.${item.id}.label`);
    const desc = t(`createTypes.${item.id}.desc`);

    return (
        <Link
            href={item.href}
            className={`group relative block h-full w-full cursor-pointer overflow-hidden border border-white/10 bg-[#0c0c0f] transition-[border-color,box-shadow] duration-400 ${
                featured ? 'rounded-[22px]' : 'rounded-2xl'
            } hover:border-white/25`}
            style={featured ? { boxShadow: `0 28px 70px -28px ${item.glow}` } : undefined}
            onMouseEnter={(e) => {
                const v = e.currentTarget.querySelector('video');
                v?.play()?.catch(() => undefined);
            }}
            onMouseLeave={(e) => {
                const v = e.currentTarget.querySelector('video');
                if (v && !autoPlay) {
                    v.pause();
                    v.currentTime = 0;
                }
            }}
        >
            <div
                className={`relative w-full overflow-hidden ${
                    compact
                        ? 'h-full min-h-0'
                        : featured
                          ? 'h-full min-h-0'
                          : side
                            ? 'h-full min-h-0'
                            : 'h-full min-h-[180px]'
                }`}
            >
                {isVideo ? (
                    <video
                        src={item.media.src}
                        className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-[1.06]"
                        muted
                        loop
                        playsInline
                        autoPlay={autoPlay}
                        preload={autoPlay || item.id === 'video' ? 'auto' : 'metadata'}
                    />
                ) : (
                    <img
                        src={item.media.src}
                        alt={label}
                        className="absolute inset-0 h-full w-full object-cover transition-transform duration-700 group-hover:scale-[1.06]"
                        loading="lazy"
                    />
                )}

                <div className="absolute inset-0 bg-gradient-to-t from-black/90 via-black/30 to-black/5" />
                <div
                    className={`pointer-events-none absolute inset-0 opacity-0 mix-blend-overlay transition-opacity duration-500 group-hover:opacity-45 bg-gradient-to-br ${item.accent}`}
                />

                <div className={`absolute inset-x-0 bottom-0 ${featured ? 'p-4 sm:p-5' : compact ? 'p-2' : side ? 'flex items-center justify-between gap-2 p-3' : 'p-3'}`}>
                    <div>
                        <div className="flex items-center gap-2">
                            <span className={`rounded-full bg-gradient-to-r ${item.accent} ${compact ? 'h-1.5 w-1.5' : 'h-2 w-2'}`} />
                            <h3
                                className={`font-[family-name:Outfit,sans-serif] font-extrabold text-white ${
                                    featured ? 'text-2xl lg:text-3xl' : compact ? 'text-xs sm:text-sm' : 'text-sm lg:text-base'
                                }`}
                            >
                                {label}
                            </h3>
                        </div>
                        {!compact && (
                            <p className={`mt-0.5 text-white/60 ${featured ? 'max-w-md text-[13px] lg:text-sm' : 'line-clamp-1 text-[11px]'}`}>
                                {desc}
                            </p>
                        )}
                    </div>
                    {side && (
                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-white/10 text-white opacity-0 transition group-hover:opacity-100">
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M7 17 17 7M9 7h8v8" />
                            </svg>
                        </span>
                    )}
                </div>

                {featured && (
                    <span className="absolute end-3 top-3 flex h-9 w-9 translate-y-1 items-center justify-center rounded-full bg-white/10 text-white opacity-0 backdrop-blur transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100">
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M7 17 17 7M9 7h8v8" />
                        </svg>
                    </span>
                )}
            </div>
        </Link>
    );
}

/* ---------------- Rails ---------------- */

function RailHeader({ title, sub, href }: { title: string; sub?: string; href: string }) {
    const { t } = useTranslation('home');
    return (
        <div className="mb-4 flex items-end justify-between gap-4">
            <div>
                <h2 className="font-[family-name:Outfit,sans-serif] text-xl font-bold text-white md:text-2xl">{title}</h2>
                {sub && <p className="mt-0.5 text-sm text-white/45">{sub}</p>}
            </div>
            <Link
                href={href}
                className="group flex shrink-0 items-center gap-1 text-sm text-white/50 transition-colors hover:text-white"
            >
                {t('seeAll')}
                <svg className="h-4 w-4 transition-transform group-hover:translate-x-0.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                    <path strokeLinecap="round" strokeLinejoin="round" d="m9 18 6-6-6-6" />
                </svg>
            </Link>
        </div>
    );
}

function ToolRail({ tools }: { tools: Tool[] }) {
    const { t } = useTranslation('home');
    const list = tools.slice(0, 12);
    const tags = [
        t('tags.upscale'),
        t('tags.enhance'),
        t('tags.lipSync'),
        t('tags.motion'),
    ];

    return (
        <motion.section
            initial={{ opacity: 0, y: 28 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-80px' }}
            transition={{ duration: 0.55, ease: [0.22, 1, 0.36, 1] }}
            className="relative overflow-hidden rounded-[28px] border border-white/[0.08] bg-gradient-to-br from-white/[0.04] via-[#0c0c10] to-[#0a0a0c]"
        >
            {/* Ambient wash */}
            <div aria-hidden className="pointer-events-none absolute inset-0">
                <div className="absolute -left-20 top-0 h-56 w-56 rounded-full bg-[#FF5733]/20 blur-[90px]" />
                <div className="absolute right-0 top-1/2 h-64 w-64 -translate-y-1/2 rounded-full bg-violet-600/15 blur-[100px]" />
                <div className="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-[#FF5733]/35 to-transparent" />
            </div>

            <div className="relative flex flex-col gap-6 p-4 sm:p-5 lg:flex-row lg:items-start lg:gap-12 lg:p-7">
                {/* Copy column */}
                <div className="flex w-full shrink-0 flex-col lg:w-[260px] xl:w-[280px]">
                    <div>
                        <motion.span
                            initial={{ opacity: 0, y: 8 }}
                            whileInView={{ opacity: 1, y: 0 }}
                            viewport={{ once: true }}
                            className="mb-3 inline-flex items-center gap-1.5 rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-white/50"
                        >
                            <span className="h-1.5 w-1.5 rounded-full bg-[#FF5733]" />
                            {t('toolsReady', { count: list.length })}
                        </motion.span>

                        <h2 className="font-[family-name:Outfit,sans-serif] text-[28px] font-extrabold leading-[1.05] tracking-tight text-white lg:text-[34px]">
                            {t('whatWillYou')}
                            <br />
                            <span className="relative inline-block bg-gradient-to-r from-[#FF8A65] via-[#FF5733] to-amber-300 bg-clip-text text-transparent">
                                {t('createToday')}
                                <motion.span
                                    aria-hidden
                                    className="absolute -bottom-1 start-0 h-[3px] w-full rounded-full bg-gradient-to-r from-[#FF5733] to-transparent"
                                    initial={{ scaleX: 0 }}
                                    whileInView={{ scaleX: 1 }}
                                    viewport={{ once: true }}
                                    transition={{ delay: 0.25, duration: 0.6 }}
                                    style={{ transformOrigin: 'left' }}
                                />
                            </span>
                        </h2>

                        <p className="mt-3.5 max-w-xs text-[13px] leading-relaxed text-white/45 lg:text-sm">
                            {t('toolsDesc')}
                        </p>

                        <div className="mt-4 flex flex-wrap gap-2">
                            {tags.map((tag) => (
                                <span
                                    key={tag}
                                    className="rounded-lg border border-white/[0.07] bg-white/[0.03] px-2.5 py-1 text-[11px] font-medium text-white/55"
                                >
                                    {tag}
                                </span>
                            ))}
                        </div>
                    </div>

                    <Link
                        href="/tools"
                        className="group mt-5 hidden w-fit items-center gap-2 rounded-full bg-gradient-to-b from-[#FF6A45] to-[#E24216] px-5 py-2.5 text-sm font-semibold text-white shadow-[0_14px_32px_-12px_rgba(255,87,51,0.95)] transition hover:brightness-110 active:scale-[0.98] lg:inline-flex"
                    >
                        {t('exploreAllTools')}
                        <svg className="h-4 w-4 transition-transform group-hover:translate-x-0.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </Link>
                </div>

                {/* Tool scroller — 2 rows */}
                <div className="relative min-w-0 flex-1 ps-1 lg:border-s lg:border-white/[0.06] lg:ps-10">
                    <div className="pointer-events-none absolute inset-y-0 end-0 z-10 w-12 bg-gradient-to-l from-[#0a0a0c] to-transparent" />

                    <div className="scrollbar-hide overflow-x-auto pb-1 pt-1">
                        <div className="grid w-max auto-cols-[148px] grid-flow-col grid-rows-2 gap-3 sm:auto-cols-[160px] sm:gap-3.5 lg:auto-cols-[168px]">
                            {list.map((tool, i) => (
                                <ToolChip
                                    key={tool.route}
                                    tool={tool}
                                    hot={i === 0}
                                    badge={tool.badge}
                                    index={i}
                                />
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {/* Mobile CTA */}
            <div className="relative border-t border-white/[0.06] px-4 py-3 lg:hidden">
                <Link
                    href="/tools"
                    className="flex w-full items-center justify-center gap-2 rounded-xl border border-[#FF5733]/35 bg-[#FF5733]/10 py-3 text-sm font-semibold text-[#ffb39f] transition hover:bg-[#FF5733]/15"
                >
                    {t('exploreAllTools')}
                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13l0-8Z" />
                    </svg>
                </Link>
            </div>
        </motion.section>
    );
}

function ToolChip({
    tool,
    hot,
    badge,
    index,
}: {
    tool: Tool;
    hot?: boolean;
    badge?: string;
    index: number;
}) {
    const { t } = useTranslation('home');
    const path = '/tools/' + tool.route.replace('tools.', '');
    const label = badge === 'New' ? t('new') : badge || (hot ? t('hot') : null);
    const isNew = badge === 'New' || label === t('new');

    return (
        <motion.div
            initial={{ opacity: 0, y: 16 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true }}
            transition={{ delay: Math.min(index * 0.05, 0.35), duration: 0.4 }}
        >
            <Link
                href={path}
                className="group relative block w-[148px] shrink-0 cursor-pointer sm:w-[160px] lg:w-[168px]"
            >
                <div className="relative mb-2.5 aspect-[4/5] overflow-hidden rounded-2xl border border-white/[0.08] bg-[#101014] shadow-[0_16px_40px_-28px_rgba(0,0,0,0.9)] transition-all duration-300 group-hover:-translate-y-1 group-hover:border-[#FF5733]/40 group-hover:shadow-[0_20px_48px_-20px_rgba(255,87,51,0.45)]">
                    {label && (
                        <span
                            className={`absolute start-2 top-2 z-10 rounded-md px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white shadow ${
                                isNew ? 'bg-emerald-500/90' : 'bg-[#FF5733]/95'
                            }`}
                        >
                            {label}
                        </span>
                    )}

                    <video
                        src={tool.video}
                        poster={tool.poster}
                        className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-110"
                        muted
                        loop
                        playsInline
                        autoPlay
                        preload="auto"
                    />

                    <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent opacity-80" />

                    <div className="absolute inset-x-0 bottom-0 translate-y-2 p-2.5 opacity-0 transition-all duration-300 group-hover:translate-y-0 group-hover:opacity-100">
                        <span className="inline-flex w-full items-center justify-center gap-1 rounded-full bg-white px-3 py-1.5 text-[11px] font-semibold text-black">
                            {t('useTool')}
                            <svg className="h-3 w-3 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.5">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                            </svg>
                        </span>
                    </div>
                </div>

                <div className="flex items-center gap-1.5 px-0.5">
                    <h3 className="truncate text-sm font-semibold text-white/85 transition-colors group-hover:text-white">
                        {tool.name}
                    </h3>
                    <svg
                        className="h-3.5 w-3.5 shrink-0 text-zinc-600 transition-all group-hover:translate-x-0.5 group-hover:text-[#FF5733]"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        strokeWidth="2"
                    >
                        <path strokeLinecap="round" strokeLinejoin="round" d="m9 18 6-6-6-6" />
                    </svg>
                </div>
            </Link>
        </motion.div>
    );
}

function TrendRail({ templates }: { templates: TrendTemplate[] }) {
    const { t } = useTranslation('home');
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const selected = selectedId ? templates.find((row) => row.id === selectedId) ?? null : null;

    useEffect(() => {
        if (!selectedId) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setSelectedId(null);
        };
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [selectedId]);

    if (templates.length === 0) {
        return null;
    }

    const useInLab = (row: TrendTemplate) => {
        setSelectedId(null);
        router.visit(`/trends/${row.id}`);
    };

    return (
        <motion.section
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-80px' }}
            transition={{ duration: 0.5 }}
        >
            <RailHeader title={t('creatorsTrends')} sub={t('creatorsTrendsSub')} href="/trends" />
            <div className="scrollbar-hide -mx-1 overflow-x-auto px-1 pb-2">
                <div className="flex gap-4">
                    {templates.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            onClick={() => setSelectedId(item.id)}
                            className="group relative w-[200px] shrink-0 cursor-pointer overflow-hidden rounded-2xl text-left sm:w-[220px]"
                        >
                            <div className="relative aspect-[4/5] overflow-hidden rounded-2xl bg-[#101014]">
                                {item.coverType === 'video' ? (
                                    <VideoThumb
                                        src={item.video_url || item.cover}
                                        poster={item.thumbnail_url || undefined}
                                        warmKey={trendWarmKey(item.id, item.video_url || item.cover)}
                                        playOnHover={false}
                                        autoLoop
                                        className="absolute inset-0 size-full object-cover transition-transform duration-700 group-hover:scale-105"
                                    />
                                ) : (
                                    <img
                                        src={item.cover}
                                        alt={item.name}
                                        className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
                                        loading="lazy"
                                    />
                                )}
                                <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/90 via-black/25 to-transparent" />
                                {item.featured && (
                                    <div className="absolute start-3 top-3 inline-flex items-center rounded-md bg-amber-500/90 px-2 py-0.5 text-[10px] font-semibold text-white">
                                        <svg className="me-1 h-3 w-3 fill-current" viewBox="0 0 24 24">
                                            <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" />
                                        </svg>
                                        {t('featured')}
                                    </div>
                                )}
                                <div className="absolute end-3 top-3 rounded-md border border-white/20 bg-black/50 px-2 py-0.5 text-[10px] font-medium text-white backdrop-blur-sm">
                                    {t('creditsLabel', { count: item.credits })}
                                </div>
                                <div className="absolute inset-x-0 bottom-0 p-4">
                                    <h3 className="line-clamp-2 text-sm font-semibold text-white">{item.name}</h3>
                                </div>
                            </div>
                        </button>
                    ))}
                </div>
            </div>

            <AnimatePresence>
                {selected && (
                    <TemplateDetailModal
                        template={selected}
                        using={false}
                        onClose={() => setSelectedId(null)}
                        onUse={() => useInLab(selected)}
                    />
                )}
            </AnimatePresence>
        </motion.section>
    );
}

function InnovationRail({ section }: { section: HomeInnovationSection }) {
    const href = `/innovation?category=${encodeURIComponent(section.slug)}`;

    return (
        <motion.section
            initial={{ opacity: 0, y: 20 }}
            whileInView={{ opacity: 1, y: 0 }}
            viewport={{ once: true, margin: '-80px' }}
            transition={{ duration: 0.5 }}
        >
            <RailHeader title={section.name} href={href} />
            <div className="scrollbar-hide -mx-1 overflow-x-auto px-1 pb-2">
                <div className="flex gap-4">
                    {section.posts.map((post) => (
                        <Link
                            key={post.id}
                            href={href}
                            className="group relative w-[160px] shrink-0 cursor-pointer overflow-hidden rounded-2xl sm:w-[180px]"
                            onMouseEnter={(e) => {
                                const v = e.currentTarget.querySelector('video');
                                v?.play()?.catch(() => undefined);
                            }}
                            onMouseLeave={(e) => {
                                const v = e.currentTarget.querySelector('video');
                                if (v) {
                                    v.pause();
                                    v.currentTime = 0;
                                }
                            }}
                        >
                            <div className="relative aspect-[4/5] overflow-hidden rounded-2xl bg-[#101014]">
                                {post.media === 'videos' && post.video ? (
                                    <video
                                        src={post.video}
                                        poster={post.image || undefined}
                                        className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
                                        muted
                                        loop
                                        playsInline
                                        preload="none"
                                    />
                                ) : (
                                    <img
                                        src={post.image}
                                        alt={post.title}
                                        className="h-full w-full object-cover transition-transform duration-700 group-hover:scale-105"
                                        loading="lazy"
                                    />
                                )}
                                <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent" />
                                <div className="absolute inset-x-0 bottom-0 p-3">
                                    <h3 className="line-clamp-2 text-sm font-medium text-white">{post.title}</h3>
                                </div>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </motion.section>
    );
}
