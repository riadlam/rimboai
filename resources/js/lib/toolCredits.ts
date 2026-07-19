/**
 * Dynamic credit estimates for video tools.
 * credits = ceil( (fal_cost_usd * markup) / usd_per_credit )
 */

import { creditsFromFalUsd, type CreditsConfig } from '@/lib/imageCredits';

export type ToolBilling = {
    endpoint_id?: string | null;
    unit: string;
    unit_price: number;
    /** When set, Fal $/unit changes with the selected output resolution. */
    unit_price_by_resolution?: Record<string, number> | null;
    max_duration?: number | null;
    ref_duration_seconds?: number | null;
    /**
     * Supported billable duration steps (seconds).
     * Uploaded/selected length is snapped UP to the next value
     * (e.g. 4s → 5 when enums are [5, 10]).
     */
    duration_enums?: number[] | null;
};

export type ToolCreditOptions = {
    /** Measured or assumed input/output duration in seconds */
    durationSeconds?: number;
    /** Output / target resolution when unit is megapixels */
    resolution?: string;
    /** Source video fps (Animate Move) or assumed fps for megapixel / frames_30 */
    fps?: number;
    /** Flat video units (PixVerse-style: doubles when duration > 5s) */
    videoLong?: boolean;
};

export type ToolCreditEstimate = {
    falCostUsd: number;
    credits: number;
    billableUnits: number;
    unit: string;
};

const DEFAULT_CONFIG: CreditsConfig = {
    markup: 1.25,
    usd_per_credit: 0.01,
};

/** User-facing credit floor (mirror ToolGenerationCostEstimator). */
const MIN_TOOL_CREDITS = 45;

function toolCreditsFromFalUsd(falCostUsd: number, config: CreditsConfig = DEFAULT_CONFIG): number {
    const credits = creditsFromFalUsd(falCostUsd, config);
    if (credits > 0 && credits < MIN_TOOL_CREDITS) {
        return MIN_TOOL_CREDITS;
    }
    return credits;
}

const RES_DIMS: Record<string, [number, number]> = {
    '360p': [640, 360],
    '480p': [854, 480],
    '540p': [960, 540],
    '580p': [1024, 576],
    '720p': [1280, 720],
    '1080p': [1920, 1080],
    '1440p': [2560, 1440],
    '2k': [2560, 1440],
    '2160p': [3840, 2160],
    '4k': [3840, 2160],
};

/**
 * Snap measured duration UP to the smallest supported enum ≥ duration.
 * If longer than every enum, clamp to the max enum.
 */
export function snapBillableDuration(
    seconds: number,
    enums: number[] | null | undefined,
    maxDuration?: number | null,
): number {
    const raw = Number.isFinite(seconds) && seconds > 0 ? seconds : 0;
    if (raw <= 0) return 0;

    const steps = (enums ?? [])
        .map((n) => Number(n))
        .filter((n) => Number.isFinite(n) && n > 0)
        .sort((a, b) => a - b);

    if (steps.length === 0) {
        // No discrete tiers: bill whole seconds (ceil), still respect max.
        let d = Math.max(1, Math.ceil(raw - 1e-9));
        if (maxDuration && maxDuration > 0) d = Math.min(d, maxDuration);
        return d;
    }

    for (const step of steps) {
        // 50ms tolerance so 5.04s metadata doesn't snap to 6s.
        if (step + 0.05 >= raw) return step;
    }

    const last = steps[steps.length - 1];
    if (maxDuration && maxDuration > 0) return Math.min(last, maxDuration);
    return last;
}

export function usesWan22GeneratedVideoSeconds(endpointId: string | null | undefined): boolean {
    return String(endpointId || '')
        .toLowerCase()
        .includes('wan/v2.2-a14b/video-to-video');
}

export function usesWan22InputFrameBilling(endpointId: string | null | undefined): boolean {
    const id = String(endpointId || '').toLowerCase();
    return id.includes('wan/v2.2-14b/animate/move') || id.includes('wan/v2.2-14b/animate/replace');
}

/** @deprecated */
export function usesWan22VideoSeconds(endpointId: string | null | undefined): boolean {
    return usesWan22GeneratedVideoSeconds(endpointId) || usesWan22InputFrameBilling(endpointId);
}

/** Match Lab / PHP: odd frame count in [17, 161] at 16fps. */
export function wan22FramesForDuration(durationSeconds: number): number {
    const fps = 16;
    let frames = Math.max(17, Math.min(161, Math.round(Math.max(0, durationSeconds) * fps) + 1));
    if (frames % 2 === 0) frames = Math.min(161, frames + 1);
    return frames;
}

export function isFlatVideoUnit(unit: string | null | undefined): boolean {
    const normalized = String(unit || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '_');
    return normalized === 'video' || normalized === 'videos' || normalized === 'video_segments';
}

export function estimateToolCredits(
    billing: ToolBilling | null | undefined,
    options: ToolCreditOptions = {},
    config: CreditsConfig = DEFAULT_CONFIG,
): ToolCreditEstimate {
    if (!billing || !(billing.unit_price > 0)) {
        return { falCostUsd: 0, credits: 0, billableUnits: 0, unit: billing?.unit || 'seconds' };
    }

    const unit = normalizeUnit(billing.unit);
    const resolution = (options.resolution || '1080p').toLowerCase();
    const unitPrice = resolveUnitPrice(billing, resolution);
    const duration = snapBillableDuration(
        options.durationSeconds ?? 0,
        billing.duration_enums,
        billing.max_duration,
    );
    if (!(duration > 0)) {
        return { falCostUsd: 0, credits: 0, billableUnits: 0, unit: unit || 'seconds' };
    }
    const fps = Math.max(1, options.fps ?? 24);

    if (unit === 'megapixels' || unit === 'processed_megapixels') {
        const [w, h] = RES_DIMS[resolution] ?? RES_DIMS['1080p'];
        const frames = Math.max(1, Math.round(duration * fps));
        const megapixels = (w * h * frames) / 1_000_000;
        const falCostUsd = round6(megapixels * unitPrice);
        return {
            falCostUsd,
            credits: toolCreditsFromFalUsd(falCostUsd, config),
            billableUnits: round6(megapixels),
            unit,
        };
    }

    if (unit === 'frames_30') {
        const frames = Math.max(1, Math.round(duration * (options.fps ?? 30)));
        const blocks = Math.max(1, Math.ceil(frames / 30));
        const falCostUsd = round6(blocks * unitPrice);
        return {
            falCostUsd,
            credits: toolCreditsFromFalUsd(falCostUsd, config),
            billableUnits: blocks,
            unit,
        };
    }

    if (unit === 'minutes') {
        const minutes = duration / 60;
        const falCostUsd = round6(minutes * unitPrice);
        return {
            falCostUsd,
            credits: toolCreditsFromFalUsd(falCostUsd, config),
            billableUnits: round6(minutes),
            unit,
        };
    }

    if (unit === 'compute_seconds') {
        const falCostUsd = round6(duration * unitPrice);
        return {
            falCostUsd,
            credits: toolCreditsFromFalUsd(falCostUsd, config),
            billableUnits: duration,
            unit,
        };
    }

    if (isFlatVideoUnit(unit)) {
        // Flat per-clip pricing; PixVerse doubles past 5s.
        const multiplier = duration > 5 || options.videoLong ? 2 : 1;
        const falCostUsd = round6(unitPrice * multiplier);
        return {
            falCostUsd,
            credits: toolCreditsFromFalUsd(falCostUsd, config),
            billableUnits: multiplier,
            unit: 'video',
        };
    }

    // Wan Animate Move: Fal bills (wall_duration × input_fps) / 16.
    if (usesWan22InputFrameBilling(billing.endpoint_id)) {
        const inputFps = Math.max(1, options.fps ?? 30);
        const inputFrames = Math.max(1, Math.round(duration * inputFps));
        const videoSeconds = inputFrames / 16;
        const falCostUsd = round6(videoSeconds * unitPrice);
        return {
            falCostUsd,
            credits: toolCreditsFromFalUsd(falCostUsd, config),
            billableUnits: round6(videoSeconds),
            unit: 'video_seconds_input_fps',
        };
    }

    // Wan 2.2 A14B v2v: num_frames at 16fps.
    if (usesWan22GeneratedVideoSeconds(billing.endpoint_id)) {
        const frames = wan22FramesForDuration(duration);
        const videoSeconds = frames / 16;
        const falCostUsd = round6(videoSeconds * unitPrice);
        return {
            falCostUsd,
            credits: toolCreditsFromFalUsd(falCostUsd, config),
            billableUnits: round6(videoSeconds),
            unit: 'video_seconds_16fps',
        };
    }

    // Default: per second (or per extension second)
    const falCostUsd = round6(duration * unitPrice);
    return {
        falCostUsd,
        credits: toolCreditsFromFalUsd(falCostUsd, config),
        billableUnits: duration,
        unit: unit || 'seconds',
    };
}

function resolveUnitPrice(billing: ToolBilling, resolution: string): number {
    const tiers = billing.unit_price_by_resolution;
    if (tiers && typeof tiers === 'object') {
        const keyed = tiers[resolution] ?? tiers[resolution.toLowerCase()];
        if (typeof keyed === 'number' && keyed > 0) return keyed;
        // UI may omit resolution or pick an unsupported tier (e.g. 1080p on PixVerse Swap).
        for (const prefer of ['720p', '540p', '480p', '360p']) {
            const v = tiers[prefer];
            if (typeof v === 'number' && v > 0) return v;
        }
        const first = Object.values(tiers).find((v) => typeof v === 'number' && v > 0);
        if (typeof first === 'number') return first;
    }
    return Number(billing.unit_price) || 0;
}

function normalizeUnit(unit: string | null | undefined): string {
    return String(unit || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '_');
}

function round6(n: number): number {
    return Math.round(n * 1_000_000) / 1_000_000;
}
