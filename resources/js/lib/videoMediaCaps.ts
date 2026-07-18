import type { BrandModel } from '@/types';

export type MediaCaps = {
    supports_ref_images: boolean;
    supports_ref_videos: boolean;
    supports_ref_audio: boolean;
    supports_first_frame: boolean;
    supports_last_frame: boolean;
    last_frame_required: boolean;
    max_ref_images: number | null;
    max_ref_videos: number | null;
    max_ref_audios: number | null;
    first_last_frame_endpoint_id?: string | null;
};

export type MediaCounts = {
    images: number;
    videos: number;
    audios: number;
};

export type MediaRouteMode = 'text-to-video' | 'image-to-video' | 'reference-to-video' | 'first-last-frame-to-video';

export type FrameMode = 'default' | 'first_last';

const EMPTY_CAPS: MediaCaps = {
    supports_ref_images: false,
    supports_ref_videos: false,
    supports_ref_audio: false,
    supports_first_frame: false,
    supports_last_frame: false,
    last_frame_required: false,
    max_ref_images: null,
    max_ref_videos: null,
    max_ref_audios: null,
    first_last_frame_endpoint_id: null,
};

export function getMediaCaps(model: Pick<BrandModel, 'media_capabilities'> | null | undefined): MediaCaps {
    const caps = model?.media_capabilities;
    if (!caps) return EMPTY_CAPS;
    return {
        supports_ref_images: Boolean(caps.supports_ref_images),
        supports_ref_videos: Boolean(caps.supports_ref_videos),
        supports_ref_audio: Boolean(caps.supports_ref_audio),
        supports_first_frame: Boolean(caps.supports_first_frame),
        supports_last_frame: Boolean(caps.supports_last_frame),
        last_frame_required: Boolean(caps.last_frame_required),
        max_ref_images: typeof caps.max_ref_images === 'number' ? caps.max_ref_images : null,
        max_ref_videos: typeof caps.max_ref_videos === 'number' ? caps.max_ref_videos : null,
        max_ref_audios: typeof caps.max_ref_audios === 'number' ? caps.max_ref_audios : null,
        first_last_frame_endpoint_id: caps.first_last_frame_endpoint_id ?? null,
    };
}

export function mediaTotal(counts: MediaCounts): number {
    return counts.images + counts.videos + counts.audios;
}

/** Absolute ceiling across the catalog (Seedance-class multimodal). */
export const CATALOG_MEDIA_LIMITS = { image: 9, video: 3, audio: 3 } as const;

export type UploadLimits = {
    image: number;
    video: number;
    audio: number;
};

/**
 * Per-model upload ceilings from media_capabilities.
 * Falls back to catalog max when a type is supported but max_* is null.
 * Unsupported types → 0 so they can't be added while that model is selected.
 */
export function resolveUploadLimits(
    model: Pick<BrandModel, 'media_capabilities'> | null | undefined,
): UploadLimits {
    const caps = getMediaCaps(model);
    const total = mediaTotal({
        images: caps.max_ref_images ?? 0,
        videos: caps.max_ref_videos ?? 0,
        audios: caps.max_ref_audios ?? 0,
    });

    // No caps payload / empty model → allow catalog defaults (text-to-video start).
    if (!model?.media_capabilities && total === 0) {
        return { ...CATALOG_MEDIA_LIMITS };
    }

    const imageSupported = caps.supports_ref_images || caps.supports_first_frame || caps.supports_last_frame;
    const videoSupported = caps.supports_ref_videos;
    const audioSupported = caps.supports_ref_audio;

    let image = 0;
    if (imageSupported) {
        if (caps.max_ref_images !== null) {
            image = caps.max_ref_images;
        } else if (caps.supports_ref_images) {
            image = CATALOG_MEDIA_LIMITS.image;
        } else {
            // First-frame / FLF only — one (or two with last frame) image(s).
            image = caps.supports_last_frame ? 2 : 1;
        }
    }

    const video =
        videoSupported
            ? caps.max_ref_videos !== null
                ? caps.max_ref_videos
                : CATALOG_MEDIA_LIMITS.video
            : 0;

    const audio =
        audioSupported
            ? caps.max_ref_audios !== null
                ? caps.max_ref_audios
                : CATALOG_MEDIA_LIMITS.audio
            : 0;

    return { image, video, audio };
}

/**
 * Keep the earliest refs per kind that fit within limits; revoke URLs for dropped items.
 * @returns trimmed count (number of items removed)
 */
export function trimMediaToUploadLimits<T extends { kind: 'image' | 'video' | 'audio'; url?: string }>(
    items: T[],
    limits: UploadLimits,
    revokeUrl: (url: string) => void = () => undefined,
): { items: T[]; trimmed: number } {
    const kept = { image: 0, video: 0, audio: 0 };
    const out: T[] = [];
    let trimmed = 0;

    for (const item of items) {
        if (kept[item.kind] >= limits[item.kind]) {
            if (item.url) revokeUrl(item.url);
            trimmed += 1;
            continue;
        }
        kept[item.kind] += 1;
        out.push(item);
    }

    return { items: out, trimmed };
}

/** Mirrors App\Services\VideoModelCapabilities::supportsMediaMix */
export function supportsMediaMix(
    model: Pick<BrandModel, 'media_capabilities'> | null | undefined,
    counts: MediaCounts,
    frameMode: FrameMode = 'default',
): boolean {
    const images = counts.images;
    const videos = counts.videos;
    const audios = counts.audios;
    const total = mediaTotal(counts);

    if (total === 0) return true;

    const caps = getMediaCaps(model);

    if (frameMode === 'first_last') {
        if (!caps.supports_last_frame) return false;
        if (videos > 0 || audios > 0) return false;
        if (images < 1 || images > 2) return false;
        if (caps.last_frame_required && images < 2) return false;
        return true;
    }

    if (audios > 0 && images + videos === 0) return false;

    // Kling V2V edit (face/character swap): needs source video + face/element image.
    const endpointId = ('endpoint_id' in (model || {}) ? String((model as BrandModel).endpoint_id || '') : '').toLowerCase();
    if (endpointId.includes('video-to-video/edit')) {
        if (videos < 1 || images < 1) return false;
    }

    if (videos > 0 && !caps.supports_ref_videos) return false;
    if (audios > 0 && !caps.supports_ref_audio) return false;
    if (images > 1 && !caps.supports_ref_images) return false;
    if (images === 1 && videos === 0 && audios === 0) {
        if (!(caps.supports_first_frame || caps.supports_ref_images)) return false;
    } else if (images > 0 && !caps.supports_ref_images && !(images === 1 && caps.supports_first_frame && videos === 0 && audios === 0)) {
        return false;
    }

    if (images > 0 && caps.max_ref_images !== null && images > caps.max_ref_images) return false;
    if (videos > 0 && caps.max_ref_videos !== null && videos > caps.max_ref_videos) return false;
    if (audios > 0 && caps.max_ref_audios !== null && audios > caps.max_ref_audios) return false;

    return true;
}

export function resolveMediaRouteMode(
    model: Pick<BrandModel, 'media_capabilities'> | null | undefined,
    counts: MediaCounts,
    frameMode: FrameMode = 'default',
): MediaRouteMode | null {
    const total = mediaTotal(counts);
    if (total === 0) return 'text-to-video';
    if (!supportsMediaMix(model, counts, frameMode)) return null;

    const caps = getMediaCaps(model);

    if (frameMode === 'first_last' && caps.supports_last_frame) {
        if (counts.images >= 2) return 'first-last-frame-to-video';
        if (counts.images === 1 && !caps.last_frame_required && caps.supports_first_frame) {
            return 'image-to-video';
        }
        return null;
    }

    if (counts.images === 1 && counts.videos === 0 && counts.audios === 0 && caps.supports_first_frame) {
        return 'image-to-video';
    }
    if (caps.supports_ref_images || caps.supports_ref_videos || caps.supports_ref_audio) {
        return 'reference-to-video';
    }
    return null;
}

export type MediaGuidance = {
    tone: 'info' | 'warn' | 'error';
    title: string;
    body: string;
};

/**
 * User-facing guidance for the current media mix (no vendor names when possible).
 */
export function describeMediaGuidance(counts: MediaCounts, compatibleCount: number, frameMode: FrameMode = 'default'): MediaGuidance | null {
    const { images, videos, audios } = counts;
    const total = mediaTotal(counts);

    if (frameMode === 'first_last') {
        if (images === 0) {
            return {
                tone: 'info',
                title: 'First & last frame',
                body: 'Add a start image and an end image. The model animates the transition between them.',
            };
        }
        if (images === 1) {
            return {
                tone: 'warn',
                title: 'Add an end frame',
                body: 'Drop a last-frame image so the clip can finish on that shot.',
            };
        }
        return {
            tone: 'info',
            title: 'First → last frame',
            body: 'We’ll animate from your start image to your end image. Describe the motion in your prompt.',
        };
    }

    if (total === 0) return null;

    if (audios > 0 && images + videos === 0) {
        return {
            tone: 'error',
            title: 'Audio needs a visual reference',
            body: 'Add at least one image or video. Audio alone can’t drive a generation.',
        };
    }

    if (compatibleCount === 0) {
        return {
            tone: 'error',
            title: 'No compatible models',
            body: 'Remove some references or change the mix — nothing in the catalog supports this combination.',
        };
    }

    if (videos > 0 || audios > 0) {
        return {
            tone: 'info',
            title: 'Multimodal references',
            body: 'Video and audio refs need a multimodal model. We’ve hidden models that can’t use this mix.',
        };
    }

    if (images > 1) {
        const maxHint =
            images > 3
                ? '4+ images only work with multi-reference models. Lower-limit models are hidden so you don’t waste credits.'
                : 'We’ll keep models that can use multiple images. Prefer a simple motion prompt — avoid exact cut timing or long character sequences.';
        return {
            tone: images > 3 ? 'warn' : 'info',
            title: images > 3 ? 'Many image references' : 'Multi-image references',
            body: maxHint,
        };
    }

    if (images === 1) {
        return {
            tone: 'info',
            title: 'First-frame image',
            body: 'With one image, compatible models use it as the opening frame. Describe the motion in your prompt.',
        };
    }

    return null;
}

export function generateBlockReason(
    promptOk: boolean,
    counts: MediaCounts,
    model: Pick<BrandModel, 'media_capabilities' | 'name'> | null | undefined,
    compatibleCount: number,
    frameMode: FrameMode = 'default',
): string | null {
    if (!promptOk) return 'Add a prompt to generate.';

    if (frameMode === 'first_last') {
        const caps = getMediaCaps(model);
        if (!caps.supports_last_frame) {
            return 'This model doesn’t support first & last frame. Pick another model or turn the toggle off.';
        }
        if (counts.images < 1) return 'Add a first-frame image.';
        if (caps.last_frame_required && counts.images < 2) return 'Add a last-frame image.';
        return null;
    }

    if (counts.audios > 0 && counts.images + counts.videos === 0) {
        return 'Add an image or video — audio can’t be used alone.';
    }

    if (mediaTotal(counts) > 0 && compatibleCount === 0) {
        return 'No model supports this media mix. Remove some references.';
    }

    if (model && mediaTotal(counts) > 0 && !supportsMediaMix(model, counts, frameMode)) {
        return `${model.name} doesn’t support your current references. Pick a compatible model.`;
    }

    return null;
}
