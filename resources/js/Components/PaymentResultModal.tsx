import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';

export type PaymentResultKind = 'canceled' | 'failed';

type Props = {
    open: boolean;
    kind: PaymentResultKind;
    detail?: string | null;
    onClose: () => void;
    onTryAgain?: () => void;
};

const AUTO_DISMISS_MS = 8000;

export default function PaymentResultModal({ open, kind, detail, onClose, onTryAgain }: Props) {
    const { t } = useTranslation('pricing');
    const [progress, setProgress] = useState(100);
    const onCloseRef = useRef(onClose);
    onCloseRef.current = onClose;

    useEffect(() => {
        if (!open) {
            setProgress(100);
            return;
        }

        const started = Date.now();
        const tick = window.setInterval(() => {
            const elapsed = Date.now() - started;
            const left = Math.max(0, 100 - (elapsed / AUTO_DISMISS_MS) * 100);
            setProgress(left);
            if (elapsed >= AUTO_DISMISS_MS) {
                window.clearInterval(tick);
                onCloseRef.current();
            }
        }, 50);

        return () => window.clearInterval(tick);
    }, [open, kind]);

    useEffect(() => {
        if (!open) return;
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.body.style.overflow = prev;
        };
    }, [open]);

    if (typeof document === 'undefined') return null;

    const canceled = kind === 'canceled';
    const title = canceled ? t('result.canceledTitle') : t('result.failedTitle');
    const body = canceled ? t('result.canceledBody') : t('result.failedBody');
    const trimmedDetail = (detail ?? '').trim();
    const showDetail = trimmedDetail !== '' && trimmedDetail !== body && trimmedDetail !== title;

    return createPortal(
        <AnimatePresence>
            {open && (
                <Dialog static open={open} onClose={onClose} className="relative z-[110]">
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
                            className="w-full max-w-[420px]"
                        >
                            <DialogPanel className="relative overflow-hidden rounded-t-2xl border border-white/10 bg-[#121216] shadow-2xl sm:rounded-2xl">
                                <div
                                    aria-hidden
                                    className={`pointer-events-none absolute inset-0 ${
                                        canceled
                                            ? 'bg-[radial-gradient(ellipse_at_top,_rgba(251,191,36,0.14),_transparent_58%)]'
                                            : 'bg-[radial-gradient(ellipse_at_top,_rgba(244,63,94,0.16),_transparent_58%)]'
                                    }`}
                                />

                                <div className="relative px-5 pb-5 pt-5 sm:px-6 sm:pt-6">
                                    <div className="mx-auto mb-3 h-1 w-10 rounded-full bg-white/20 sm:hidden" />

                                    <button
                                        type="button"
                                        onClick={onClose}
                                        className="absolute end-3 top-3 rounded-lg p-1.5 text-zinc-500 transition hover:bg-white/5 hover:text-white"
                                        aria-label={t('result.close')}
                                    >
                                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                                        </svg>
                                    </button>

                                    <div className="flex flex-col items-center text-center">
                                        <motion.span
                                            initial={{ scale: 0.7, opacity: 0 }}
                                            animate={{ scale: 1, opacity: 1 }}
                                            transition={{ type: 'spring', stiffness: 460, damping: 20 }}
                                            className={`flex h-14 w-14 items-center justify-center rounded-2xl border ${
                                                canceled
                                                    ? 'border-amber-400/30 bg-amber-400/10 text-amber-200'
                                                    : 'border-rose-400/30 bg-rose-500/10 text-rose-200'
                                            }`}
                                        >
                                            {canceled ? (
                                                <svg className="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                                                    <circle cx="12" cy="12" r="9" />
                                                    <path strokeLinecap="round" d="M8 12h8" />
                                                </svg>
                                            ) : (
                                                <svg className="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.8">
                                                    <circle cx="12" cy="12" r="9" />
                                                    <path strokeLinecap="round" d="M12 8v5" />
                                                    <circle cx="12" cy="16" r="0.9" fill="currentColor" stroke="none" />
                                                </svg>
                                            )}
                                        </motion.span>

                                        <DialogTitle className="mt-4 font-[family-name:Outfit,sans-serif] text-[1.2rem] font-bold tracking-tight text-white">
                                            {title}
                                        </DialogTitle>
                                        <p className="mt-2 max-w-[34ch] text-[13px] leading-relaxed text-white/55">{body}</p>
                                        {showDetail && (
                                            <p className="mt-3 max-w-[36ch] rounded-xl border border-white/8 bg-white/[0.03] px-3 py-2 text-[12px] leading-relaxed text-white/40">
                                                {trimmedDetail}
                                            </p>
                                        )}
                                    </div>

                                    <div className="mt-5 flex flex-col gap-2.5">
                                        <button
                                            type="button"
                                            onClick={onTryAgain ?? onClose}
                                            className="inline-flex h-11 items-center justify-center rounded-xl bg-gradient-to-b from-[#FF7A55] to-[#E24216] text-sm font-semibold text-white shadow-[0_14px_32px_-12px_rgba(255,87,51,0.9)] transition hover:brightness-110"
                                        >
                                            {t('result.tryAgain')}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={onClose}
                                            className="inline-flex h-11 items-center justify-center rounded-xl border border-white/12 bg-white/[0.03] text-sm font-medium text-white/80 transition hover:bg-white/[0.07] hover:text-white"
                                        >
                                            {t('result.close')}
                                        </button>
                                    </div>
                                </div>

                                <div className="h-0.5 w-full bg-white/[0.06]" aria-hidden>
                                    <motion.div
                                        className={`h-full ${canceled ? 'bg-amber-300/80' : 'bg-rose-400/80'}`}
                                        style={{ width: `${progress}%` }}
                                    />
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
