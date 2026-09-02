/**
 * Moves an already-buffered <video> from a trend card into the detail modal
 * (and back on close) so the browser does not re-fetch / re-decode the file.
 */

type WarmHandlers = {
    onLift?: () => void;
    onRestore?: () => void;
};

type WarmEntry = {
    el: HTMLVideoElement;
    host: HTMLElement;
    handlers: WarmHandlers;
    /** Original playback URL — reasserted if something clears src. */
    src: string;
};

type ClaimMeta = {
    key: string;
    host: HTMLElement;
    handlers: WarmHandlers;
    src: string;
};

const registry = new Map<string, WarmEntry>();
const claimMeta = new WeakMap<HTMLVideoElement, ClaimMeta>();

export function trendWarmKey(templateId: string, src?: string | null): string {
    return `${templateId}::${src || ''}`;
}

/** Stable warm-cache key for Lab library grid ↔ preview handoff. */
export function labWarmKey(itemId: string, src?: string | null): string {
    return `lab::${itemId}::${src || ''}`;
}

function unwrapFromPlyr(el: HTMLVideoElement): void {
    const wrap = el.closest('.plyr');
    if (wrap && wrap.parentNode && wrap !== el) {
        wrap.parentNode.insertBefore(el, wrap);
        wrap.remove();
    }
}

function scrubPlayerChrome(el: HTMLVideoElement): void {
    unwrapFromPlyr(el);
    el.controls = false;
    el.removeAttribute('controls');
    el.removeAttribute('data-plyr');
    el.removeAttribute('data-poster');
    // Drop leftover Plyr / inline styles that can leave a black frame.
    // Keep positioning; callers set object-fit (contain vs cover).
    el.style.cssText = '';
    el.className = 'absolute inset-0 size-full opacity-100';
}

export function bindTrendWarmVideo(
    key: string,
    el: HTMLVideoElement,
    host: HTMLElement,
    handlers: WarmHandlers = {},
): () => void {
    const src = el.currentSrc || el.src || '';
    registry.set(key, { el, host, handlers, src });
    return () => {
        const cur = registry.get(key);
        if (cur?.el === el) {
            registry.delete(key);
        }
    };
}

/** Claim a warm video for the modal. Returns null if nothing useful is buffered yet. */
export function claimTrendWarmVideo(key: string): HTMLVideoElement | null {
    const entry = registry.get(key);
    if (!entry) return null;

    const { el, host, handlers, src } = entry;
    // Accept a painted frame even if the browser hasn't promoted readyState yet.
    if (el.readyState < HTMLMediaElement.HAVE_CURRENT_DATA && el.videoWidth < 2) {
        return null;
    }

    registry.delete(key);
    claimMeta.set(el, { key, host, handlers, src });
    handlers.onLift?.();

    const time = el.currentTime;
    el.pause();
    scrubPlayerChrome(el);
    el.remove();

    // Keep buffered media; only reassert src if something wiped it.
    if (!el.src && !el.currentSrc && src) {
        el.src = src;
    }
    try {
        if (Number.isFinite(time)) el.currentTime = time;
    } catch {
        /* ignore */
    }

    return el;
}

/** Put a claimed video back on its card host after the modal closes. */
export function restoreTrendWarmVideo(el: HTMLVideoElement): void {
    const meta = claimMeta.get(el);
    claimMeta.delete(el);

    if (!meta?.host?.isConnected) {
        el.pause();
        return;
    }

    scrubPlayerChrome(el);
    el.muted = true;
    el.defaultMuted = true;
    el.loop = true;
    el.playsInline = true;
    el.autoplay = false;

    if (!el.src && !el.currentSrc && meta.src) {
        el.src = meta.src;
    }

    meta.host.appendChild(el);
    registry.set(meta.key, { el, host: meta.host, handlers: meta.handlers, src: meta.src });
    // Card host decides whether to resume (Trends teasers play; Lab stills stay paused).
    meta.handlers.onRestore?.();
}
