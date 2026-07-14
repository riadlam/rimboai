import { creditsFromFalUsd, type CreditsConfig } from '@/lib/imageCredits';

const DEFAULT_CONFIG: CreditsConfig = {
    markup: 1.25,
    usd_per_credit: 0.01,
};

export type MusicModelPricing = {
    unit_price?: number | string | null;
    unit?: string | null;
    endpoint_id?: string | null;
    /** Hidden billing assumption — never exposed as a length slider */
    default_duration_seconds?: number | null;
    max_duration?: number | null;
    supports_audio?: boolean | null;
};

export type MusicCreditEstimate = {
    credits: number;
    falCostUsd: number;
    billableUnits: number;
    unit: string;
    assumedSeconds: number | null;
};

/**
 * Music credit estimate.
 * For time-based pricing (ACE-Step etc.), pass actual source audio seconds when available.
 */
export function estimateMusicCredits(
    model: MusicModelPricing | null | undefined,
    options: { autoEnhance?: boolean; durationSeconds?: number | null } = {},
    config: CreditsConfig = DEFAULT_CONFIG,
): MusicCreditEstimate {
    const rawPrice = model?.unit_price;
    const unitPrice =
        typeof rawPrice === 'number'
            ? rawPrice
            : Math.max(0, Number(String(rawPrice ?? '').replace(/[^0-9.\-eE]/g, '')) || 0);
    const unitRaw = String(model?.unit ?? '').toLowerCase().trim();
    const assumedSeconds = resolveAssumedSeconds(model, options.durationSeconds);

    if (unitPrice <= 0) {
        return { credits: 0, falCostUsd: 0, billableUnits: 0, unit: unitRaw || 'unknown', assumedSeconds };
    }

    let billableUnits = 1;
    let unitLabel = unitRaw || 'audios';

    if (unitRaw.includes('minute')) {
        const seconds = assumedSeconds ?? 120;
        billableUnits = Math.max(seconds / 60, 1 / 60);
        unitLabel = 'minutes';
    } else if (unitRaw.includes('second') || unitRaw.includes('compute')) {
        billableUnits = Math.max(assumedSeconds ?? 90, 1);
        unitLabel = unitRaw.includes('compute') ? 'compute seconds' : 'seconds';
    } else {
        // Flat per generation: audios / images / requests
        billableUnits = 1;
        unitLabel = unitRaw.includes('audio') ? 'audios' : unitRaw || 'audios';
    }

    const falCostUsd = Math.round(billableUnits * unitPrice * 1e6) / 1e6;
    let credits = falCostUsd > 0 ? Math.max(1, creditsFromFalUsd(falCostUsd, config)) : 0;

    // Product add-on (not fal) — keep tiny if enabled
    if (options.autoEnhance) {
        credits += 1;
    }

    return {
        falCostUsd,
        billableUnits,
        credits,
        unit: unitLabel,
        assumedSeconds,
    };
}

function resolveAssumedSeconds(
    model: MusicModelPricing | null | undefined,
    durationSeconds?: number | null,
): number | null {
    const max = typeof model?.max_duration === 'number' && model.max_duration > 0 ? model.max_duration : null;

    if (typeof durationSeconds === 'number' && Number.isFinite(durationSeconds) && durationSeconds > 0) {
        const rounded = Math.max(1, Math.ceil(durationSeconds));
        return max != null ? Math.min(max, rounded) : rounded;
    }

    const configured = model?.default_duration_seconds;
    if (typeof configured === 'number' && configured > 0) {
        return configured;
    }

    if (max != null) {
        // Typical song ≈ half of model max, capped to a sensible full-track default
        return Math.min(180, Math.max(60, Math.round(max * 0.5)));
    }

    const unitRaw = String(model?.unit ?? '').toLowerCase();
    if (unitRaw.includes('minute') || unitRaw.includes('second') || unitRaw.includes('compute')) {
        return 120;
    }

    return null;
}

/** Read duration (seconds) from a local audio File. */
export function readAudioFileDuration(file: File): Promise<number | null> {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file);
        const audio = document.createElement('audio');
        audio.preload = 'metadata';
        const done = (value: number | null) => {
            URL.revokeObjectURL(url);
            resolve(value);
        };
        audio.onloadedmetadata = () => {
            const d = audio.duration;
            done(Number.isFinite(d) && d > 0 ? d : null);
        };
        audio.onerror = () => done(null);
        audio.src = url;
    });
}

export function formatMusicDuration(seconds: number | null | undefined): string {
    if (seconds == null || !Number.isFinite(seconds) || seconds <= 0) return '';
    const total = Math.round(seconds);
    const m = Math.floor(total / 60);
    const s = total % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}
