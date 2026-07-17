import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { usePage } from '@inertiajs/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { intlLocale, readSavedLang } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Props = {
    open: boolean;
    onClose: () => void;
};

type Currency = 'DZD' | 'USD' | 'EUR' | 'GBP';

type UiPack = {
    id: string;
    dzd: number;
    tokens: number;
    label: string;
    popular?: boolean;
    best?: boolean;
    bonus?: string;
};

const CURRENCIES: Record<Currency, { flag: string; symbol: string; rateFromDzd: number }> = {
    DZD: { flag: '🇩🇿', symbol: 'DZD', rateFromDzd: 1 },
    USD: { flag: '🇺🇸', symbol: '$', rateFromDzd: 1 / 134 },
    EUR: { flag: '🇪🇺', symbol: '€', rateFromDzd: 0.92 / 134 },
    GBP: { flag: '🇬🇧', symbol: '£', rateFromDzd: 0.79 / 134 },
};

/** Only DZD is live for SofizPay right now — keep others defined for later. */
const ENABLED_CURRENCIES: Currency[] = ['DZD'];

const PACK_BADGES: Record<string, { popular?: boolean; best?: boolean }> = {
    pro: { popular: true },
    business: { best: true },
};

function bonusLabel(tokens: number, dzd: number, baseTokens: number, baseDzd: number): string | undefined {
    if (baseDzd <= 0 || dzd <= 0) return undefined;
    const baseRate = baseTokens / baseDzd;
    const rate = tokens / dzd;
    const pct = Math.round(((rate / baseRate) - 1) * 100);
    if (pct <= 0) return undefined;
    return `+${pct}%`;
}

export default function CreditsModal({ open, onClose }: Props) {
    const { t } = useTranslation('billing');
    const { t: tc } = useTranslation('common');
    const { t: tp, i18n } = useTranslation('pricing');
    const { props } = usePage<PageProps>();
    const locale = intlLocale((i18n.language as 'en' | 'fr' | 'ar') || readSavedLang());
    const [currency] = useState<Currency>('DZD');
    const [selected, setSelected] = useState<string>('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const packs: UiPack[] = useMemo(() => {
        const rows = props.tokenPackages ?? [];
        const base = rows[0];
        return rows.map((p) => {
            const badges = PACK_BADGES[p.slug] ?? {};
            return {
                id: p.slug,
                dzd: Number(p.price_dzd),
                tokens: Number(p.tokens),
                label: p.name,
                popular: badges.popular,
                best: badges.best,
                bonus:
                    base && p.slug !== base.slug
                        ? bonusLabel(Number(p.tokens), Number(p.price_dzd), Number(base.tokens), Number(base.price_dzd))
                        : undefined,
            };
        });
    }, [props.tokenPackages]);

    useEffect(() => {
        if (!packs.length) return;
        if (!packs.some((p) => p.id === selected)) {
            setSelected(packs.find((p) => p.popular)?.id ?? packs[0].id);
        }
    }, [packs, selected]);

    const cur = CURRENCIES[currency];
    const selectedPack = useMemo(() => packs.find((p) => p.id === selected) ?? null, [packs, selected]);

    const price = (dzd: number) => {
        if (currency === 'DZD') return `${Math.round(dzd).toLocaleString(locale)} DZD`;
        const value = dzd * cur.rateFromDzd;
        return `${cur.symbol}${value % 1 < 0.05 || value % 1 > 0.95 ? Math.round(value) : value.toFixed(2)}`;
    };

    async function checkout() {
        if (!selectedPack || loading) return;

        setLoading(true);
        setError(null);

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
            const response = await fetch('/billing/sofizpay/create', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ pack: selectedPack.id }),
            });

            const data = (await response.json().catch(() => ({}))) as {
                checkout_url?: string;
                message?: string;
            };

            if (!response.ok || !data.checkout_url) {
                throw new Error(data.message || tp('notices.checkoutError'));
            }

            window.location.assign(data.checkout_url);
        } catch (e) {
            setError(e instanceof Error ? e.message : tp('notices.checkoutError'));
            setLoading(false);
        }
    }

    if (typeof document === 'undefined') return null;

    return createPortal(
        <AnimatePresence>
            {open && (
                <Dialog static open={open} onClose={onClose} className="relative z-[100]">
                    <motion.div
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        className="fixed inset-0 bg-black/70 backdrop-blur-sm"
                    />

                    <div className="fixed inset-0 flex items-end justify-center p-0 sm:items-center sm:p-4">
                        <motion.div
                            initial={{ opacity: 0, y: 40, scale: 0.98 }}
                            animate={{ opacity: 1, y: 0, scale: 1 }}
                            exit={{ opacity: 0, y: 24, scale: 0.98 }}
                            transition={{ type: 'spring', stiffness: 380, damping: 32 }}
                            className="w-full max-w-lg"
                        >
                            <DialogPanel className="flex max-h-[92dvh] flex-col overflow-hidden rounded-t-2xl border border-white/10 bg-[#121216] shadow-2xl sm:max-h-[85vh] sm:rounded-2xl">
                                <div className="shrink-0 border-b border-white/[0.07] px-4 pb-3 pt-4 sm:px-5 sm:pt-5">
                                    <div className="mx-auto mb-3 h-1 w-10 rounded-full bg-white/20 sm:hidden" />
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <DialogTitle className="text-base font-semibold text-white sm:text-lg">
                                                {t('modal.title')}
                                            </DialogTitle>
                                            <p className="mt-0.5 text-[12px] text-zinc-500 sm:text-[13px]">
                                                {t('modal.subtitle')}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={onClose}
                                            className="rounded-lg p-1.5 text-zinc-500 transition hover:bg-white/5 hover:text-white"
                                            aria-label={t('modal.close')}
                                        >
                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4 sm:px-5">
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="text-[12px] font-medium text-zinc-400">{t('modal.currency')}</span>
                                        <div className="flex items-center gap-1.5 rounded-lg border border-white/10 bg-white/[0.03] px-2.5 py-1.5 text-[12px] text-white">
                                            <span>{CURRENCIES.DZD.flag}</span>
                                            <span className="font-medium">DZD</span>
                                            {ENABLED_CURRENCIES.length === 1 && (
                                                <span className="ms-0.5 rounded bg-white/[0.06] px-1.5 py-0.5 text-[10px] font-medium text-zinc-500">
                                                    {t('modal.onlyCurrency', { defaultValue: 'Only' })}
                                                </span>
                                            )}
                                        </div>
                                    </div>

                                    <p className="text-[12px] leading-relaxed text-zinc-500 sm:text-[13px]">
                                        {t('modal.hint')}
                                    </p>

                                    {packs.length === 0 ? (
                                        <div className="rounded-xl border border-white/10 bg-white/[0.02] px-3 py-6 text-center text-[13px] text-zinc-500">
                                            {t('modal.emptyPacks')}
                                        </div>
                                    ) : (
                                        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                                            {packs.map((pack) => {
                                                const active = selected === pack.id;
                                                return (
                                                    <button
                                                        key={pack.id}
                                                        type="button"
                                                        onClick={() => setSelected(pack.id)}
                                                        className={`relative flex min-h-[92px] flex-col items-center justify-center gap-0.5 rounded-xl border px-2 py-3 text-center transition sm:min-h-0 ${
                                                            active
                                                                ? 'border-[#FF5733] bg-[rgba(255,87,51,0.08)] shadow-[0_0_0_1px_rgba(255,87,51,0.55)]'
                                                                : 'border-white/10 bg-white/[0.02] hover:border-white/20 hover:bg-white/[0.04]'
                                                        }`}
                                                    >
                                                        {pack.popular && (
                                                            <span className="absolute -top-2 rounded-full bg-[#FF5733] px-1.5 py-px text-[8px] font-bold uppercase tracking-wide text-white">
                                                                {t('modal.popular')}
                                                            </span>
                                                        )}
                                                        {pack.best && (
                                                            <span className="absolute -top-2 rounded-full bg-amber-400 px-1.5 py-px text-[8px] font-bold uppercase tracking-wide text-black">
                                                                {t('modal.bestValue')}
                                                            </span>
                                                        )}
                                                        <span className="text-[10px] font-medium uppercase tracking-wide text-zinc-500">{pack.label}</span>
                                                        <span className="text-sm font-bold text-white sm:text-base">{price(pack.dzd)}</span>
                                                        {pack.bonus && (
                                                            <span className="text-[10px] font-semibold text-emerald-400">{pack.bonus}</span>
                                                        )}
                                                        <span className="mt-0.5 text-[11px] text-amber-300/90">
                                                            {pack.tokens.toLocaleString(locale)} {tc('tokens')}
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    )}

                                    <div className="flex items-center gap-2 rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5 text-[11px] leading-relaxed text-zinc-400 sm:text-[12px]">
                                        <svg className="h-4 w-4 shrink-0 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.5 11 14.5 15.5 10M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3Z" />
                                        </svg>
                                        {t('modal.secureNote')}
                                    </div>

                                    {error && (
                                        <div className="rounded-xl border border-rose-400/25 bg-rose-500/10 px-3 py-2.5 text-[12px] leading-relaxed text-rose-200">
                                            {error}
                                        </div>
                                    )}
                                </div>

                                <div className="shrink-0 border-t border-white/[0.07] bg-[#0e0e11] px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 sm:px-5 sm:pb-5">
                                    {selectedPack && currency !== 'DZD' && (
                                        <p className="mb-2 text-center text-[11px] text-zinc-500">
                                            {t('modal.chargeDzd', { amount: selectedPack.dzd.toLocaleString(locale) })}
                                        </p>
                                    )}
                                    <button
                                        type="button"
                                        onClick={checkout}
                                        disabled={!selectedPack || loading}
                                        className="group relative flex h-11 w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-b from-[#FF6A45] to-[#E24216] text-[14px] font-semibold text-white shadow-[0_12px_28px_-12px_rgba(255,87,51,0.9)] transition hover:brightness-110 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 group-hover:translate-x-full" />
                                        {loading ? (
                                            <>
                                                <svg className="relative h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle className="opacity-25" cx="12" cy="12" r="9" stroke="currentColor" strokeWidth="3" />
                                                    <path className="opacity-80" fill="currentColor" d="M12 3a9 9 0 0 1 9 9h-3a6 6 0 0 0-6-6V3Z" />
                                                </svg>
                                                <span className="relative">{t('modal.starting')}</span>
                                            </>
                                        ) : (
                                            <>
                                                <svg className="relative h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.9">
                                                    <rect width="20" height="14" x="2" y="5" rx="2" />
                                                    <path strokeLinecap="round" d="M2 10h20" />
                                                </svg>
                                                <span className="relative truncate">
                                                    {selectedPack
                                                        ? t('modal.payCta', {
                                                              amount: selectedPack.dzd.toLocaleString(locale),
                                                              tokens: selectedPack.tokens.toLocaleString(locale),
                                                          })
                                                        : t('modal.selectPack')}
                                                </span>
                                            </>
                                        )}
                                    </button>
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
