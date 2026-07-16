/**
 * Shared reuse / use-result drafts for Lab create forms.
 * History can stash a draft via saveLabReuseDraft() then visit /lab?type=…
 */

export type LabReuseMediaKind = 'image' | 'video' | 'audio';

export type LabReuseMediaItem = {
    url: string;
    kind: LabReuseMediaKind;
    name?: string | null;
    fallbackUrls?: string[] | null;
};

export type LabReuseDraft = {
    /** Bumps whenever the user clicks Reuse / Use so forms re-apply */
    id: string;
    lab: 'image' | 'video' | 'music';
    intent: 'reuse-settings' | 'use-result';
    prompt: string;
    /** Optional lyrics payload (music lab) */
    lyrics?: string | null;
    modelName?: string | null;
    endpointId?: string | null;
    aspect?: string | null;
    resolution?: string | null;
    duration?: number | 'auto' | string | null;
    audio?: boolean | null;
    quantity?: number | null;
    imageMode?: 'create' | 'variations' | null;
    media: LabReuseMediaItem[];
};

export type LabReuseSource = {
    id: string;
    prompt: string;
    src: string;
    videoUrl?: string | null;
    method?: string | null;
    modelName?: string | null;
    aspect?: string | null;
    resolution?: string | null;
    duration?: number | string | null;
    audio?: boolean | null;
    quantity?: number | null;
    imageMode?: 'create' | 'variations' | null;
    inputAssets?: LabReuseMediaItem[] | null;
};

const STORAGE_KEY = 'chameleon.labReuseDraft';

function draftId(prefix: string): string {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

function isVideoMethod(method?: string | null, videoUrl?: string | null): boolean {
    return (
        method === 'text-to-video' ||
        method === 'image-to-video' ||
        method === 'reference-to-video' ||
        Boolean(videoUrl)
    );
}

function normalizeSourceMedia(items?: LabReuseMediaItem[] | null): LabReuseMediaItem[] {
    if (!items?.length) return [];
    return items
        .filter((m) => m?.url)
        .map((m) => ({
            url: m.url,
            kind: m.kind,
            name: m.name ?? null,
            fallbackUrls: m.fallbackUrls ?? null,
        }));
}

export function buildReuseSettingsDraft(source: LabReuseSource): LabReuseDraft {
    const video = isVideoMethod(source.method, source.videoUrl);
    return {
        id: draftId('reuse'),
        lab: video ? 'video' : 'image',
        intent: 'reuse-settings',
        prompt: source.prompt ?? '',
        modelName: source.modelName ?? null,
        aspect: source.aspect ?? (video ? '16:9' : '1:1'),
        resolution: source.resolution ?? (video ? '720p' : '1K'),
        duration: source.duration ?? (video ? 5 : null),
        audio: source.audio ?? (video ? true : null),
        quantity: source.quantity ?? 1,
        imageMode: source.imageMode ?? (source.method === 'image-to-image' ? 'variations' : 'create'),
        media: normalizeSourceMedia(source.inputAssets),
    };
}

export function buildUseResultDraft(source: LabReuseSource): LabReuseDraft {
    const video = isVideoMethod(source.method, source.videoUrl);
    const resultUrl = video ? source.videoUrl || source.src : source.src;
    const kind: LabReuseMediaKind = video ? 'video' : 'image';
    const fallbackUrls =
        video && source.videoUrl && source.src && source.src !== source.videoUrl ? [source.src] : [];

    return {
        id: draftId('use-result'),
        lab: video ? 'video' : 'image',
        intent: 'use-result',
        prompt: '',
        modelName: source.modelName ?? null,
        aspect: source.aspect ?? (video ? '16:9' : '1:1'),
        resolution: source.resolution ?? (video ? '720p' : '1K'),
        duration: source.duration ?? (video ? 5 : null),
        audio: source.audio ?? (video ? true : null),
        quantity: 1,
        // "Use Image/Video" always returns to Create (not Variations / Remix refs).
        // Attach the result as an optional reference on the Create tab.
        imageMode: video ? null : 'create',
        media: resultUrl
            ? [
                  {
                      url: resultUrl,
                      kind,
                      name: video ? `result-${source.id}.mp4` : `result-${source.id}.jpg`,
                      fallbackUrls,
                  },
              ]
            : [],
    };
}

export function saveLabReuseDraft(draft: LabReuseDraft): void {
    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(draft));
    } catch {
        /* ignore quota / private mode */
    }
}

/** Read + clear a draft stashed by History (or deep links). */
export function consumeLabReuseDraft(expectedLab?: LabReuseDraft['lab']): LabReuseDraft | null {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        sessionStorage.removeItem(STORAGE_KEY);
        const parsed = JSON.parse(raw) as LabReuseDraft;
        if (!parsed?.id || !parsed.lab) return null;
        if (expectedLab && parsed.lab !== expectedLab) {
            // Wrong lab type — put it back for the correct page
            sessionStorage.setItem(STORAGE_KEY, raw);
            return null;
        }
        return parsed;
    } catch {
        return null;
    }
}

function extensionForKind(kind: LabReuseMediaKind, contentType: string): string {
    if (contentType.includes('png')) return 'png';
    if (contentType.includes('webp')) return 'webp';
    if (contentType.includes('gif')) return 'gif';
    if (contentType.includes('webm')) return 'webm';
    if (contentType.includes('quicktime') || contentType.includes('mov')) return 'mov';
    if (contentType.includes('mpeg') || contentType.includes('mp3')) return 'mp3';
    if (contentType.includes('wav')) return 'wav';
    if (kind === 'video') return 'mp4';
    if (kind === 'audio') return 'mp3';
    return 'jpg';
}

function mimeForKind(kind: LabReuseMediaKind, contentType: string): string {
    if (contentType && contentType !== 'application/octet-stream') return contentType.split(';')[0].trim();
    if (kind === 'video') return 'video/mp4';
    if (kind === 'audio') return 'audio/mpeg';
    return 'image/jpeg';
}

function resolveFetchUrl(raw: string): string {
    if (raw.startsWith('blob:') || raw.startsWith('/')) return raw;
    const absolute = raw.startsWith('http://') || raw.startsWith('https://');
    if (absolute && raw.startsWith(window.location.origin)) return raw;
    if (!absolute) {
        // relative path without leading slash
        return raw.startsWith('storage/') ? `/${raw}` : raw;
    }
    return `/lab/asset-fetch?url=${encodeURIComponent(raw)}`;
}

async function fetchUrlAsFile(
    rawUrl: string,
    kind: LabReuseMediaKind,
    name: string | null | undefined,
    signal?: AbortSignal,
): Promise<File> {
    const fetchUrl = resolveFetchUrl(rawUrl);
    const res = await fetch(fetchUrl, {
        credentials: 'same-origin',
        headers: { Accept: '*/*', 'X-Requested-With': 'XMLHttpRequest' },
        signal,
    });
    if (!res.ok) {
        throw new Error(`Failed to load asset (${res.status})`);
    }

    const blob = await res.blob();
    if (blob.size === 0) {
        throw new Error('Empty asset');
    }

    // Login HTML / JSON error pages sometimes come back as 200
    const sniff = (blob.type || '').toLowerCase();
    if (sniff.includes('text/html') || sniff.includes('application/json')) {
        throw new Error('Unexpected asset response');
    }

    const contentType = blob.type || res.headers.get('Content-Type') || '';
    const mime = mimeForKind(kind, contentType);
    const ext = extensionForKind(kind, mime);
    const base = (name || `${kind}-ref`).replace(/\.[^.]+$/, '');
    return new File([blob], `${base}.${ext}`, { type: mime });
}

/** Fetch a remote/local asset as a File (uses same-origin proxy for fal CDN). */
export async function fetchAssetAsFile(
    item: LabReuseMediaItem,
    signal?: AbortSignal,
): Promise<File> {
    const urls = [item.url, ...(item.fallbackUrls ?? [])].filter(Boolean);
    let lastError: unknown;
    for (const url of urls) {
        try {
            return await fetchUrlAsFile(url, item.kind, item.name, signal);
        } catch (err) {
            if (signal?.aborted) throw err;
            lastError = err;
        }
    }
    throw lastError instanceof Error ? lastError : new Error('Failed to load asset');
}

export type LoadDraftMediaResult = {
    files: File[];
    /** Parallel to draft.media indexes that succeeded */
    kinds: LabReuseMediaKind[];
    failed: number;
    attempted: number;
};

/** Load media for a draft; does not throw if some/all items fail. */
export async function loadDraftMediaFiles(
    media: LabReuseMediaItem[],
    signal?: AbortSignal,
): Promise<LoadDraftMediaResult> {
    const files: File[] = [];
    const kinds: LabReuseMediaKind[] = [];
    let failed = 0;
    let attempted = 0;

    for (const item of media) {
        if (!item.url) continue;
        attempted += 1;
        try {
            const file = await fetchAssetAsFile(item, signal);
            files.push(file);
            kinds.push(item.kind);
        } catch (err) {
            if (signal?.aborted) throw err;
            failed += 1;
        }
    }

    return { files, kinds, failed, attempted };
}

export function parseDurationDraft(value: unknown): number | 'auto' | null {
    if (value === 'auto' || value === 'Auto') return 'auto';
    if (typeof value === 'number' && Number.isFinite(value)) return value;
    if (typeof value === 'string') {
        const trimmed = value.trim();
        if (trimmed.toLowerCase() === 'auto') return 'auto';
        const n = Number.parseInt(trimmed.replace(/s$/i, ''), 10);
        return Number.isFinite(n) ? n : null;
    }
    return null;
}

type MatchableModel = {
    name: string;
    brandName: string;
    endpoint_id?: string | null;
    supports_audio?: boolean | null;
};

/** Match a draft model by endpoint id, exact name, then fuzzy name. */
export function matchLabModel<T extends MatchableModel>(
    models: T[],
    opts: { modelName?: string | null; endpointId?: string | null; preferNoAudioInput?: boolean } = {},
): T | null {
    if (!models.length) return null;
    const endpoint = (opts.endpointId || '').trim().toLowerCase();
    if (endpoint) {
        const byEp = models.find((m) => String(m.endpoint_id || '').toLowerCase() === endpoint);
        if (byEp) return byEp;
    }
    const name = (opts.modelName || '').trim().toLowerCase();
    if (name) {
        const exact = models.find((m) => m.name.toLowerCase() === name);
        if (exact) return exact;
        const fuzzy = models.find(
            (m) => m.name.toLowerCase().includes(name) || name.includes(m.name.toLowerCase()),
        );
        if (fuzzy) return fuzzy;
    }
    if (opts.preferNoAudioInput) {
        const textOnly = models.find((m) => !m.supports_audio);
        if (textOnly) return textOnly;
    }
    return models[0] || null;
}
