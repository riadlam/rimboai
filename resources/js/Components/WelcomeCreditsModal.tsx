import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import type { PageProps } from '@/types';

const LOGO_SRC = '/storage/ai_icons/logo_with_icon_text.png';

const LAB_TYPES = [
    { id: 'video', href: '/lab?type=text-to-video', accent: 'from-[#FF6A45] to-[#E24216]' },
    { id: 'image', href: '/lab?type=text-to-image', accent: 'from-[#a78bfa] to-[#6d28d9]' },
    { id: 'voice', href: '/lab?type=text-to-voice', accent: 'from-[#22d3ee] to-[#0e7490]' },
    { id: 'music', href: '/lab?type=text-to-music', accent: 'from-[#fbbf24] to-[#b45309]' },
] as const;

const SPRINKLES = Array.from({ length: 24 }, (_, i) => ({
    left: 6 + ((i * 41) % 88),
    top: 8 + ((i * 29) % 82),
    size: 3 + (i % 4),
    delay: (i % 8) * 0.22,
    duration: 2.2 + (i % 5) * 0.45,
    drift: (i % 2 === 0 ? 1 : -1) * (6 + (i % 7)),
    color: ['#FF5733', '#fbbf24', '#a78bfa', '#22d3ee', '#34d399', '#f472b6', '#fde047', '#fb7185'][i % 8],
    shape: i % 3,
}));

const CONFETTI = Array.from({ length: 16 }, (_, i) => ({
    angle: (i / 16) * 360,
    distance: 52 + (i % 4) * 14,
    size: 4 + (i % 3),
    delay: 0.05 + (i % 6) * 0.04,
    color: ['#FF5733', '#fbbf24', '#a78bfa', '#22d3ee', '#34d399', '#f472b6'][i % 6],
}));

function LabTypeIcon({ id }: { id: string }) {
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

function ModalSprinkles() {
    return (
        <div className="pointer-events-none absolute inset-0 overflow-hidden">
            {SPRINKLES.map((s, i) => (
                <motion.span
                    key={i}
                    className="absolute"
                    style={{
                        left: `${s.left}%`,
                        top: `${s.top}%`,
                        width: s.size,
                        height: s.size,
                        backgroundColor: s.color,
                        borderRadius: s.shape === 0 ? '9999px' : s.shape === 1 ? '2px' : '1px',
                        transform: s.shape === 2 ? 'rotate(45deg)' : undefined,
                        boxShadow: `0 0 ${s.size * 2}px ${s.color}55`,
                    }}
                    initial={{ opacity: 0, scale: 0.4 }}
                    animate={{
                        opacity: [0, 0.95, 0.35, 0.9, 0],
                        scale: [0.4, 1.1, 0.85, 1.05, 0.5],
                        y: [0, -10, -4, -14, 0],
                        x: [0, s.drift, 0, -s.drift * 0.5, 0],
                    }}
                    transition={{
                        duration: s.duration,
                        delay: s.delay,
                        repeat: Infinity,
                        ease: 'easeInOut',
                    }}
                />
            ))}
        </div>
    );
}

function TokenConfetti() {
    return (
        <div className="pointer-events-none absolute inset-0">
            {CONFETTI.map((c, i) => {
                const rad = (c.angle * Math.PI) / 180;
                const x = Math.cos(rad) * c.distance;
                const y = Math.sin(rad) * c.distance;

                return (
                    <motion.span
                        key={i}
                        className="absolute left-1/2 top-1/2 rounded-sm"
                        style={{
                            width: c.size,
                            height: c.size,
                            marginLeft: -c.size / 2,
                            marginTop: -c.size / 2,
                            backgroundColor: c.color,
                            boxShadow: `0 0 8px ${c.color}88`,
                        }}
                        initial={{ opacity: 0, scale: 0, x: 0, y: 0 }}
                        animate={{ opacity: [0, 1, 0.8, 0], scale: [0, 1.2, 1, 0.6], x, y }}
                        transition={{ duration: 1.1, delay: c.delay, ease: [0.22, 1, 0.36, 1] }}
                    />
                );
            })}
        </div>
    );
}

export default function WelcomeCreditsModal() {
    const { t } = useTranslation('welcome');
    const { props } = usePage<PageProps>();
    const welcome = props.flash?.welcome;
    const [open, setOpen] = useState(() => Boolean(welcome && typeof welcome.tokens === 'number'));

    const count = welcome?.tokens ?? props.creditsConfig?.starter_tokens ?? 25;
    const firstName = useMemo(() => {
        const name = props.auth.user?.name?.trim() ?? '';
        if (!name) return '';
        return name.split(/\s+/)[0] ?? '';
    }, [props.auth.user?.name]);

    useEffect(() => {
        if (!open) return;
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.body.style.overflow = prev;
        };
    }, [open]);

    if (!welcome || typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <AnimatePresence>
            {open && (
                <Dialog static open={open} onClose={() => setOpen(false)} className="relative z-[100]">
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.28 }}
                        className="fixed inset-0 bg-black/75 backdrop-blur-sm"
                        aria-hidden="true"
                    />

                    <div className="fixed inset-0 flex items-end justify-center p-0 sm:items-center sm:p-4">
                        <motion.div
                            initial={{ opacity: 0, y: 48, scale: 0.98 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 32, scale: 0.98 }}
                            transition={{ type: 'spring', stiffness: 380, damping: 32 }}
                            className="w-full max-w-[440px]"
                        >
                            <DialogPanel className="relative flex max-h-[92dvh] flex-col overflow-hidden rounded-t-[26px] border border-white/10 bg-[#101014] shadow-[0_40px_120px_-28px_rgba(0,0,0,0.95)] sm:max-h-[90vh] sm:rounded-[26px]">
                                <div aria-hidden className="pointer-events-none absolute inset-0 overflow-hidden">
                                    <div className="absolute -left-20 -top-16 h-56 w-56 rounded-full bg-[#FF5733]/25 blur-[90px]" />
                                    <div className="absolute -bottom-20 -right-16 h-56 w-56 rounded-full bg-violet-600/20 blur-[100px]" />
                                    <ModalSprinkles />
                                </div>

                                <div className="mx-auto mt-3 h-1 w-10 shrink-0 rounded-full bg-white/20 sm:hidden" />

                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    aria-label={t('close')}
                                    className="absolute end-3 top-3 z-10 flex h-9 w-9 items-center justify-center rounded-full text-white/40 transition hover:bg-white/[0.06] hover:text-white"
                                >
                                    <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <path strokeLinecap="round" d="M6 6l12 12M18 6 6 18" />
                                    </svg>
                                </button>

                                <div className="relative min-h-0 flex-1 overflow-y-auto px-6 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-6 sm:px-8 sm:pb-8 sm:pt-9">
                                    <div className="text-center">
                                        <motion.div
                                            initial={{ opacity: 0, y: 8 }}
                                            animate={{ opacity: 1, y: 0 }}
                                            transition={{ delay: 0.1, duration: 0.4 }}
                                            className="mx-auto mb-3 flex justify-center"
                                        >
                                            <img
                                                src={LOGO_SRC}
                                                alt="RIMBOAI"
                                                className="h-14 w-auto max-w-[220px] object-contain sm:h-16"
                                            />
                                        </motion.div>
                                        <p className="font-[family-name:Outfit,sans-serif] text-[12px] font-semibold tracking-[0.22em] text-[#FF8A65]">
                                            {t('eyebrow')}
                                        </p>
                                        <DialogTitle className="mt-2 font-[family-name:Outfit,sans-serif] text-[1.45rem] font-bold tracking-tight text-white sm:text-[1.55rem]">
                                            {t('title', { name: firstName ? t('titleName', { name: firstName }) : '' })}
                                        </DialogTitle>
                                    </div>

                                    <div className="relative mt-5 flex justify-center">
                                        <TokenConfetti />
                                        <motion.div
                                            initial={{ opacity: 0, scale: 0.88 }}
                                            animate={{ opacity: 1, scale: 1 }}
                                            transition={{ type: 'spring', stiffness: 420, damping: 22, delay: 0.15 }}
                                            className="relative rounded-2xl border border-emerald-400/25 bg-emerald-500/10 px-7 py-4 text-center shadow-[0_18px_40px_-20px_rgba(16,185,129,0.7)]"
                                        >
                                            <motion.p
                                                initial={{ scale: 0.6, opacity: 0 }}
                                                animate={{ scale: 1, opacity: 1 }}
                                                transition={{ type: 'spring', stiffness: 500, damping: 18, delay: 0.25 }}
                                                className="font-[family-name:Outfit,sans-serif] text-4xl font-black tabular-nums text-white"
                                            >
                                                {count}
                                            </motion.p>
                                            <p className="mt-1 text-[13px] font-medium text-emerald-200/90">{t('gift')}</p>
                                        </motion.div>
                                    </div>

                                    <p className="mx-auto mt-4 max-w-[34ch] text-center text-[13px] leading-relaxed text-white/55">
                                        {t('body')}
                                    </p>

                                    <div className="mt-5 grid grid-cols-4 gap-2">
                                        {LAB_TYPES.map((item, index) => (
                                            <motion.div
                                                key={item.id}
                                                initial={{ opacity: 0, y: 12 }}
                                                animate={{ opacity: 1, y: 0 }}
                                                transition={{ delay: 0.35 + index * 0.06, duration: 0.35 }}
                                            >
                                                <Link
                                                    href={item.href}
                                                    onClick={() => setOpen(false)}
                                                    className="group flex flex-col items-center gap-1.5 rounded-xl border border-white/8 bg-white/[0.03] px-1 py-2.5 transition hover:border-white/16 hover:bg-white/[0.06]"
                                                >
                                                    <span
                                                        className={`flex h-8 w-8 items-center justify-center rounded-lg bg-gradient-to-br ${item.accent} text-white shadow-sm transition group-hover:scale-105 group-hover:shadow-md`}
                                                    >
                                                        <LabTypeIcon id={item.id} />
                                                    </span>
                                                    <span className="text-[11px] font-medium text-white/70">{t(`types.${item.id}`)}</span>
                                                </Link>
                                            </motion.div>
                                        ))}
                                    </div>

                                    <div className="mt-6 flex flex-col gap-2.5">
                                        <Link
                                            href="/lab?type=text-to-video"
                                            onClick={() => setOpen(false)}
                                            className="inline-flex h-11 items-center justify-center rounded-xl bg-gradient-to-b from-[#FF7A55] to-[#E24216] text-sm font-semibold text-white shadow-[0_14px_32px_-12px_rgba(255,87,51,0.9)] transition hover:brightness-110"
                                        >
                                            {t('cta')}
                                        </Link>
                                        <Link
                                            href="/tools"
                                            onClick={() => setOpen(false)}
                                            className="inline-flex h-11 items-center justify-center rounded-xl border border-white/12 bg-white/[0.03] text-sm font-medium text-white/80 transition hover:bg-white/[0.07] hover:text-white"
                                        >
                                            {t('exploreTools')}
                                        </Link>
                                    </div>
                                </div>
                            </DialogPanel>
                        </motion.div>
                    </div>
                </Dialog>
            )}
        </AnimatePresence>,
        document.body,
    );
}
