import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';

type Props = {
    text: string;
    label?: string;
    className?: string;
};

const GAP = 8;
const VIEWPORT_PAD = 8;
const TOOLTIP_MAX_W = 240;

type Placement = 'top' | 'bottom';

type TooltipPos = {
    top: number;
    left: number;
    placement: Placement;
    arrowLeft: number;
};

function measureTooltipPos(trigger: HTMLElement, tooltip: HTMLElement): TooltipPos {
    const tr = trigger.getBoundingClientRect();
    const tt = tooltip.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    const triggerCenterX = tr.left + tr.width / 2;
    let left = triggerCenterX - tt.width / 2;
    left = Math.max(VIEWPORT_PAD, Math.min(left, vw - tt.width - VIEWPORT_PAD));

    const spaceAbove = tr.top - VIEWPORT_PAD;
    const spaceBelow = vh - tr.bottom - VIEWPORT_PAD;
    const preferTop = spaceAbove >= tt.height + GAP || spaceAbove >= spaceBelow;
    const placement: Placement = preferTop && spaceAbove >= tt.height + GAP ? 'top' : 'bottom';

    const top =
        placement === 'top'
            ? Math.max(VIEWPORT_PAD, tr.top - tt.height - GAP)
            : Math.min(vh - tt.height - VIEWPORT_PAD, tr.bottom + GAP);

    const arrowLeft = Math.max(12, Math.min(tt.width - 12, triggerCenterX - left));

    return { top, left, placement, arrowLeft };
}

/**
 * Compact “!” tip next to Lab toggles — tap to show a short translated explanation.
 */
export default function ToggleTip({ text, label = 'Help', className = '' }: Props) {
    const [open, setOpen] = useState(false);
    const [pos, setPos] = useState<TooltipPos | null>(null);
    const tipId = useId();
    const rootRef = useRef<HTMLSpanElement>(null);
    const btnRef = useRef<HTMLButtonElement>(null);
    const tooltipRef = useRef<HTMLSpanElement>(null);

    const updatePosition = useCallback(() => {
        const btn = btnRef.current;
        const tooltip = tooltipRef.current;
        if (!btn || !tooltip) return;
        setPos(measureTooltipPos(btn, tooltip));
    }, []);

    useLayoutEffect(() => {
        if (!open) {
            setPos(null);
            return;
        }
        updatePosition();
    }, [open, text, updatePosition]);

    useEffect(() => {
        if (!open) return;
        const onReposition = () => updatePosition();
        window.addEventListener('resize', onReposition);
        window.addEventListener('scroll', onReposition, true);
        return () => {
            window.removeEventListener('resize', onReposition);
            window.removeEventListener('scroll', onReposition, true);
        };
    }, [open, updatePosition]);

    useEffect(() => {
        if (!open) return;
        const onDoc = (e: MouseEvent) => {
            const target = e.target as Node;
            if (rootRef.current?.contains(target) || tooltipRef.current?.contains(target)) return;
            setOpen(false);
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

    const tooltip =
        open && typeof document !== 'undefined'
            ? createPortal(
                  <span
                      ref={tooltipRef}
                      id={tipId}
                      role="tooltip"
                      style={{
                          position: 'fixed',
                          top: pos?.top ?? -9999,
                          left: pos?.left ?? -9999,
                          width: `min(${TOOLTIP_MAX_W}px, calc(100vw - ${VIEWPORT_PAD * 2}px))`,
                          visibility: pos ? 'visible' : 'hidden',
                          zIndex: 200,
                      }}
                      className="rounded-xl border border-white/12 bg-[#16161c] px-3 py-2 text-start text-[11px] leading-relaxed text-white/80 shadow-[0_16px_40px_-12px_rgba(0,0,0,0.9)]"
                  >
                      {text}
                      {pos && (
                          <span
                              aria-hidden
                              style={{ left: pos.arrowLeft }}
                              className={`absolute h-0 w-0 -translate-x-1/2 border-x-[6px] border-x-transparent ${
                                  pos.placement === 'top'
                                      ? 'top-full border-t-[6px] border-t-[#16161c]'
                                      : 'bottom-full border-b-[6px] border-b-[#16161c]'
                              }`}
                          />
                      )}
                  </span>,
                  document.body,
              )
            : null;

    return (
        <span ref={rootRef} className={`relative inline-flex shrink-0 ${className}`}>
            <button
                ref={btnRef}
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
            {tooltip}
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
