import { sameOriginMediaUrl } from '@/lib/labReuse';

function guessMime(filename: string): string {
    const lower = filename.toLowerCase();
    if (lower.endsWith('.mp4') || lower.endsWith('.webm') || lower.endsWith('.mov')) return 'video/mp4';
    if (lower.endsWith('.mp3') || lower.endsWith('.wav') || lower.endsWith('.m4a')) return 'audio/mpeg';
    if (lower.endsWith('.png')) return 'image/png';
    if (lower.endsWith('.webp')) return 'image/webp';
    return 'image/jpeg';
}

function isLikelyMobile(): boolean {
    if (typeof navigator === 'undefined') return false;
    if ((navigator as Navigator & { userAgentData?: { mobile?: boolean } }).userAgentData?.mobile) return true;
    return /iPhone|iPad|iPod|Android/i.test(navigator.userAgent || '');
}

function attachmentFetchUrl(raw: string, filename: string): string {
    const base = sameOriginMediaUrl(raw);
    const sep = base.includes('?') ? '&' : '?';
    return `${base}${sep}download=1&filename=${encodeURIComponent(filename)}`;
}

function triggerAnchorDownload(href: string, filename: string): void {
    const a = document.createElement('a');
    a.href = href;
    a.download = filename;
    a.rel = 'noopener';
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
}

async function fetchAsFile(url: string, filename: string): Promise<File> {
    const fetchUrl = sameOriginMediaUrl(url);
    const res = await fetch(fetchUrl, {
        credentials: 'same-origin',
        headers: {
            Accept: '*/*',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    if (!res.ok) {
        throw new Error(`Download failed (${res.status})`);
    }

    const blob = await res.blob();
    if (blob.size === 0) {
        throw new Error('Empty download');
    }

    const type = (blob.type && !blob.type.includes('text/html') ? blob.type : guessMime(filename)).split(';')[0];
    return new File([blob], filename, { type });
}

/**
 * Download a remote lab asset on desktop + mobile.
 * Uses same-origin /lab/asset-fetch (fal CDN blocks browser CORS),
 * then Web Share on phones when available, otherwise an attachment response
 * (mobile) or blob <a download> (desktop).
 */
export async function downloadMediaAsset(url: string, filename: string): Promise<void> {
    if (!url) throw new Error('Nothing to download');

    // Already local
    if (url.startsWith('blob:') || url.startsWith('data:')) {
        triggerAnchorDownload(url, filename);
        return;
    }

    const mobile = isLikelyMobile();

    // Mobile: share sheet is the reliable "save to Files / Photos" path.
    if (mobile) {
        const nav = navigator as Navigator & {
            canShare?: (data: ShareData) => boolean;
            share?: (data: ShareData) => Promise<void>;
        };
        if (typeof nav.share === 'function' && typeof nav.canShare === 'function') {
            try {
                const file = await fetchAsFile(url, filename);
                if (nav.canShare({ files: [file] })) {
                    await nav.share({ files: [file], title: filename });
                    return;
                }
            } catch (err) {
                // User cancelled share — treat as done.
                if (err instanceof DOMException && err.name === 'AbortError') return;
                // Fall through to attachment navigation.
            }
        }

        // iOS/Android: navigate to same-origin attachment (blob <a download> is unreliable).
        triggerAnchorDownload(attachmentFetchUrl(url, filename), filename);
        return;
    }

    // Desktop: blob + download attribute.
    const file = await fetchAsFile(url, filename);
    const objectUrl = URL.createObjectURL(file);
    try {
        triggerAnchorDownload(objectUrl, filename);
    } finally {
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 4000);
    }
}
