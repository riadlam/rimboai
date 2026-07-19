import { sameOriginMediaUrl } from '@/lib/labReuse';

/**
 * Download / save a lab asset.
 *
 * On iPhone/Android, "Save Image" / "Save Video" / "Add to Photos" only appears
 * when Web Share is given a real File — sharing a fal.media URL does not.
 * We fetch the bytes (CDN CORS first, same-origin proxy fallback) and share the File.
 */
export async function downloadMediaAsset(url: string, filename: string): Promise<void> {
    if (!url) throw new Error('Nothing to download');

    if (url.startsWith('blob:') || url.startsWith('data:')) {
        if (isLikelyMobile()) {
            try {
                const file = await materializeLocalFile(url, filename);
                if (await tryShareFile(file, filename)) return;
            } catch (err) {
                if (err instanceof DOMException && err.name === 'AbortError') return;
            }
        }
        triggerAnchorDownload(url, filename);
        return;
    }

    const absolute = toAbsoluteUrl(url);
    const mobile = isLikelyMobile();

    // Phones: share as File so the system sheet includes Save to Photos / Gallery.
    if (mobile) {
        try {
            const file = await fetchAsShareFile(absolute, filename);
            if (await tryShareFile(file, filename)) return;
        } catch (err) {
            if (err instanceof DOMException && err.name === 'AbortError') return;
            // Fall through to open CDN.
        }
        triggerAnchorDownload(absolute, filename, { openBlank: true });
        return;
    }

    // Desktop same-origin: native download attribute.
    if (isSameOrigin(absolute)) {
        triggerAnchorDownload(absolute, filename);
        return;
    }

    // Desktop remote: blob download when possible (keeps a clean filename).
    try {
        const file = await fetchAsShareFile(absolute, filename);
        const objectUrl = URL.createObjectURL(file);
        try {
            triggerAnchorDownload(objectUrl, filename);
        } finally {
            window.setTimeout(() => URL.revokeObjectURL(objectUrl), 4000);
        }
        return;
    } catch {
        triggerAnchorDownload(absolute, filename, { openBlank: true });
    }
}

async function tryShareFile(file: File, title: string): Promise<boolean> {
    const nav = navigator as Navigator & {
        canShare?: (data: ShareData) => boolean;
        share?: (data: ShareData) => Promise<void>;
    };
    if (typeof nav.share !== 'function' || typeof nav.canShare !== 'function') {
        return false;
    }
    if (!nav.canShare({ files: [file] })) {
        return false;
    }
    await nav.share({ files: [file], title });
    return true;
}

/**
 * Prefer direct CDN fetch (fast). Fall back to /lab/asset-fetch when fal blocks CORS.
 * Images are small so the proxy is fine; required for a real File on Web Share.
 */
async function fetchAsShareFile(absoluteUrl: string, filename: string): Promise<File> {
    // 1) Direct CDN (when CORS allows)
    try {
        const res = await fetch(absoluteUrl, {
            mode: 'cors',
            credentials: 'omit',
            headers: { Accept: '*/*' },
        });
        if (res.ok) {
            const file = await responseToFile(res, filename);
            if (file) return file;
        }
    } catch {
        /* CORS or network — use proxy */
    }

    // 2) Same-origin proxy (fal CDN often blocks browser CORS)
    const res = await fetch(sameOriginMediaUrl(absoluteUrl), {
        credentials: 'same-origin',
        headers: {
            Accept: '*/*',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    if (!res.ok) {
        throw new Error(`Download failed (${res.status})`);
    }
    const file = await responseToFile(res, filename);
    if (!file) throw new Error('Empty download');
    return file;
}

async function materializeLocalFile(url: string, filename: string): Promise<File> {
    const res = await fetch(url);
    if (!res.ok) throw new Error('Could not read local asset');
    const file = await responseToFile(res, filename);
    if (!file) throw new Error('Empty download');
    return file;
}

async function responseToFile(res: Response, filename: string): Promise<File | null> {
    const blob = await res.blob();
    if (blob.size === 0) return null;
    const headerType = (res.headers.get('Content-Type') || blob.type || '').split(';')[0].trim();
    const type =
        headerType && !headerType.includes('text/html') && !headerType.includes('application/json')
            ? headerType
            : guessMime(filename);
    // iOS Save Image / Save Video keys off MIME + extension.
    return new File([blob], filename, { type });
}

function guessMime(filename: string): string {
    const lower = filename.toLowerCase();
    if (lower.endsWith('.mp4') || lower.endsWith('.m4v')) return 'video/mp4';
    if (lower.endsWith('.webm')) return 'video/webm';
    if (lower.endsWith('.mov')) return 'video/quicktime';
    if (lower.endsWith('.mp3')) return 'audio/mpeg';
    if (lower.endsWith('.wav')) return 'audio/wav';
    if (lower.endsWith('.m4a')) return 'audio/mp4';
    if (lower.endsWith('.png')) return 'image/png';
    if (lower.endsWith('.webp')) return 'image/webp';
    if (lower.endsWith('.gif')) return 'image/gif';
    return 'image/jpeg';
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
