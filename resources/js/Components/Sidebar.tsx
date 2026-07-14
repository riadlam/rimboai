import { Link, usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import type { PageProps } from '@/types';

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
            <Link href={href} onClick={onClick} className={className}>
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

type AppLang = 'en' | 'fr' | 'ar';

const LANGUAGES: { code: AppLang; label: string; short: string }[] = [
    { code: 'en', label: 'English', short: 'EN' },
    { code: 'fr', label: 'French', short: 'FR' },
    { code: 'ar', label: 'Arabic', short: 'AR' },
];

function readSavedLang(): AppLang {
    if (typeof window === 'undefined') return 'en';
    const saved = window.localStorage.getItem('app_lang');
    if (saved === 'ar' || saved === 'fr' || saved === 'en') return saved;
    return 'en';
}

function applyLang(next: AppLang) {
    window.localStorage.setItem('app_lang', next);
    document.documentElement.lang = next;
    document.documentElement.dir = next === 'ar' ? 'rtl' : 'ltr';
}

const createItems = [
    {
        href: '/lab?type=text-to-image',
        label: 'Image',
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
        label: 'Video',
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
        label: 'Voice',
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
        label: 'Music',
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
    const { url } = usePage<PageProps>();
    const [createOpen, setCreateOpen] = useState(true);
    const [lang, setLang] = useState<AppLang>(readSavedLang);
    const [langMenuOpen, setLangMenuOpen] = useState(false);
    const [menuPos, setMenuPos] = useState({ top: 0, left: 0 });
    const langWrapRef = useRef<HTMLDivElement>(null);

    const selectLanguage = (next: AppLang) => {
        setLang(next);
        applyLang(next);
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
        applyLang(lang);
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
            className={`fixed bottom-0 left-0 top-14 z-40 box-border flex w-[84px] min-w-[84px] shrink-0 flex-col overflow-visible border-e border-sidebar-border bg-sidebar text-sidebar-foreground transition-transform duration-300 md:top-16 lg:static lg:top-auto lg:z-40 lg:mr-4 lg:h-full lg:translate-x-0 ${
                open ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'
            }`}
        >
            {/* Home */}
            <div className="flex-shrink-0 px-2 py-3">
                <RailLink href="/" active={url === '/'} onClick={onClose} label="Home">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                        <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8" />
                        <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" />
                    </svg>
                </RailLink>
            </div>

            <Divider />

            <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto">
                {/* Create group */}
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
                        <span className="text-center text-[10px] font-medium leading-tight">Create</span>
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
                                    {createItems.map((item) => (
                                        <RailLink
                                            key={item.href}
                                            href={item.href}
                                            active={item.match(url)}
                                            onClick={onClose}
                                            label={item.label}
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

                {/* Secondary */}
                <nav className="flex flex-col gap-1 px-2 py-3">
                    <RailLink href="/trends" active={url.startsWith('/trends') || url.startsWith('/marketplace')} onClick={onClose} label="Trends">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                            <polyline points="22 7 13.5 15.5 8.5 10.5 2 17" />
                            <polyline points="16 7 22 7 22 13" />
                        </svg>
                    </RailLink>
                    <RailLink href="/innovation" active={url.startsWith('/innovation') || url.startsWith('/post/')} onClick={onClose} label="Innovation">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                            <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                            <path d="M20 3v4" />
                            <path d="M22 5h-4" />
                            <path d="M4 17v2" />
                            <path d="M5 18H3" />
                        </svg>
                    </RailLink>
                    <RailLink href="/history" active={url.startsWith('/history')} onClick={onClose} label="History">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
                            <path d="m6 14 1.5-2.9A2 2 0 0 1 9.24 10H20a2 2 0 0 1 1.94 2.5l-1.54 6a2 2 0 0 1-1.95 1.5H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.9a2 2 0 0 1 1.69.9l.81 1.2a2 2 0 0 0 1.67.9H18a2 2 0 0 1 2 2v2" />
                        </svg>
                    </RailLink>
                </nav>
            </div>

            {/* Language — pinned at bottom, same rail style as Home */}
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
                                style={{ top: menuPos.top, left: menuPos.left }}
                                className="fixed z-[200] w-[128px] -translate-y-1/2 overflow-hidden rounded-xl border border-white/10 bg-black p-1 shadow-[0_12px_32px_-8px_rgba(0,0,0,0.9)]"
                                role="menu"
                                aria-label="Language"
                            >
                                {LANGUAGES.map((item) => {
                                    const active = lang === item.code;
                                    return (
                                        <button
                                            key={item.code}
                                            type="button"
                                            role="menuitem"
                                            onClick={() => selectLanguage(item.code)}
                                            className={`flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-left text-xs font-medium transition ${
                                                active
                                                    ? 'bg-white/15 text-white'
                                                    : 'text-white/70 hover:bg-white/10 hover:text-white'
                                            }`}
                                        >
                                            <span>{item.label}</span>
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
