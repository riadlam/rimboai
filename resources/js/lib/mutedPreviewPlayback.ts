/**
 * Site-wide budget for muted continuous preview players (Home / Trends grids).
 * Caps concurrent decoders to avoid browser hangs.
 */

/** One continuous muted preview at a time — two decoders still hung weaker devices. */
export const MAX_MUTED_PREVIEW_PLAYERS = 1;

type Entry = {
    el: HTMLVideoElement;
    grantedAt: number;
};

const active: Entry[] = [];
let visibilityBound = false;

function pauseReset(el: HTMLVideoElement): void {
    try {
        el.pause();
    } catch {
        /* ignore */
    }
    try {
        el.currentTime = 0;
    } catch {
        /* ignore */
    }
}

function removeEntry(el: HTMLVideoElement): void {
    const idx = active.findIndex((e) => e.el === el);
    if (idx >= 0) active.splice(idx, 1);
}

function ensureVisibilityListener(): void {
    if (visibilityBound || typeof document === 'undefined') return;
    visibilityBound = true;
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'hidden') return;
        // Freeze every budgeted preview when the tab is backgrounded.
        const snapshot = [...active];
        active.length = 0;
        for (const entry of snapshot) {
            pauseReset(entry.el);
        }
    });
}

/**
 * Ask for a muted-preview play slot. May demote the oldest player when over budget.
 * Returns false if the document is hidden (caller should stay paused).
 */
export function requestMutedPreviewPlay(el: HTMLVideoElement): boolean {
    ensureVisibilityListener();

    if (typeof document !== 'undefined' && document.visibilityState === 'hidden') {
        return false;
    }

    const existing = active.find((e) => e.el === el);
    if (existing) {
        existing.grantedAt = Date.now();
        return true;
    }

    while (active.length >= MAX_MUTED_PREVIEW_PLAYERS) {
        // Demote least-recently granted (oldest grant time).
        let oldestIdx = 0;
        for (let i = 1; i < active.length; i++) {
            if (active[i].grantedAt < active[oldestIdx].grantedAt) oldestIdx = i;
        }
        const victim = active.splice(oldestIdx, 1)[0];
        if (victim) pauseReset(victim.el);
    }

    active.push({ el, grantedAt: Date.now() });
    return true;
}

/** Release a preview slot and reset the element to the first frame. */
export function releaseMutedPreview(el: HTMLVideoElement): void {
    removeEntry(el);
    pauseReset(el);
}

/** True when this element currently holds a budget slot. */
export function hasMutedPreviewSlot(el: HTMLVideoElement): boolean {
    return active.some((e) => e.el === el);
}
