import { Link, usePage } from '@inertiajs/react';
import { createPortal } from 'react-dom';
import type { ReactNode } from 'react';
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

type TabProps = {
    href: string;
    label: string;
    active: boolean;
    children: ReactNode;
};

function NavTab({ href, label, active, children }: TabProps) {
    return (
        <Link
            href={href}
            prefetch
            cacheFor="30s"
            aria-current={active ? 'page' : undefined}
            className={`flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 px-1 py-1 transition-colors active:scale-[0.96] ${
                active ? 'text-[#FF5733]' : 'text-zinc-500 hover:text-zinc-300'
            }`}
        >
            <span className={`flex h-6 w-6 items-center justify-center ${active ? 'scale-105' : ''}`}>{children}</span>
            <span className="max-w-full truncate text-[10px] font-medium leading-tight">{label}</span>
        </Link>
    );
}

/** Returns true when the bottom bar should be shown (logged-in, not Lab). Visibility for md+ is CSS. */
export function shouldShowMobileBottomNav(user: unknown, url: string): boolean {
    if (!user) return false;
    const path = pathOf(url);
    return !path.startsWith('/lab');
}

export default function MobileBottomNav() {
    const { t } = useTranslation('nav');
    const { url, props } = usePage<PageProps>();
    const user = props.auth.user;

    if (!shouldShowMobileBottomNav(user, url) || typeof document === 'undefined') {
        return null;
    }

    const path = pathOf(url);
    const homeActive = path === '/';
    const trendsActive = path.startsWith('/trends') || path.startsWith('/marketplace');
    const toolsActive = path.startsWith('/tools');
    const historyActive = path.startsWith('/history');

    return createPortal(
        <nav
            aria-label="Primary"
            className="pointer-events-none fixed inset-x-0 bottom-0 z-40 md:hidden"
        >
            <div className="pointer-events-auto border-t border-white/[0.07] bg-[#0a0a0c]/95 pb-[max(0.5rem,env(safe-area-inset-bottom))] backdrop-blur-xl">
                <div className="relative flex h-14 items-end justify-between px-1">
                    <NavTab href="/" label={t('home')} active={homeActive}>
                        <HomeIcon className="h-[20px] w-[20px]" />
                    </NavTab>

                    <NavTab href="/trends" label={t('trends')} active={trendsActive}>
                        <TrendsIcon className="h-[20px] w-[20px]" />
                    </NavTab>

                    {/* Center Create FAB */}
                    <div className="relative flex w-[72px] shrink-0 flex-col items-center justify-end pb-1">
                        <Link
                            href="/lab?type=text-to-video"
                            prefetch
                            cacheFor="30s"
                            aria-label={t('create')}
                            className="absolute bottom-[22px] flex h-[52px] w-[52px] items-center justify-center rounded-full bg-gradient-to-b from-[#FF6A45] to-[#E24216] text-white shadow-[0_12px_28px_-10px_rgba(255,87,51,0.95)] ring-4 ring-[#0a0a0c] transition active:scale-95"
                        >
                            <CreateSparkIcon className="h-6 w-6" />
                        </Link>
                        <span className="pointer-events-none text-[10px] font-medium leading-tight text-zinc-400">
                            {t('create')}
                        </span>
                    </div>

                    <NavTab href="/tools" label={t('tools')} active={toolsActive}>
                        <ToolsIcon className="h-[20px] w-[20px]" />
                    </NavTab>

                    <NavTab href="/history" label={t('history')} active={historyActive}>
                        <HistoryIcon className="h-[20px] w-[20px]" />
                    </NavTab>
                </div>
            </div>
        </nav>,
        document.body,
    );
}
