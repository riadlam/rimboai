import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

type Props = {
    text: string;
    label?: string;
    className?: string;
};

/**
 * Compact “!” tip next to Lab toggles — tap to show a short translated explanation.
 */
export default function ToggleTip({ text, label = 'Help', className = '' }: Props) {
    const [open, setOpen] = useState(false);
    const tipId = useId();
    const rootRef = useRef<HTMLSpanElement>(null);

    useEffect(() => {
        if (!open) return;
        const onDoc = (e: MouseEvent) => {
            if (!rootRef.current?.contains(e.target as Node)) setOpen(false);
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    return (
        <span ref={rootRef} className={`relative inline-flex shrink-0 ${className}`}>
            <button
                type="button"
                aria-label={label}
                aria-expanded={open}
                aria-controls={tipId}
                onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    setOpen((v) => !v);
                }}
                className="flex h-7 w-7 items-center justify-center rounded-full border border-white/12 bg-white/[0.04] text-[11px] font-bold text-white/55 transition hover:border-orange-400/40 hover:bg-orange-500/10 hover:text-orange-200"
            >
                !
            </button>
            {open && (
                <span
                    id={tipId}
                    role="tooltip"
                    className="absolute bottom-[calc(100%+8px)] start-1/2 z-30 w-[min(240px,70vw)] -translate-x-1/2 rounded-xl border border-white/12 bg-[#16161c] px-3 py-2 text-start text-[11px] leading-relaxed text-white/80 shadow-[0_16px_40px_-12px_rgba(0,0,0,0.9)] rtl:translate-x-1/2"
                >
                    {text}
                    <span
                        aria-hidden
                        className="absolute start-1/2 top-full h-0 w-0 -translate-x-1/2 border-x-[6px] border-t-[6px] border-x-transparent border-t-[#16161c] rtl:translate-x-1/2"
                    />
                </span>
            )}
        </span>
    );
}

/** Label row with optional tip — use next to switches. */
export function ToggleLabelWithTip({
    children,
    tip,
    tipLabel,
}: {
    children: ReactNode;
    tip: string;
    tipLabel?: string;
}) {
    return (
        <span className="inline-flex min-w-0 items-center gap-1.5">
            <span className="min-w-0">{children}</span>
            <ToggleTip text={tip} label={tipLabel} />
        </span>
    );
}
