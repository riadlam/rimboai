export type MediaType = 'images' | 'videos' | 'music';

export type CategoryId = string;

export type InnovationPost = {
    id: string;
    db_id?: number;
    title: string;
    category: string;
    category_label?: string;
    media: MediaType;
    media_type?: 'image' | 'video' | 'music';
    image: string;
    /** Extra sample frames when an innovation has multiple reference renders */
    images?: string[];
    video?: string | null;
    audio?: string | null;
    prompt: string;
    model: string;
    endpoint_id?: string | null;
    lab_type?: string;
    aspect_ratio?: string | null;
    resolution?: string | null;
    duration?: string | number | null;
    quantity?: number | null;
    generate_audio?: boolean | null;
    image_mode?: 'create' | 'variations' | string | null;
    style_prompt?: string | null;
    settings?: Record<string, unknown> | null;
    gradient?: string | null;
    is_featured?: boolean;
};

/** @deprecated alias for InnovationPost */
export type Prompt = InnovationPost;

export const CATEGORY_GRADIENTS: Record<string, string> = {
    'profile-avatar': 'from-purple-900/80 via-pink-900/60 to-violet-900/80',
    'social-media': 'from-blue-900/80 via-cyan-900/60 to-teal-900/80',
    infographic: 'from-emerald-900/80 via-green-900/60 to-lime-900/80',
    youtube: 'from-red-900/80 via-rose-900/60 to-pink-900/80',
    comic: 'from-amber-900/80 via-orange-900/60 to-yellow-900/80',
    'product-marketing': 'from-indigo-900/80 via-blue-900/60 to-sky-900/80',
    'e-commerce': 'from-fuchsia-900/80 via-purple-900/60 to-pink-900/80',
    'game-asset': 'from-orange-900/80 via-red-900/60 to-rose-900/80',
    poster: 'from-cyan-900/80 via-teal-900/60 to-emerald-900/80',
    'app-web-design': 'from-sky-900/80 via-blue-900/60 to-indigo-900/80',
    music: 'from-violet-900/80 via-fuchsia-900/60 to-purple-900/80',
    other: 'from-zinc-800/80 via-zinc-700/60 to-zinc-800/80',
};

export function categoryGradient(slug: string, fallback?: string | null): string {
    return fallback || CATEGORY_GRADIENTS[slug] || CATEGORY_GRADIENTS.other;
}

export function resolveLabType(post: InnovationPost): 'text-to-image' | 'text-to-video' | 'text-to-music' {
    if (post.lab_type === 'text-to-video' || post.lab_type === 'text-to-image' || post.lab_type === 'text-to-music') {
        return post.lab_type;
    }
    if (post.media === 'videos' || post.media_type === 'video') return 'text-to-video';
    if (post.media === 'music' || post.media_type === 'music') return 'text-to-music';
    return 'text-to-image';
}

export function labHrefForPost(post: InnovationPost): string {
    return `/lab?type=${encodeURIComponent(resolveLabType(post))}`;
}

const LYRIC_TAG_RE = /\[(intro|verse|pre[-\s]?chorus|chorus|post[-\s]?chorus|bridge|outro|hook|inst)\]/i;

function pickSetting<T>(post: InnovationPost, key: string, fallback: T): T {
    const direct = (post as Record<string, unknown>)[key];
    if (direct !== undefined && direct !== null && direct !== '') return direct as T;
    const settings = post.settings && typeof post.settings === 'object' ? post.settings : {};
    const nested = settings[key];
    if (nested !== undefined && nested !== null && nested !== '') return nested as T;
    return fallback;
}

/**
 * Build a Lab reuse draft from an Innovation post (image / video / music).
 */
export function buildInnovationLabDraft(post: InnovationPost): import('@/lib/labReuse').LabReuseDraft {
    const labType = resolveLabType(post);
    const lab = labType === 'text-to-video' ? 'video' : labType === 'text-to-music' ? 'music' : 'image';
    const rawPrompt = (post.prompt || '').trim();
    const stylePrompt = (post.style_prompt || pickSetting<string | null>(post, 'style', null) || '').trim();

    let prompt = rawPrompt;
    let lyrics: string | null = null;

    if (lab === 'music' && LYRIC_TAG_RE.test(rawPrompt)) {
        lyrics = rawPrompt;
        prompt =
            stylePrompt ||
            [post.category_label || post.category, post.model, 'emotional', 'modern mix']
                .filter(Boolean)
                .join(', ');
    } else if (lab === 'music' && stylePrompt) {
        prompt = stylePrompt;
        lyrics = rawPrompt || null;
    }

    const aspectDefault = lab === 'video' ? '16:9' : '1:1';
    const resolutionDefault = lab === 'video' ? '720p' : '1K';
    const aspect = String(post.aspect_ratio || pickSetting(post, 'aspect', aspectDefault) || aspectDefault);
    const resolution = String(post.resolution || pickSetting(post, 'resolution', resolutionDefault) || resolutionDefault);

    let duration: number | 'auto' | string | null = null;
    if (lab === 'video') {
        const rawDuration = post.duration ?? pickSetting<string | number | null>(post, 'duration', '5');
        if (rawDuration === 'auto' || rawDuration === 'Auto') {
            duration = 'auto';
        } else if (typeof rawDuration === 'number') {
            duration = rawDuration;
        } else if (typeof rawDuration === 'string' && rawDuration.trim() !== '') {
            const n = Number.parseInt(rawDuration, 10);
            duration = Number.isFinite(n) ? n : rawDuration;
        } else {
            duration = 5;
        }
    }

    const quantityRaw = post.quantity ?? pickSetting<number>(post, 'quantity', 1);
    const quantity = Math.max(1, Math.min(4, Number(quantityRaw) || 1));

    const audioRaw = post.generate_audio ?? pickSetting<boolean | null>(post, 'audio', lab === 'video' ? true : null);
    const audio = lab === 'video' ? Boolean(audioRaw ?? true) : null;

    const imageModeRaw = post.image_mode ?? pickSetting<string | null>(post, 'image_mode', lab === 'image' ? 'create' : null);
    const imageMode =
        lab === 'image'
            ? imageModeRaw === 'variations'
                ? 'variations'
                : 'create'
            : null;

    return {
        id: `innovation-${post.id}-${Date.now()}`,
        lab,
        intent: 'reuse-settings',
        prompt,
        lyrics,
        modelName: post.model || null,
        endpointId: post.endpoint_id || null,
        aspect,
        resolution,
        duration,
        audio,
        quantity,
        imageMode,
        media: [],
    };
}
