import { Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import type { PageProps } from '@/types';
import { applyLanguage, LANGUAGES, readSavedLang, type AppLang } from '@/lib/i18n';

type Props = {
    open: boolean;
    onClose: () => void;
};

function Divider() {
    return <div role="none" className="mx-3 h-px w-auto shrink-0 bg-sidebar-border" />;
}

function RailLink({
    href,
    active,
    onClick,
    children,
    label,
}: {
    href?: string;
    active?: boolean;
    onClick?: () => void;
    children: ReactNode;
    label: string;
}) {
    const className = `flex w-full cursor-pointer flex-col items-center gap-1.5 rounded-lg px-2 py-3 transition-colors ${
        active
            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
            : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
    }`;

    const content = (
        <>
            {children}
            <span className="text-center text-[10px] font-medium leading-tight">{label}</span>
        </>
    );

    if (href) {
        return (
            <Link href={href} prefetch cacheFor="30s" onClick={onClick} className={className}>
                {content}
            </Link>
        );
    }

    return (
        <button type="button" onClick={onClick} className={className}>
            {content}
        </button>
    );
}

const createItemDefs = [
    {
        href: '/lab?type=text-to-image',
        labelKey: 'image' as const,
        match: (url: string) => url.startsWith('/lab') && url.includes('text-to-image'),
        icon: (
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                <circle cx="9" cy="9" r="2" />
                <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
            </svg>
        ),
    },
    {
        href: '/lab?type=text-to-video',
        labelKey: 'video' as const,
        match: (url: string) => {
            if (!url.startsWith('/lab')) return false;
            if (
                url.includes('text-to-image') ||
                url.includes('text-to-voice') ||
                url.includes('image-to-video') ||
                url.includes('text-to-music') ||
                url.includes('text-to-sound')
            ) {
                return false;
            }
            return url.includes('text-to-video') || !url.includes('type=');
        },
        icon: (
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                <path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5" />
                <rect x="2" y="6" width="14" height="12" rx="2" />
            </svg>
        ),
    },
    {
        href: '/lab?type=text-to-voice',
        labelKey: 'voice' as const,
        match: (url: string) => url.startsWith('/lab') && (url.includes('text-to-voice') || url.includes('image-to-video')),
        icon: (
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                <path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z" />
                <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                <line x1="12" x2="12" y1="19" y2="22" />
            </svg>
        ),
    },
    {
        href: '/lab?type=text-to-music',
        labelKey: 'music' as const,
        match: (url: string) => url.startsWith('/lab') && (url.includes('text-to-music') || url.includes('text-to-sound')),
        icon: (
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                <path d="M9 18V5l12-2v13" />
                <circle cx="6" cy="18" r="3" />
                <circle cx="18" cy="16" r="3" />
            </svg>
        ),
    },
];

export default function Sidebar({ open, onClose }: Props) {
    const { t } = useTranslation('nav');
    const { t: tc } = useTranslation('common');
    const { url, props } = usePage<PageProps>();
    const user = props.auth.user;
    const [createOpen, setCreateOpen] = useState(true);
    const [lang, setLang] = useState<AppLang>(readSavedLang);
    const [langMenuOpen, setLangMenuOpen] = useState(false);
    const [menuPos, setMenuPos] = useState({ top: 0, left: 0 });
    const langWrapRef = useRef<HTMLDivElement>(null);

    const selectLanguage = (next: AppLang) => {
        setLang(next);
        applyLanguage(next);
        setLangMenuOpen(false);
    };

    const updateMenuPos = () => {
        const el = langWrapRef.current;
        if (!el) return;
        const rect = el.getBoundingClientRect();
        setMenuPos({
            top: rect.top + rect.height / 2,
            left: rect.right + 4,
        });
    };

    useEffect(() => {
        applyLanguage(lang);
    }, [lang]);

    useEffect(() => {
        if (!langMenuOpen) return;
        updateMenuPos();
        const onPointerDown = (e: MouseEvent) => {
            const target = e.target as Node;
            if (langWrapRef.current?.contains(target)) return;
            const menu = document.getElementById('sidebar-lang-menu');
            if (menu?.contains(target)) return;
            setLangMenuOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setLangMenuOpen(false);
        };
        window.addEventListener('resize', updateMenuPos);
        window.addEventListener('scroll', updateMenuPos, true);
        document.addEventListener('mousedown', onPointerDown);
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('resize', updateMenuPos);
            window.removeEventListener('scroll', updateMenuPos, true);
            document.removeEventListener('mousedown', onPointerDown);
            window.removeEventListener('keydown', onKey);
        };
    }, [langMenuOpen]);

    return (
        <aside
            data-slot="app-sidebar"
            className={`fixed bottom-0 left-0 top-14 z-40 box-border flex w-[84px] min-w-[84px] shrink-0 flex-col overflow-visible border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-transform duration-300 md:top-16 lg:static lg:top-auto lg:z-40 lg:mr-4 lg:h-full lg:translate-x-0 ${
                open ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'
            }`}
        >
            <div className="flex-shrink-0 px-2 py-3">
                <RailLink href="/" active={url === '/'} onClick={onClose} label={t('home')}>
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                        <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                        <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                    </svg>
                </RailLink>
            </div>

            <Divider />

            <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
                <div className="px-2 py-2">
                    <button
                        type="button"
                        onClick={() => setCreateOpen((v) => !v)}
                        className="flex w-full cursor-pointer flex-col items-center gap-1 rounded-lg px-2 py-2 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            width="24"
                            height="24"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            className={`h-4 w-4 transition-transform duration-200 ${createOpen ? 'rotate-0' : '-rotate-90'}`}
                        >
                            <path d="m6 9 6 6 6-6" />
                        </svg>
                        <span className="text-center text-[10px] font-medium leading-tight">{t('create')}</span>
                    </button>

                    <AnimatePresence initial={false}>
                        {createOpen && (
                            <motion.div
                                initial={{ height: 0, opacity: 0 }}
                                animate={{ height: 'auto', opacity: 1 }}
                                exit={{ height: 0, opacity: 0 }}
                                transition={{ duration: 0.2 }}
                                className="mt-1 overflow-hidden"
                            >
                                <nav className="flex flex-col gap-1">
                                    {createItemDefs.map((item) => (
                                        <RailLink
                                            key={item.href}
                                            href={item.href}
                                            active={item.match(url)}
                                            onClick={onClose}
                                            label={t(item.labelKey)}
                                        >
                                            {item.icon}
                                        </RailLink>
                                    ))}
                                </nav>
                            </motion.div>
                        )}
                    </AnimatePresence>
                </div>

                <Divider />

                <nav className="flex flex-col gap-1 px-2 py-3">
                    <RailLink href="/pricing" active={url.startsWith('/pricing')} onClick={onClose} label={t('pricing')}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                            <circle cx="8" cy="8" r="6" />
                            <path d="M18.09 10.37A6 6 0 1 1 10.34 18" />
                            <path d="M7 6h1v4" />
                            <path d="m16.71 13.88.7.71-2.82 2.82" />
                        </svg>
                    </RailLink>
                    <RailLink href="/tools" active={url.startsWith('/tools')} onClick={onClose} label={t('tools')}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
                        </svg>
                    </RailLink>
                    <RailLink href="/trends" active={url.startsWith('/trends') || url.startsWith('/marketplace')} onClick={onClose} label={t('trends')}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17" />
                            <polyline points="16 7 22 7 22 13" />
                        </svg>
                    </RailLink>
                    <RailLink href="/innovation" active={url.startsWith('/innovation') || url.startsWith('/post/')} onClick={onClose} label={t('innovation')}>
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                            <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                            <path d="M20 3v4" />
                            <path d="M22 5h-4" />
                            <path d="M4 17v2" />
                            <path d="M5 18H3" />
                        </svg>
                    </RailLink>
                    {user && (
                        <RailLink href="/history" active={url.startsWith('/history')} onClick={onClose} label={t('history')}>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                                <path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2" />
                            </svg>
                        </RailLink>
                    )}
                </nav>
            </div>

            <Divider />
            <div className="relative flex-shrink-0 px-2 py-3" ref={langWrapRef}>
                <RailLink
                    onClick={() => {
                        updateMenuPos();
                        setLangMenuOpen((v) => !v);
                    }}
                    active={langMenuOpen}
                    label={LANGUAGES.find((l) => l.code === lang)?.short ?? 'EN'}
                >
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                        <circle cx="12" cy="12" r="10" />
                        <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20" />
                        <path d="M2 12h20" />
                    </svg>
                </RailLink>
            </div>

            {typeof document !== 'undefined' &&
                createPortal(
                    <AnimatePresence>
                        {langMenuOpen && (
                            <motion.div
                                id="sidebar-lang-menu"
                                initial={{ opacity: 0, x: -8, scale: 0.92 }}
                                animate={{ opacity: 1, x: 0, scale: 1 }}
                                exit={{ opacity: 0, x: -8, scale: 0.92 }}
                                transition={{ type: 'spring', stiffness: 520, damping: 28 }}
                                style={{
                                    top: menuPos.top,
                                    left: menuPos.left,
                                }}
                                className="fixed z-[200] w-[128px] -translate-y-1/2 overflow-hidden rounded-xl border border-white/10 bg-black p-1 shadow-[0_12px_32px_-8px_rgba(0,0,0,0.9)]"
                                role="menu"
                                aria-label={tc('language')}
                            >
                                {LANGUAGES.map((item) => {
                                    const active = lang === item.code;
                                    const label =
                                        item.code === 'en'
                                            ? t('english')
                                            : item.code === 'fr'
                                              ? t('french')
                                              : t('arabic');
                                    return (
                                        <button
                                            key={item.code}
                                            type="button"
                                            role="menuitem"
                                            onClick={() => selectLanguage(item.code)}
                                            className={`flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-start text-xs font-medium transition ${
                                                active
                                                    ? 'bg-white/15 text-white'
                                                    : 'text-white/70 hover:bg-white/10 hover:text-white'
                                            }`}
                                        >
                                            <span>{label}</span>
                                            <span className="text-[10px] text-white/40">{item.short}</span>
                                        </button>
                                    );
                                })}
                            </motion.div>
                        )}
                    </AnimatePresence>,
                    document.body,
                )}
        </aside>
    );
}
