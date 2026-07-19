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
};

type ClaimMeta = {
    key: string;
    host: HTMLElement;
    handlers: WarmHandlers;
};

const registry = new Map<string, WarmEntry>();
const claimMeta = new WeakMap<HTMLVideoElement, ClaimMeta>();

export function trendWarmKey(templateId: string, src?: string | null): string {
    return `${templateId}::${src || ''}`;
}

export function bindTrendWarmVideo(
    key: string,
    el: HTMLVideoElement,
    host: HTMLElement,
    handlers: WarmHandlers = {},
): () => void {
    registry.set(key, { el, host, handlers });
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

    const { el, host, handlers } = entry;
    // HAVE_CURRENT_DATA or better — enough to paint without waiting on network.
    if (el.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) {
        return null;
    }

    registry.delete(key);
    claimMeta.set(el, { key, host, handlers });
    handlers.onLift?.();

    el.pause();
    el.remove();

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

    el.controls = false;
    el.removeAttribute('controls');
    el.muted = true;
    el.loop = true;
    el.playsInline = true;
    el.autoplay = true;
    // Card teaser classes; modal / Plyr may have rewritten these.
    el.className = 'absolute inset-0 size-full object-cover';
    el.style.cssText = '';

    meta.host.appendChild(el);
    registry.set(meta.key, { el, host: meta.host, handlers: meta.handlers });
    meta.handlers.onRestore?.();

    void el.play().catch(() => undefined);
}
