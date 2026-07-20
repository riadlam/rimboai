/**
 * Lab UI helpers for per-model aspect / resolution chips.
 */

const VIDEO_DEFAULT_ASPECTS = ['16:9', '9:16', '1:1', '4:5', '3:4'] as const;
const VIDEO_DEFAULT_RESOLUTIONS = ['720p', '1080p', '4K'] as const;
const IMAGE_DEFAULT_ASPECTS = ['1:1', '16:9', '9:16', '4:5', '3:4'] as const;
const IMAGE_DEFAULT_RESOLUTIONS = ['1K', '2K', '4K'] as const;

const KNOWN_ASPECT_META: Record<string, { w: number; h: number; label: string }> = {
    '21:9': { w: 21, h: 9, label: 'Ultra wide' },
    '16:9': { w: 18, h: 10, label: 'Wide' },
    '4:3': { w: 16, h: 12, label: 'Classic' },
    '3:2': { w: 15, h: 10, label: 'Photo' },
    '1:1': { w: 14, h: 14, label: 'Square' },
    '4:5': { w: 12, h: 15, label: 'Social' },
    '3:4': { w: 12, h: 16, label: 'Portrait' },
    '2:3': { w: 10, h: 15, label: 'Tall' },
    '9:16': { w: 10, h: 18, label: 'Vertical' },
    auto: { w: 14, h: 14, label: 'Auto' },
};

function asStringList(value: unknown): string[] {
    if (!Array.isArray(value)) return [];
    return value
        .filter((v): v is string | number => typeof v === 'string' || typeof v === 'number')
        .map((v) => String(v).trim())
        .filter(Boolean);
}

export function normalizeVideoResolution(value: string): string {
    const lower = value.toLowerCase();
    if (lower === '4k' || lower === '2160' || lower === '2160p') return '4K';
    if (lower === '1080' || lower === '1080p') return '1080p';
    if (lower === '720' || lower === '720p') return '720p';
    if (lower === '480' || lower === '480p') return '480p';
    return value;
}

export function normalizeImageResolution(value: string): string {
    const lower = value.toLowerCase();
    if (lower === '1k' || lower === '1024' || lower === '1024p') return '1K';
    if (lower === '2k' || lower === '2048' || lower === '2048p') return '2K';
    if (lower === '4k' || lower === '4096' || lower === '4096p') return '4K';
    if (/^\d+k$/i.test(value)) return value.toUpperCase();
    return value;
}

export function videoAspectOptions(fromModel?: string[] | null): string[] {
    const list = asStringList(fromModel).map((v) => v);
    return list.length > 0 ? Array.from(new Set(list)) : [...VIDEO_DEFAULT_ASPECTS];
}

export function videoResolutionOptions(fromModel?: string[] | null): Array<{ id: string; sub: string }> {
    const list = asStringList(fromModel).map(normalizeVideoResolution);
    const ids = list.length > 0 ? Array.from(new Set(list)) : [...VIDEO_DEFAULT_RESOLUTIONS];
    return ids.map((id) => ({
        id,
        sub: id === '4K' ? 'Cinema' : id === '1080p' ? 'HD' : id === '480p' ? 'Lite' : 'Standard',
    }));
}

export function imageAspectOptions(fromModel?: string[] | null): string[] {
    const list = asStringList(fromModel);
    return list.length > 0 ? Array.from(new Set(list)) : [...IMAGE_DEFAULT_ASPECTS];
}

export function imageResolutionOptions(fromModel?: string[] | null): Array<{ id: string; subKey: 'fast' | 'balanced' | 'max' | 'custom' }> {
    const list = asStringList(fromModel).map(normalizeImageResolution);
    const ids = list.length > 0 ? Array.from(new Set(list)) : [...IMAGE_DEFAULT_RESOLUTIONS];
    return ids.map((id) => ({
        id,
        subKey: id === '1K' ? 'fast' : id === '2K' ? 'balanced' : id === '4K' ? 'max' : 'custom',
    }));
}

export function aspectBox(key: string): { w: number; h: number; label: string } {
    if (KNOWN_ASPECT_META[key]) return KNOWN_ASPECT_META[key];
    const parts = key.split(':').map((p) => Number(p));
    if (parts.length === 2 && parts[0] > 0 && parts[1] > 0) {
        const scale = 18 / Math.max(parts[0], parts[1]);
        return {
            w: Math.max(8, Math.round(parts[0] * scale)),
            h: Math.max(8, Math.round(parts[1] * scale)),
            label: key,
        };
    }
    return { w: 14, h: 14, label: key };
}

export function pickSupportedValue<T extends string>(current: T | string, options: string[], fallback: string): string {
    if (options.includes(current)) return current;
    if (options.includes(fallback)) return fallback;
    return options[0] ?? fallback;
}
