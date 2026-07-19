/**
 * Fast download: hit the CDN (or same-origin storage) directly.
 * Never proxy the full file through /lab/asset-fetch — that doubles transfer
 * time on shared hosting and makes large videos feel stuck.
 */
export async function downloadMediaAsset(url: string, filename: string): Promise<void> {
    if (!url) throw new Error('Nothing to download');

    if (url.startsWith('blob:') || url.startsWith('data:')) {
        triggerAnchorDownload(url, filename);
        return;
    }

    const absolute = toAbsoluteUrl(url);

    // Same-origin (/storage/...): download attribute works and is already fast.
    if (isSameOrigin(absolute)) {
        triggerAnchorDownload(absolute, filename);
        return;
    }

    // Remote CDN (fal, etc.): open/save directly — browser streams from the CDN.
    // Cross-origin `download` is often ignored, but the transfer is still at CDN speed.
    if (isLikelyMobile() && typeof navigator.share === 'function') {
        try {
            // Share the CDN link (no file body) — instant; user can Save from the sheet.
            await navigator.share({ url: absolute, title: filename });
            return;
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError') return;
            // Fall through to anchor open.
        }
    }

    triggerAnchorDownload(absolute, filename, { openBlank: true });
}

function isLikelyMobile(): boolean {
    if (typeof navigator === 'undefined') return false;
    if ((navigator as Navigator & { userAgentData?: { mobile?: boolean } }).userAgentData?.mobile) {
        return true;
    }
    return /iPhone|iPad|iPod|Android/i.test(navigator.userAgent || '');
}

function toAbsoluteUrl(raw: string): string {
    if (raw.startsWith('http://') || raw.startsWith('https://')) return raw;
    if (raw.startsWith('//')) return `${window.location.protocol}${raw}`;
    if (raw.startsWith('/')) return `${window.location.origin}${raw}`;
    return raw;
}

function isSameOrigin(absolute: string): boolean {
    try {
        return new URL(absolute, window.location.origin).origin === window.location.origin;
    } catch {
        return absolute.startsWith('/');
    }
}

function triggerAnchorDownload(
    href: string,
    filename: string,
    opts?: { openBlank?: boolean },
): void {
    const a = document.createElement('a');
    a.href = href;
    a.download = filename;
    a.rel = 'noopener noreferrer';
    if (opts?.openBlank) {
        a.target = '_blank';
    }
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
}
