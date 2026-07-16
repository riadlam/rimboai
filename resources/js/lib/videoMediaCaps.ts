import type { BrandModel } from '@/types';

export type MediaCaps = {
    supports_ref_images: boolean;
    supports_ref_videos: boolean;
    supports_ref_audio: boolean;
    supports_first_frame: boolean;
    max_ref_images: number | null;
    max_ref_videos: number | null;
    max_ref_audios: number | null;
};

export type MediaCounts = {
    images: number;
    videos: number;
    audios: number;
};

export type MediaRouteMode = 'text-to-video' | 'image-to-video' | 'reference-to-video';

const EMPTY_CAPS: MediaCaps = {
    supports_ref_images: false,
    supports_ref_videos: false,
    supports_ref_audio: false,
    supports_first_frame: false,
    max_ref_images: null,
    max_ref_videos: null,
    max_ref_audios: null,
};

export function getMediaCaps(model: Pick<BrandModel, 'media_capabilities'> | null | undefined): MediaCaps {
    const caps = model?.media_capabilities;
    if (!caps) return EMPTY_CAPS;
    return {
        supports_ref_images: Boolean(caps.supports_ref_images),
        supports_ref_videos: Boolean(caps.supports_ref_videos),
        supports_ref_audio: Boolean(caps.supports_ref_audio),
        supports_first_frame: Boolean(caps.supports_first_frame),
        max_ref_images: typeof caps.max_ref_images === 'number' ? caps.max_ref_images : null,
        max_ref_videos: typeof caps.max_ref_videos === 'number' ? caps.max_ref_videos : null,
        max_ref_audios: typeof caps.max_ref_audios === 'number' ? caps.max_ref_audios : null,
    };
}

export function mediaTotal(counts: MediaCounts): number {
    return counts.images + counts.videos + counts.audios;
}

/** Mirrors App\Services\VideoModelCapabilities::supportsMediaMix */
export function supportsMediaMix(model: Pick<BrandModel, 'media_capabilities'> | null | undefined, counts: MediaCounts): boolean {
    const images = counts.images;
    const videos = counts.videos;
    const audios = counts.audios;
    const total = mediaTotal(counts);

    if (total === 0) return true;
    if (audios > 0 && images + videos === 0) return false;

    const caps = getMediaCaps(model);

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

export function resolveMediaRouteMode(model: Pick<BrandModel, 'media_capabilities'> | null | undefined, counts: MediaCounts): MediaRouteMode | null {
    const total = mediaTotal(counts);
    if (total === 0) return 'text-to-video';
    if (!supportsMediaMix(model, counts)) return null;

    const caps = getMediaCaps(model);
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
export function describeMediaGuidance(counts: MediaCounts, compatibleCount: number): MediaGuidance | null {
    const { images, videos, audios } = counts;
    const total = mediaTotal(counts);

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

    if (images > 3) {
        return {
            tone: 'warn',
            title: 'Many image references',
            body: '4+ images only work with multi-reference models. Lower-limit models are hidden so you don’t waste credits.',
        };
    }

    if (images > 1) {
        return {
            tone: 'info',
            title: 'Multi-image references',
            body: 'We’ll keep models that can use multiple images. Prefer a simple motion prompt — avoid exact cut timing or long character sequences.',
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
): string | null {
    if (!promptOk) return 'Add a prompt to generate.';

    if (counts.audios > 0 && counts.images + counts.videos === 0) {
        return 'Add an image or video — audio can’t be used alone.';
    }

    if (mediaTotal(counts) > 0 && compatibleCount === 0) {
        return 'No model supports this media mix. Remove some references.';
    }

    if (model && mediaTotal(counts) > 0 && !supportsMediaMix(model, counts)) {
        return `${model.name} doesn’t support your current references. Pick a compatible model.`;
    }

    return null;
}
