import { Link, router, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { createPortal } from 'react-dom';
import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { PageProps } from '@/types';

function pathOf(url: string): string {
    const q = url.indexOf('?');
    const h = url.indexOf('#');
    let end = url.length;
    if (q >= 0) end = Math.min(end, q);
    if (h >= 0) end = Math.min(end, h);
    const path = url.slice(0, end);
    return path === '' ? '/' : path;
}

function HomeIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
            <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
        </svg>
    );
}

function TrendsIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17" />
            <polyline points="16 7 22 7 22 13" />
        </svg>
    );
}

function ToolsIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
        </svg>
    );
}

function HistoryIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
            <path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2" />
        </svg>
    );
}

function CreateSparkIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" className={className}>
            <path d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13l0-8Z" />
        </svg>
    );
}

function VideoIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
            <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.934a.5.5 0 0 0-.752-.432L16 10.5" />
            <rect x="2" y="6" width="14" height="12" rx="2" />
        </svg>
    );
}

function ImageIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
            <rect width="18" height="18" x="3" y="3" rx="2" />
            <circle cx="9" cy="9" r="2" />
            <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
        </svg>
    );
}

function VoiceIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
            <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
            <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
            <line x1="12" x2="12" y1="19" y2="22" />
        </svg>
    );
}

function MusicIcon({ className }: { className?: string }) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={className}>
            <path d="M9 18V5l12-2v13" />
            <circle cx="6" cy="18" r="3" />
            <circle cx="18" cy="16" r="3" />
        </svg>
    );
}

const CREATE_LABS = [
    {
        id: 'video',
        href: '/lab?type=text-to-video',
        labelKey: 'video' as const,
        accent: 'from-[#FF6A45] to-[#E24216]',
        glow: 'rgba(255,87,51,0.55)',
        icon: VideoIcon,
        /** Arc position relative to FAB center (px) */
        x: -78,
        y: -86,
    },
    {
        id: 'image',
        href: '/lab?type=text-to-image',
        labelKey: 'image' as const,
        accent: 'from-[#a78bfa] to-[#6d28d9]',
        glow: 'rgba(139,92,246,0.5)',
        icon: ImageIcon,
        x: -28,
        y: -118,
    },
    {
        id: 'voice',
        href: '/lab?type=text-to-voice',
        labelKey: 'voice' as const,
        accent: 'from-[#22d3ee] to-[#0e7490]',
        glow: 'rgba(6,182,212,0.5)',
        icon: VoiceIcon,
        x: 28,
        y: -118,
    },
    {
        id: 'music',
        href: '/lab?type=text-to-music',
        labelKey: 'music' as const,
        accent: 'from-[#fbbf24] to-[#b45309]',
        glow: 'rgba(245,158,11,0.5)',
        icon: MusicIcon,
        x: 78,
        y: -86,
    },
] as const;

type TabProps = {
    href: string;
    label: string;
    active: boolean;
    onNavigate?: () => void;
    children: ReactNode;
};

function NavTab({ href, label, active, onNavigate, children }: TabProps) {
    return (
        <Link
            href={href}
            prefetch
            cacheFor="30s"
            aria-current={active ? 'page' : undefined}
            onClick={onNavigate}
            className={`flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 py-1 transition-colors active:scale-[0.96] ${
                active ? 'text-[#FF5733]' : 'text-zinc-500 hover:text-zinc-300'
            }`}
        >
            <span className={`flex h-6 w-6 items-center justify-center ${active ? 'scale-105' : ''}`}>{children}</span>
            <span className="max-w-full truncate text-[10px] font-medium leading-tight">{label}</span>
        </Link>
    );
}

/** Logged-in phone bottom nav — includes Lab routes. md+ hidden via CSS. */
export function shouldShowMobileBottomNav(user: unknown): boolean {
    return Boolean(user);
}

export default function MobileBottomNav() {
    const { t } = useTranslation('nav');
    const { url, props } = usePage<PageProps>();
    const user = props.auth.user;
    const [createOpen, setCreateOpen] = useState(false);

    useEffect(() => {
        setCreateOpen(false);
    }, [url]);

    useEffect(() => {
        if (!createOpen) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setCreateOpen(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [createOpen]);

    if (!shouldShowMobileBottomNav(user) || typeof document === 'undefined') {
        return null;
    }

    const path = pathOf(url);
    const query = url.includes('?') ? url.slice(url.indexOf('?') + 1) : '';
    const params = new URLSearchParams(query);
    const labType = params.get('type') ?? '';

    const homeActive = path === '/';
    const trendsActive = path.startsWith('/trends') || path.startsWith('/marketplace');
    const toolsActive = path.startsWith('/tools');
    const historyActive = path.startsWith('/history');
    const onLab = path.startsWith('/lab');

    const goLab = (href: string) => {
        setCreateOpen(false);
        router.visit(href);
    };

    return createPortal(
        <>
            <AnimatePresence>
                {createOpen && (
                    <motion.button
                        type="button"
                        aria-label={t('create')}
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.2 }}
                        className="fixed inset-0 z-[45] bg-black/55 backdrop-blur-[2px] md:hidden"
                        onClick={() => setCreateOpen(false)}
                    />
                )}
            </AnimatePresence>

            <nav aria-label="Primary" className="pointer-events-none fixed inset-x-0 bottom-0 z-50 md:hidden">
                {/* Floating create shortcuts — anchored above FAB */}
                <AnimatePresence>
                    {createOpen && (
                        <div className="pointer-events-none absolute inset-x-0 bottom-[calc(3.5rem+max(0.5rem,env(safe-area-inset-bottom))+1.75rem)] flex justify-center">
                            <div className="relative h-0 w-0">
                                {CREATE_LABS.map((lab, i) => {
                                    const Icon = lab.icon;
                                    const active =
                                        onLab &&
                                        (lab.id === 'video'
                                            ? labType === 'text-to-video' || labType === ''
                                            : labType === `text-to-${lab.id}`);

                                    return (
                                        <motion.div
                                            key={lab.id}
                                            className="pointer-events-auto absolute"
                                            style={{ left: 0, top: 0 }}
                                            initial={{ opacity: 0, scale: 0.35, x: 0, y: 0 }}
                                            animate={{ opacity: 1, scale: 1, x: lab.x, y: lab.y }}
                                            exit={{ opacity: 0, scale: 0.35, x: 0, y: 0 }}
                                            transition={{
                                                type: 'spring',
                                                stiffness: 520,
                                                damping: 28,
                                                delay: i * 0.04,
                                            }}
                                        >
                                            <button
                                                type="button"
                                                onClick={() => goLab(lab.href)}
                                                className="flex -translate-x-1/2 -translate-y-1/2 flex-col items-center gap-1.5"
                                            >
                                                <span
                                                    className={`flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br ${lab.accent} text-white ring-2 ring-white/15 transition active:scale-95 ${
                                                        active ? 'ring-[#FF5733]/70' : ''
                                                    }`}
                                                    style={{ boxShadow: `0 14px 32px -12px ${lab.glow}` }}
                                                >
                                                    <Icon className="h-5 w-5" />
                                                </span>
                                                <span className="rounded-full border border-white/10 bg-[#101014]/92 px-2 py-0.5 text-[10px] font-semibold text-white shadow-lg backdrop-blur-md">
                                                    {t(lab.labelKey)}
                                                </span>
                                            </button>
                                        </motion.div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </AnimatePresence>

                <div className="pointer-events-auto border-t border-white/[0.07] bg-[#0a0a0c]/95 pb-[max(0.5rem,env(safe-area-inset-bottom))] backdrop-blur-xl">
                    <div className="relative flex h-14 items-end justify-between px-1">
                        <NavTab href="/" label={t('home')} active={homeActive} onNavigate={() => setCreateOpen(false)}>
                            <HomeIcon className="h-[20px] w-[20px]" />
                        </NavTab>

                        <NavTab
                            href="/trends"
                            label={t('trends')}
                            active={trendsActive}
                            onNavigate={() => setCreateOpen(false)}
                        >
                            <TrendsIcon className="h-[20px] w-[20px]" />
                        </NavTab>

                        {/* Center Create FAB */}
                        <div className="relative flex w-[72px] shrink-0 flex-col items-center justify-end pb-1">
                            <motion.button
                                type="button"
                                aria-label={t('create')}
                                aria-expanded={createOpen}
                                onClick={() => setCreateOpen((v) => !v)}
                                whileTap={{ scale: 0.92 }}
                                animate={{ rotate: createOpen ? 45 : 0 }}
                                transition={{ type: 'spring', stiffness: 420, damping: 24 }}
                                className={`absolute bottom-[22px] flex h-[52px] w-[52px] items-center justify-center rounded-full text-white ring-4 ring-[#0a0a0c] transition ${
                                    createOpen
                                        ? 'bg-white text-black shadow-[0_12px_28px_-10px_rgba(255,255,255,0.45)]'
                                        : 'bg-gradient-to-b from-[#FF6A45] to-[#E24216] shadow-[0_12px_28px_-10px_rgba(255,87,51,0.95)]'
                                }`}
                            >
                                {createOpen ? (
                                    <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4">
                                        <path strokeLinecap="round" d="M12 5v14M5 12h14" />
                                    </svg>
                                ) : (
                                    <CreateSparkIcon className="h-6 w-6" />
                                )}
                            </motion.button>
                            <span
                                className={`pointer-events-none text-[10px] font-medium leading-tight ${
                                    createOpen || onLab ? 'text-[#FF5733]' : 'text-zinc-400'
                                }`}
                            >
                                {t('create')}
                            </span>
                        </div>

                        <NavTab
                            href="/tools"
                            label={t('tools')}
                            active={toolsActive}
                            onNavigate={() => setCreateOpen(false)}
                        >
                            <ToolsIcon className="h-[20px] w-[20px]" />
                        </NavTab>

                        <NavTab
                            href="/history"
                            label={t('history')}
                            active={historyActive}
                            onNavigate={() => setCreateOpen(false)}
                        >
                            <HistoryIcon className="h-[20px] w-[20px]" />
                        </NavTab>
                    </div>
                </div>
            </nav>
        </>,
        document.body,
    );
}
