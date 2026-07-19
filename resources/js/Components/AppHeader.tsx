import { Link, router, usePage } from '@inertiajs/react';
import { Menu, MenuButton, MenuItem, MenuItems, Transition } from '@headlessui/react';
import { Fragment, useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import CreditsModal from '@/Components/CreditsModal';
import { getEcho } from '@/lib/echo';
import type { PageProps } from '@/types';

type Props = {
    onMenuClick: () => void;
};

export default function AppHeader({ onMenuClick }: Props) {
    const { t } = useTranslation('common');
    const { props } = usePage<PageProps>();
    const user = props.auth.user;
    const [creditsOpen, setCreditsOpen] = useState(false);
    const [tokens, setTokens] = useState(() => Math.max(0, user?.tokens ?? 0));
    const meterMax = Math.max(100, tokens);
    const pct = Math.min(100, Math.round((tokens / meterMax) * 100));
    const initials = user?.name?.slice(0, 2).toUpperCase() || 'U';
    const firstName = user?.name?.split(' ')[0] || t('guest');

    useEffect(() => {
        setTokens(Math.max(0, user?.tokens ?? 0));
    }, [user?.tokens]);

    useEffect(() => {
        const sync = (event: Event) => {
            const balance = (event as CustomEvent<{ balance?: number }>).detail?.balance;
            if (typeof balance === 'number' && Number.isFinite(balance)) {
                setTokens(Math.max(0, Math.floor(balance)));
            }
        };
        window.addEventListener('tokens:updated', sync);
        return () => window.removeEventListener('tokens:updated', sync);
    }, []);

    // Live balance via existing private Pusher channel (user.{id} — auth-gated).
    useEffect(() => {
        if (!user?.id) return;

        const echo = getEcho();
        if (!echo) return;

        const channelName = `user.${user.id}`;
        const channel = echo.private(channelName);
        const onTokensUpdated = (payload: { balance?: number }) => {
            if (typeof payload?.balance !== 'number' || !Number.isFinite(payload.balance)) return;
            const next = Math.max(0, Math.floor(payload.balance));
            setTokens(next);
            window.dispatchEvent(new CustomEvent('tokens:updated', { detail: { balance: next } }));
        };

        channel.listen('.tokens.updated', onTokensUpdated);

        return () => {
            channel.stopListening('.tokens.updated');
            // Do not echo.leave here — LabWorkspace may share the same private channel.
        };
    }, [user?.id]);

    return (
        <div className="fixed inset-x-0 top-0 z-50 flex-shrink-0" dir="ltr">
            <header className="relative flex h-14 flex-shrink-0 items-center border-b border-white/[0.07] bg-[#0a0a0c]/95 px-3 backdrop-blur-xl md:h-16 md:px-5 [&_button]:cursor-pointer">
                {/* Left — brand (always physical left in every language) */}
                <div className="flex items-center gap-2.5">
                    <button
                        type="button"
                        onClick={onMenuClick}
                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-white/[0.06] hover:text-white md:hidden"
                        aria-label="Toggle menu"
                    >
                        <svg className="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                            <path strokeLinecap="round" d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>

                    <Link href="/" className="group flex items-center gap-2.5">
                        <img
                            src="/storage/ai_icons/logo_with_icon_text.png"
                            alt="RIMBOAI"
                            className="h-[72px] w-auto object-contain md:h-[84px]"
                        />
                    </Link>
                </div>

                <div className="flex-1" />

                {/* Right */}
                <div className="flex items-center gap-2 md:gap-3">
                    {user ? (
                        <>
                    {/* Credits meter */}
                    <div className="hidden items-center gap-2.5 rounded-full border border-white/[0.08] bg-white/[0.03] py-1 pe-3 ps-1 sm:flex">
                        <span className="flex h-7 w-7 items-center justify-center rounded-full bg-amber-400/15 text-amber-300">
                            <svg className="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13l0-8Z" />
                            </svg>
                        </span>
                        <div className="flex flex-col justify-center gap-1">
                            <div className="flex items-center gap-1.5 leading-none">
                                <span className="text-[13px] font-semibold tabular-nums text-white">{tokens}</span>
                                <span className="text-[11px] text-zinc-500">{t('tokens')}</span>
                            </div>
                            <div className="h-1 w-24 overflow-hidden rounded-full bg-white/10">
                                <div
                                    className="h-full rounded-full bg-gradient-to-r from-amber-400 to-orange-500 transition-all duration-500"
                                    style={{ width: `${pct}%` }}
                                />
                            </div>
                        </div>
                    </div>

                    {/* Buy credits */}
                    <button
                        type="button"
                        onClick={() => setCreditsOpen(true)}
                        className="group relative inline-flex h-8 items-center gap-1.5 overflow-hidden rounded-full bg-gradient-to-b from-[#FF6A45] to-[#E24216] px-3 text-[12px] font-semibold text-white shadow-[0_6px_18px_-8px_rgba(255,87,51,0.9)] transition-transform active:scale-[0.97] md:h-9 md:px-3.5 md:text-[13px]"
                    >
                        <span className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 group-hover:translate-x-full" />
                        <svg className="relative h-3.5 w-3.5 md:h-4 md:w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <rect x="3" y="8" width="18" height="4" rx="1" />
                            <path d="M12 8v13" />
                            <path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7" />
                            <path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5" />
                        </svg>
                        <span className="relative">{t('buyTokens')}</span>
                    </button>

                    <div className="mx-0.5 hidden h-6 w-px bg-white/[0.08] md:block" />

                    {/* Account */}
                    <Menu as="div" className="relative">
                        <MenuButton className="group flex items-center gap-2 rounded-full p-0.5 pe-1 outline-none transition-colors hover:bg-white/[0.05] focus-visible:ring-2 focus-visible:ring-[#FF5733]/50 md:pe-2">
                            <span className="relative">
                                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br from-[#6366f1] via-[#a855f7] to-[#ec4899] text-[12px] font-semibold text-white ring-2 ring-white/10">
                                    {initials}
                                </span>
                                <span className="absolute -bottom-0.5 -end-0.5 h-2.5 w-2.5 rounded-full border-2 border-[#0a0a0c] bg-emerald-400" />
                            </span>
                            <svg className="hidden h-4 w-4 text-zinc-500 transition-transform group-data-[open]:rotate-180 md:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                <path strokeLinecap="round" strokeLinejoin="round" d="m6 9 6 6 6-6" />
                            </svg>
                        </MenuButton>

                        <Transition
                            as={Fragment}
                            enter="transition ease-out duration-150"
                            enterFrom="opacity-0 translate-y-1 scale-95"
                            enterTo="opacity-100 translate-y-0 scale-100"
                            leave="transition ease-in duration-100"
                            leaveFrom="opacity-100 translate-y-0 scale-100"
                            leaveTo="opacity-0 translate-y-1 scale-95"
                        >
                            <MenuItems className="absolute end-0 z-50 mt-2.5 w-64 origin-top-right overflow-hidden rounded-2xl border border-white/[0.08] bg-[#111114] shadow-[0_24px_60px_-20px_rgba(0,0,0,0.85)] focus:outline-none">
                                {/* Profile card → Settings */}
                                <MenuItem>
                                    {({ focus }) => (
                                        <Link
                                            href="/settings"
                                            className={`flex items-center gap-3 border-b border-white/[0.06] p-3.5 transition-colors ${
                                                focus ? 'bg-white/[0.04]' : ''
                                            }`}
                                        >
                                            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#6366f1] via-[#a855f7] to-[#ec4899] text-[14px] font-semibold text-white">
                                                {initials}
                                            </span>
                                            <div className="min-w-0">
                                                <p className="truncate text-[14px] font-semibold text-white">{firstName}</p>
                                                <p className="truncate text-[12px] text-zinc-500">{user?.email || t('notSignedIn')}</p>
                                            </div>
                                        </Link>
                                    )}
                                </MenuItem>

                                {/* Credit summary */}
                                <div className="border-b border-white/[0.06] px-3.5 py-3">
                                    <div className="mb-1.5 flex items-center justify-between">
                                        <span className="text-[12px] text-zinc-400">{t('tokens')}</span>
                                        <span className="text-[12px] font-medium tabular-nums text-white">
                                            {tokens}
                                        </span>
                                    </div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-white/[0.08]">
                                        <div
                                            className="h-full rounded-full bg-gradient-to-r from-amber-400 to-orange-500"
                                            style={{ width: `${pct}%` }}
                                        />
                                    </div>
                                </div>

                                <div className="p-1.5">
                                    <MenuItem>
                                        {({ focus }) => (
                                            <button
                                                type="button"
                                                onClick={() => setCreditsOpen(true)}
                                                className={`flex w-full cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2 text-left text-[13px] transition-colors ${
                                                    focus ? 'bg-[#FF5733]/15 text-[#ffb39f]' : 'text-[#ff8f73]'
                                                }`}
                                            >
                                                <IconSpark />
                                                {t('buyTokens')}
                                            </button>
                                        )}
                                    </MenuItem>
                                    <AccountRowLink href="/history" icon={<IconClock />} label={t('history')} />
                                    <AccountRowLink href="/billing/history" icon={<IconBilling />} label={t('billing')} />
                                    <AccountRowLink href="/settings" icon={<IconGear />} label={t('settings')} />
                                </div>

                                {user && (
                                    <div className="border-t border-white/[0.06] p-1.5">
                                        <MenuItem>
                                            {({ focus }) => (
                                                <button
                                                    type="button"
                                                    onClick={() => router.post('/logout')}
                                                    className={`flex w-full cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2 text-left text-[13px] transition-colors ${
                                                        focus ? 'bg-rose-500/10 text-rose-300' : 'text-zinc-400'
                                                    }`}
                                                >
                                                    <IconSignOut />
                                                    {t('signOut')}
                                                </button>
                                            )}
                                        </MenuItem>
                                    </div>
                                )}
                            </MenuItems>
                        </Transition>
                    </Menu>
                        </>
                    ) : (
                        <>
                            <Link
                                href="/pricing"
                                className="inline-flex h-7 items-center px-2 text-[11.5px] font-semibold text-white/65 transition hover:text-white sm:px-2.5 sm:text-[12.5px]"
                            >
                                {t('pricing')}
                            </Link>
                            <Link
                                href="/?login"
                                className="hidden h-7 items-center rounded-[5px] border border-white/15 bg-white/[0.04] px-2.5 text-[11.5px] font-semibold text-white transition hover:border-white/25 hover:bg-white/[0.08] sm:inline-flex sm:px-3.5 sm:text-[12.5px]"
                            >
                                {t('signIn')}
                            </Link>
                            <Link
                                href="/register"
                                className="inline-flex h-7 items-center rounded-[5px] bg-gradient-to-b from-[#FF6A45] to-[#E24216] px-2.5 text-[11.5px] font-semibold text-white shadow-[0_6px_18px_-10px_rgba(255,87,51,0.95)] transition hover:brightness-110 sm:px-3.5 sm:text-[12.5px]"
                            >
                                {t('signUp')}
                            </Link>                        </>
                    )}
                </div>
            </header>

            <CreditsModal open={creditsOpen} onClose={() => setCreditsOpen(false)} />
        </div>
    );
}

function AccountRowLink({ href, icon, label }: { href: string; icon: ReactNode; label: string }) {
    return (
        <MenuItem>
            {({ focus }) => (
                <Link
                    href={href}
                    className={`flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left text-[13px] transition-colors ${
                        focus ? 'bg-white/[0.06] text-white' : 'text-zinc-300'
                    }`}
                >
                    {icon}
                    {label}
                </Link>
            )}
        </MenuItem>
    );
}

function IconSpark() {
    return (
        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
            <path d="M13 2 4.5 13.5H11l-1 8.5L19.5 10H13l0-8Z" />
        </svg>
    );
}

function IconClock() {
    return (
        <svg className="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
            <circle cx="12" cy="12" r="8.5" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 7.5V12l3 1.8" />
        </svg>
    );
}

function IconBilling() {
    return (
        <svg className="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
            <path d="M6 2h12a2 2 0 0 1 2 2v18l-3-2-3 2-3-2-3 2-3-2-3 2V4a2 2 0 0 1 2-2Z" />
            <path d="M16 8h-6M16 12h-6M13 16h-3" />
        </svg>
    );
}

function IconGear() {
    return (
        <svg className="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.6">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z" />
        </svg>
    );
}

function IconSignOut() {
    return (
        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
            <path strokeLinecap="round" strokeLinejoin="round" d="M10 4.5H6.5A2 2 0 0 0 4.5 6.5v11a2 2 0 0 0 2 2H10M14 8.5 18.5 12 14 15.5M18.5 12H9" />
        </svg>
    );
}
