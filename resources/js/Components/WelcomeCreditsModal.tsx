import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import type { PageProps } from '@/types';

const LAB_TYPES = [
    { id: 'video', href: '/lab?type=text-to-video', accent: 'from-[#FF6A45] to-[#E24216]' },
    { id: 'image', href: '/lab?type=text-to-image', accent: 'from-[#a78bfa] to-[#6d28d9]' },
    { id: 'voice', href: '/lab?type=text-to-voice', accent: 'from-[#22d3ee] to-[#0e7490]' },
    { id: 'music', href: '/lab?type=text-to-music', accent: 'from-[#fbbf24] to-[#b45309]' },
] as const;

export default function WelcomeCreditsModal() {
    const { t } = useTranslation('welcome');
    const { props } = usePage<PageProps>();
    const welcome = props.flash?.welcome;
    const [open, setOpen] = useState(() => Boolean(welcome && typeof welcome.tokens === 'number'));

    const count = welcome?.tokens ?? props.creditsConfig?.starter_tokens ?? 50;
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
                                        <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-[#FF7A55] to-[#E24216] font-[family-name:Outfit,sans-serif] text-xl font-extrabold text-white shadow-[0_16px_40px_-14px_rgba(255,87,51,0.9)]">
                                            R
                                        </div>
                                        <p className="font-[family-name:Outfit,sans-serif] text-[12px] font-semibold tracking-[0.22em] text-[#FF8A65]">
                                            {t('eyebrow')}
                                        </p>
                                        <DialogTitle className="mt-2 font-[family-name:Outfit,sans-serif] text-[1.45rem] font-bold tracking-tight text-white sm:text-[1.55rem]">
                                            {t('title', { name: firstName ? t('titleName', { name: firstName }) : '' })}
                                        </DialogTitle>
                                    </div>

                                    <div className="mt-5 flex justify-center">
                                        <div className="relative rounded-2xl border border-emerald-400/25 bg-emerald-500/10 px-7 py-4 text-center shadow-[0_18px_40px_-20px_rgba(16,185,129,0.7)]">
                                            <p className="font-[family-name:Outfit,sans-serif] text-4xl font-black tabular-nums text-white">
                                                {count}
                                            </p>
                                            <p className="mt-1 text-[13px] font-medium text-emerald-200/90">{t('gift')}</p>
                                        </div>
                                    </div>

                                    <p className="mx-auto mt-4 max-w-[34ch] text-center text-[13px] leading-relaxed text-white/55">
                                        {t('body')}
                                    </p>

                                    <div className="mt-5 grid grid-cols-4 gap-2">
                                        {LAB_TYPES.map((item) => (
                                            <Link
                                                key={item.id}
                                                href={item.href}
                                                onClick={() => setOpen(false)}
                                                className="group flex flex-col items-center gap-1.5 rounded-xl border border-white/8 bg-white/[0.03] px-1 py-2.5 transition hover:border-white/16 hover:bg-white/[0.06]"
                                            >
                                                <span
                                                    className={`h-8 w-8 rounded-lg bg-gradient-to-br ${item.accent} opacity-90 shadow-sm group-hover:opacity-100`}
                                                />
                                                <span className="text-[11px] font-medium text-white/70">{t(`types.${item.id}`)}</span>
                                            </Link>
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
