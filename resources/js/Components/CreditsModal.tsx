import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';

type Props = {
    open: boolean;
    onClose: () => void;
};

type Currency = 'USD' | 'EUR' | 'GBP';

const CURRENCIES: Record<Currency, { flag: string; symbol: string; rate: number }> = {
    USD: { flag: '🇺🇸', symbol: '$', rate: 1 },
    EUR: { flag: '🇪🇺', symbol: '€', rate: 0.92 },
    GBP: { flag: '🇬🇧', symbol: '£', rate: 0.79 },
};

const PACKS = [
    { id: 'starter', usd: 15, credits: 5000, label: 'Starter' },
    { id: 'creator', usd: 40, credits: 15000, label: 'Creator', bonus: '+7%' },
    { id: 'pro', usd: 75, credits: 30000, label: 'Pro', popular: true, bonus: '+14%' },
    { id: 'studio', usd: 220, credits: 100000, label: 'Studio', best: true, bonus: '+21%' },
];

export default function CreditsModal({ open, onClose }: Props) {
    const [currency, setCurrency] = useState<Currency>('USD');
    const [currencyOpen, setCurrencyOpen] = useState(false);
    const [selected, setSelected] = useState<string>('pro');

    const cur = CURRENCIES[currency];
    const selectedPack = useMemo(() => PACKS.find((p) => p.id === selected) ?? null, [selected]);

    const price = (usd: number) => {
        const value = usd * cur.rate;
        return `${cur.symbol}${value % 1 === 0 ? value.toFixed(0) : value.toFixed(2)}`;
    };

    if (typeof document === 'undefined') return null;

    return createPortal(
        <AnimatePresence>
            {open && (
                <Dialog static open={open} onClose={onClose} className="relative z-[80]">
                    <motion.div
                        className="fixed inset-0 bg-black/70 backdrop-blur-sm"
                        initial={{ opacity: 0 }}
                        animate={{ opacity: 1 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.2 }}
                        aria-hidden="true"
                    />

                    <div className="fixed inset-0 z-[81] flex items-center justify-center p-4">
                        <motion.div
                            initial={{ opacity: 0, scale: 0.96, y: 16 }}
                            animate={{ opacity: 1, scale: 1, y: 0 }}
                            exit={{ opacity: 0, scale: 0.97, y: 8 }}
                            transition={{ duration: 0.25, ease: 'easeOut' }}
                            className="relative w-full max-w-lg"
                        >
                            <DialogPanel className="relative w-full overflow-hidden rounded-2xl border border-white/10 bg-[#0e0e11] shadow-[0_40px_90px_-30px_rgba(0,0,0,0.9)] [&_button]:cursor-pointer">
                                {/* Banner */}
                                <div className="relative overflow-hidden px-5 pb-5 pt-5">
                                    <div className="absolute inset-0 bg-gradient-to-br from-[#FF6A45] via-[#E24216] to-[#7a1f0c]" />
                                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_90%_0%,rgba(255,255,255,0.3),transparent_50%)]" />
                                    <div className="absolute inset-0 bg-gradient-to-t from-[#0e0e11] via-[#0e0e11]/35 to-transparent" />

                                    <div className="relative flex items-start justify-between gap-3">
                                        <div>
                                            <div className="flex items-center gap-2 text-white">
                                                <svg className="h-5 w-5 text-amber-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                                                    <path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z" />
                                                    <path d="M20 3v4M22 5h-4M4 17v2M5 18H3" />
                                                </svg>
                                                <DialogTitle className="font-[family-name:Outfit,sans-serif] text-xl font-bold tracking-tight">
                                                    RIMBOAI Tokens
                                                </DialogTitle>
                                            </div>
                                            <p className="mt-1 text-[13px] text-white/80">Token Top-Up</p>
                                            <p className="mt-0.5 text-[12px] text-white/65">Top up and keep creating without limits</p>
                                        </div>

                                        <button
                                            type="button"
                                            onClick={onClose}
                                            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-black/25 text-white/80 backdrop-blur transition hover:bg-black/40 hover:text-white"
                                            aria-label="Close"
                                        >
                                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                <path strokeLinecap="round" d="M18 6 6 18M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div className="space-y-4 px-5 pb-5 pt-1">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[13px] text-zinc-400">Display prices in:</span>
                                        <div className="relative">
                                            <button
                                                type="button"
                                                onClick={() => setCurrencyOpen((v) => !v)}
                                                className="flex h-9 w-28 items-center justify-between gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-2.5 text-[13px] text-zinc-200 transition hover:border-white/20"
                                            >
                                                <span className="flex items-center gap-1.5">
                                                    <span>{cur.flag}</span>
                                                    <span>{currency}</span>
                                                </span>
                                                <svg className="h-3.5 w-3.5 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="m6 9 6 6 6-6" />
                                                </svg>
                                            </button>
                                            {currencyOpen && (
                                                <div className="absolute end-0 z-10 mt-1.5 w-32 overflow-hidden rounded-lg border border-white/10 bg-[#16161a] p-1 shadow-xl">
                                                    {(Object.keys(CURRENCIES) as Currency[]).map((c) => (
                                                        <button
                                                            key={c}
                                                            type="button"
                                                            onClick={() => {
                                                                setCurrency(c);
                                                                setCurrencyOpen(false);
                                                            }}
                                                            className={`flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-[13px] transition ${
                                                                c === currency ? 'bg-white/[0.08] text-white' : 'text-zinc-300 hover:bg-white/[0.05]'
                                                            }`}
                                                        >
                                                            <span>{CURRENCIES[c].flag}</span>
                                                            <span>{c}</span>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <p className="text-[13px] text-zinc-500">
                                        Purchase a credit pack and receive it instantly after checkout.
                                    </p>

                                    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                                        {PACKS.map((pack) => {
                                            const active = selected === pack.id;
                                            return (
                                                <button
                                                    key={pack.id}
                                                    type="button"
                                                    onClick={() => setSelected(pack.id)}
                                                    className={`relative flex flex-col items-center justify-center gap-0.5 rounded-xl border px-2 py-3 text-center transition ${
                                                        active
                                                            ? 'border-[#FF5733] bg-[rgba(255,87,51,0.08)] shadow-[0_0_0_1px_rgba(255,87,51,0.55)]'
                                                            : 'border-white/10 bg-white/[0.02] hover:border-white/20 hover:bg-white/[0.04]'
                                                    }`}
                                                >
                                                    {pack.popular && (
                                                        <span className="absolute -top-2 rounded-full bg-[#FF5733] px-1.5 py-px text-[8px] font-bold uppercase tracking-wide text-white">
                                                            Popular
                                                        </span>
                                                    )}
                                                    {pack.best && (
                                                        <span className="absolute -top-2 rounded-full bg-amber-400 px-1.5 py-px text-[8px] font-bold uppercase tracking-wide text-black">
                                                            Best value
                                                        </span>
                                                    )}
                                                    <span className="text-[10px] font-medium uppercase tracking-wide text-zinc-500">{pack.label}</span>
                                                    <span className="text-base font-bold text-white">
                                                        {price(pack.usd).replace(/[^\d.]/g, '')}{' '}
                                                        <span className="text-[10px] font-medium text-zinc-400">{currency}</span>
                                                    </span>
                                                    {pack.bonus && (
                                                        <span className="text-[10px] font-semibold text-emerald-400">{pack.bonus}</span>
                                                    )}
                                                    <span className="mt-0.5 text-[11px] text-amber-300/90">{pack.credits.toLocaleString()}</span>
                                                </button>
                                            );
                                        })}
                                    </div>

                                    <div className="flex items-center gap-2 rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5 text-[12px] text-zinc-400">
                                        <svg className="h-4 w-4 shrink-0 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.5 11 14.5 15.5 10M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3Z" />
                                        </svg>
                                        Secure checkout — tokens never expire.
                                    </div>

                                    <button
                                        type="button"
                                        disabled={!selectedPack}
                                        className="group relative flex h-11 w-full items-center justify-center gap-2 overflow-hidden rounded-xl bg-gradient-to-b from-[#FF6A45] to-[#E24216] text-[14px] font-semibold text-white shadow-[0_12px_28px_-12px_rgba(255,87,51,0.9)] transition hover:brightness-110 active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span className="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 group-hover:translate-x-full" />
                                        <svg className="relative h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.9">
                                            <rect width="20" height="14" x="2" y="5" rx="2" />
                                            <path strokeLinecap="round" d="M2 10h20" />
                                        </svg>
                                        <span className="relative">
                                            {selectedPack
                                                ? `Proceed to Checkout · ${price(selectedPack.usd)} for ${selectedPack.credits.toLocaleString()} tokens`
                                                : 'Select a pack'}
                                        </span>
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
