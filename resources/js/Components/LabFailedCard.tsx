import { AnimatePresence, motion } from 'framer-motion';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

type Variant = 'tile' | 'row';

type Props = {
    error?: string | null;
    prompt?: string | null;
    onDismiss?: () => void;
    variant?: Variant;
};

function FailedErrorHint({ error }: { error: string }) {
    const { t: tLab } = useTranslation('lab');
    const [open, setOpen] = useState(false);
    const rootRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;
        const onPointerDown = (e: PointerEvent) => {
            if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', onPointerDown);
        return () => document.removeEventListener('pointerdown', onPointerDown);
    }, [open]);

    return (
        <div ref={rootRef} className="relative z-10 shrink-0">
            <button
                type="button"
                aria-label={tLab('viewError', { defaultValue: 'View error details' })}
                aria-expanded={open}
                onClick={() => setOpen((v) => !v)}
                onMouseEnter={() => {
                    if (window.matchMedia('(hover: hover)').matches) setOpen(true);
                }}
                onMouseLeave={() => {
                    if (window.matchMedia('(hover: hover)').matches) setOpen(false);
                }}
                className="flex h-7 w-7 items-center justify-center rounded-full bg-red-500/15 text-[13px] font-bold text-red-300 ring-1 ring-red-400/30 transition hover:bg-red-500/25 hover:text-red-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400/50 sm:h-8 sm:w-8 sm:text-sm"
            >
                !
            </button>

            <AnimatePresence>
                {open && (
                    <motion.div
                        role="tooltip"
                        initial={{ opacity: 0, y: 6, scale: 0.96 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 6, scale: 0.96 }}
                        transition={{ duration: 0.16, ease: 'easeOut' }}
                        className="absolute end-0 bottom-full z-30 mb-2 w-[min(16rem,calc(100vw-2rem))] rounded-xl border border-red-400/20 bg-[#1a1214]/98 p-3 text-start shadow-[0_12px_40px_rgba(0,0,0,0.45)] backdrop-blur-md sm:w-56"
                    >
                        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-red-300/90">
                            {tLab('errorDetails', { defaultValue: 'Error details' })}
                        </p>
                        <p className="text-[11px] leading-relaxed text-white/80 sm:text-[12px]">{error}</p>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}

export default function LabFailedCard({ error, prompt, onDismiss, variant = 'tile' }: Props) {
    const { t: tLab } = useTranslation('lab');
    const message = (error || '').trim() || tLab('generationFailedGeneric', { defaultValue: 'Generation failed.' });

    if (variant === 'row') {
        return (
            <motion.div
                layout
                initial={{ opacity: 0, y: 8 }}
                animate={{ opacity: 1, y: 0 }}
                className="relative overflow-visible rounded-2xl border border-red-500/15 bg-gradient-to-r from-[#1a1012] to-[#121218] p-2.5 sm:p-3"
            >
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 rounded-2xl opacity-40"
                    style={{
                        background:
                            'radial-gradient(ellipse 80% 60% at 0% 50%, rgba(239,68,68,0.12), transparent 70%)',
                    }}
                />
                <div className="relative flex items-center gap-2.5 sm:gap-3">
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-red-500/10 ring-1 ring-red-400/20 sm:h-14 sm:w-14">
                        <svg className="h-5 w-5 text-red-300/80" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                            <path strokeLinecap="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[12px] font-semibold text-red-200/90 sm:text-[13px]">
                            {tLab('failed')}
                        </p>
                        {prompt && (
                            <p className="mt-0.5 truncate text-[10px] text-white/35 sm:text-[11px]">{prompt}</p>
                        )}
                    </div>
                    <FailedErrorHint error={message} />
                    {onDismiss && (
                        <button
                            type="button"
                            onClick={onDismiss}
                            className="shrink-0 rounded-lg border border-white/10 bg-white/[0.04] px-2 py-1 text-[10px] font-medium text-white/60 transition hover:bg-white/[0.08] hover:text-white sm:px-2.5 sm:text-[11px]"
                        >
                            {tLab('dismiss')}
                        </button>
                    )}
                </div>
            </motion.div>
        );
    }

    return (
        <motion.div
            layout
            initial={{ opacity: 0, scale: 0.97 }}
            animate={{ opacity: 1, scale: 1 }}
            className="relative w-full overflow-visible rounded-xl border border-red-500/15 bg-[#141018]"
            style={{ aspectRatio: '1 / 1' }}
        >
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0 rounded-xl"
                style={{
                    background:
                        'radial-gradient(ellipse 90% 70% at 50% 0%, rgba(239,68,68,0.14), transparent 65%), linear-gradient(180deg, #1a1014 0%, #121018 100%)',
                }}
            />

            <div className="absolute end-2 top-2 sm:end-2.5 sm:top-2.5">
                <FailedErrorHint error={message} />
            </div>

            <div className="relative flex h-full flex-col items-center justify-center gap-2 px-3 pb-3 pt-8 text-center sm:gap-2.5 sm:px-4">
                <div className="flex h-10 w-10 items-center justify-center rounded-2xl bg-red-500/10 ring-1 ring-red-400/25 sm:h-11 sm:w-11">
                    <svg className="h-5 w-5 text-red-300/75" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="1.75">
                        <path strokeLinecap="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </div>
                <div className="min-w-0 max-w-full space-y-1">
                    <p className="text-[12px] font-semibold text-red-200/90 sm:text-[13px]">{tLab('failed')}</p>
                    {prompt ? (
                        <p className="line-clamp-2 text-[10px] leading-snug text-white/35">{prompt}</p>
                    ) : (
                        <p className="text-[10px] text-white/30">
                            {tLab('tapForError', { defaultValue: 'Tap ! for details' })}
                        </p>
                    )}
                </div>
                {onDismiss && (
                    <button
                        type="button"
                        onClick={onDismiss}
                        className="mt-0.5 rounded-lg border border-white/10 bg-white/[0.05] px-2.5 py-1 text-[10px] font-medium text-white/65 transition hover:bg-white/[0.1] hover:text-white sm:text-[11px]"
                    >
                        {tLab('dismiss')}
                    </button>
                )}
            </div>
        </motion.div>
    );
}
