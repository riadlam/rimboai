import { AnimatePresence, motion } from 'framer-motion';
import { createPortal } from 'react-dom';
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

type Variant = 'tile' | 'row';

type Props = {
    error?: string | null;
    prompt?: string | null;
    onDismiss?: () => void;
    variant?: Variant;
};

type PopPos = { top: number; left: number; width: number };

const TIP_ESTIMATE_H = 130;

function ErrorBang({ error }: { error: string }) {
    const { t: tLab } = useTranslation('lab');
    const [open, setOpen] = useState(false);
    const [pos, setPos] = useState<PopPos | null>(null);
    const btnRef = useRef<HTMLButtonElement>(null);
    const tipRef = useRef<HTMLDivElement>(null);
    const tipId = useId();
    const hoverOk = useRef(false);

    useEffect(() => {
        hoverOk.current = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    }, []);

    const place = useCallback(() => {
        const btn = btnRef.current;
        if (!btn) return;
        const r = btn.getBoundingClientRect();
        const width = Math.min(280, window.innerWidth - 16);
        const tipH = tipRef.current?.offsetHeight || TIP_ESTIMATE_H;
        const gap = 8;

        let left = r.left + r.width / 2 - width / 2;
        left = Math.max(8, Math.min(left, window.innerWidth - width - 8));

        const below = r.bottom + gap;
        const above = r.top - tipH - gap;
        const top =
            below + tipH + 8 > window.innerHeight && above > 8 ? above : below;

        setPos({ top, left, width });
    }, []);

    useLayoutEffect(() => {
        if (!open) {
            setPos(null);
            return;
        }
        place();
        // Second pass after tip paints with real height
        const id = window.requestAnimationFrame(() => place());
        const onMove = () => place();
        window.addEventListener('resize', onMove);
        window.addEventListener('scroll', onMove, true);
        return () => {
            window.cancelAnimationFrame(id);
            window.removeEventListener('resize', onMove);
            window.removeEventListener('scroll', onMove, true);
        };
    }, [open, place]);

    useEffect(() => {
        if (!open) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        const onPointer = (e: PointerEvent) => {
            const t = e.target as Node;
            if (btnRef.current?.contains(t) || tipRef.current?.contains(t)) return;
            setOpen(false);
        };
        document.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onPointer);
        return () => {
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('pointerdown', onPointer);
        };
    }, [open]);

    return (
        <>
            <button
                ref={btnRef}
                type="button"
                aria-label={tLab('viewError', { defaultValue: 'View error details' })}
                aria-expanded={open}
                aria-controls={open ? tipId : undefined}
                onClick={(e) => {
                    e.stopPropagation();
                    setOpen((v) => !v);
                }}
                onMouseEnter={() => {
                    if (hoverOk.current) setOpen(true);
                }}
                onMouseLeave={() => {
                    if (hoverOk.current) setOpen(false);
                }}
                className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-500/20 text-[14px] font-bold leading-none text-red-200 ring-1 ring-red-400/40 transition hover:bg-red-500/35 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-red-400/70"
            >
                !
            </button>

            {typeof document !== 'undefined' &&
                createPortal(
                    <AnimatePresence>
                        {open && pos && (
                            <motion.div
                                ref={tipRef}
                                id={tipId}
                                role="dialog"
                                aria-label={tLab('errorDetails', { defaultValue: 'Error details' })}
                                initial={{ opacity: 0, scale: 0.97 }}
                                animate={{ opacity: 1, scale: 1 }}
                                exit={{ opacity: 0, scale: 0.97 }}
                                transition={{ duration: 0.12 }}
                                onMouseEnter={() => {
                                    if (hoverOk.current) setOpen(true);
                                }}
                                onMouseLeave={() => {
                                    if (hoverOk.current) setOpen(false);
                                }}
                                style={{
                                    position: 'fixed',
                                    top: pos.top,
                                    left: pos.left,
                                    width: pos.width,
                                    zIndex: 200,
                                }}
                                className="rounded-xl border border-red-400/30 bg-[#1c1214] p-3 shadow-[0_16px_48px_rgba(0,0,0,0.6)]"
                            >
                                <p className="mb-1 text-[10px] font-semibold uppercase tracking-wide text-red-300/90">
                                    {tLab('errorDetails', { defaultValue: 'Error details' })}
                                </p>
                                <p className="max-h-36 overflow-y-auto break-words text-[12px] leading-relaxed text-white/85">
                                    {error}
                                </p>
                            </motion.div>
                        )}
                    </AnimatePresence>,
                    document.body,
                )}
        </>
    );
}

export default function LabFailedCard({ error, prompt, onDismiss, variant = 'tile' }: Props) {
    const { t: tLab } = useTranslation('lab');
    const message = (error || '').trim() || tLab('generationFailedGeneric', { defaultValue: 'Generation failed.' });

    if (variant === 'row') {
        return (
            <motion.div
                layout
                initial={{ opacity: 0, y: 6 }}
                animate={{ opacity: 1, y: 0 }}
                className="flex min-h-[52px] items-center gap-3 rounded-2xl border border-red-500/20 bg-[#161014] px-3 py-2"
            >
                <ErrorBang error={message} />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-semibold text-red-100/90">{tLab('failed')}</p>
                    {prompt ? <p className="truncate text-[11px] text-white/40">{prompt}</p> : null}
                </div>
                {onDismiss ? (
                    <button
                        type="button"
                        onClick={onDismiss}
                        className="shrink-0 rounded-lg px-2.5 py-1.5 text-[11px] font-medium text-white/55 transition hover:bg-white/[0.06] hover:text-white"
                    >
                        {tLab('dismiss')}
                    </button>
                ) : null}
            </motion.div>
        );
    }

    return (
        <motion.div
            layout
            initial={{ opacity: 0, scale: 0.98 }}
            animate={{ opacity: 1, scale: 1 }}
            className="relative w-full overflow-hidden rounded-xl border border-red-500/20 bg-[#141018]"
            style={{ aspectRatio: '1 / 1' }}
        >
            <div
                aria-hidden
                className="pointer-events-none absolute inset-0"
                style={{
                    background: 'radial-gradient(70% 55% at 50% 35%, rgba(239,68,68,0.18), transparent 72%)',
                }}
            />

            {onDismiss ? (
                <button
                    type="button"
                    onClick={onDismiss}
                    aria-label={tLab('dismiss')}
                    className="absolute end-1 top-1 z-[2] flex h-7 w-7 items-center justify-center rounded-full text-white/40 transition hover:bg-white/10 hover:text-white"
                >
                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth="2.2">
                        <path strokeLinecap="round" d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            ) : null}

            {/* Center cluster — stays inside the square without stacking huge blocks */}
            <div className="absolute inset-0 flex items-center justify-center">
                <div className="flex max-w-[92%] flex-col items-center gap-1.5">
                    <ErrorBang error={message} />
                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-red-200/80">
                        {tLab('failed')}
                    </span>
                    {prompt ? (
                        <span className="line-clamp-1 max-w-full px-1 text-center text-[10px] text-white/30">
                            {prompt}
                        </span>
                    ) : null}
                </div>
            </div>
        </motion.div>
    );
}
