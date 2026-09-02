/**
 * Lab building progress from real server signals.
 * fal does not send a continuous 0–100% for generation; we use:
 * - queue_position while queued (real)
 * - stage floors + gentle time crawl while in_progress (fal has no %)
 */
export function labProgressPercent(opts: {
    status?: string | null;
    queuePosition?: number | null;
    startedAt?: number;
    completing?: boolean;
}): number {
    if (opts.completing || opts.status === 'completed') return 100;
    if (opts.status === 'failed' || opts.status === 'cancelled') return 0;

    if (opts.status === 'queued') {
        const pos = opts.queuePosition;
        if (typeof pos === 'number' && Number.isFinite(pos) && pos >= 0) {
            return Math.max(8, Math.min(42, 40 - Math.min(pos, 16) * 2));
        }
        return 20;
    }

    if (opts.status === 'in_progress') {
        const elapsed = opts.startedAt ? Math.max(0, (Date.now() - opts.startedAt) / 1000) : 0;
        const t = Math.min(elapsed / 240, 1);
        const eased = 1 - Math.pow(1 - t, 1.55);
        return Math.round(45 + eased * 43); // 45 → 88
    }

    // pending / starting
    const elapsed = opts.startedAt ? Math.max(0, (Date.now() - opts.startedAt) / 1000) : 0;
    return Math.min(12, 4 + Math.round(elapsed * 2));
}

export type LabPhaseTranslator = (key: string, opts?: Record<string, unknown>) => string;

export function labPhaseLabel(opts: {
    status?: string | null;
    queuePosition?: number | null;
    progress?: string | null;
    completing?: boolean;
    kind: 'image' | 'video' | 'music';
    t?: LabPhaseTranslator;
}): string {
    const t = opts.t ?? ((key: string) => key);
    if (opts.completing) return t('library.progress.done');
    if (opts.status === 'queued') {
        if (typeof opts.queuePosition === 'number' && opts.queuePosition > 0) {
            return t('library.progress.queue', { pos: opts.queuePosition });
        }
        if (typeof opts.queuePosition === 'number' && opts.queuePosition === 0) {
            return t('library.progress.nextUp');
        }
        return t('library.progress.inQueue');
    }
    if (opts.status === 'pending') return t('library.progress.starting');
    if (opts.progress && opts.progress.trim() !== '') {
        return opts.progress.trim();
    }
    if (opts.kind === 'music') return t('library.progress.composing');
    if (opts.kind === 'video') return t('library.progress.generatingVideo');
    return t('library.progress.generating');
}

/** How long to ramp displayed % → 100 when the job finishes (farther = a bit longer, still snappy). */
export function labCompletingRampMs(fromPercent: number): number {
    const gap = Math.max(0, 100 - Math.min(100, Math.max(0, fromPercent)));
    return Math.round(Math.min(1800, Math.max(700, 500 + gap * 12)));
}

/** Prefer server sync % when available; smooth upward between polls with local estimate. */
export function labEffectiveProgressPercent(opts: {
    serverPercent?: number | null;
    status?: string | null;
    queuePosition?: number | null;
    startedAt?: number;
    completing?: boolean;
}): number {
    const estimated = labProgressPercent(opts);
    if (typeof opts.serverPercent === 'number' && Number.isFinite(opts.serverPercent)) {
        return Math.max(opts.serverPercent, estimated);
    }
    return estimated;
}
