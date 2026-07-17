/**
 * Dynamic credit estimates for video tools.
 * credits = ceil( (fal_cost_usd * markup) / usd_per_credit )
 */

import { creditsFromFalUsd, type CreditsConfig } from '@/lib/imageCredits';

export type ToolBilling = {
    unit: string;
    unit_price: number;
    /** When set, Fal $/unit changes with the selected output resolution. */
    unit_price_by_resolution?: Record<string, number> | null;
    max_duration?: number | null;
    ref_duration_seconds?: number | null;
};

export type ToolCreditOptions = {
    /** Measured or assumed input/output duration in seconds */
    durationSeconds?: number;
    /** Output / target resolution when unit is megapixels */
    resolution?: string;
    /** Assumed fps for megapixel / frames_30 billing */
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
    const duration = clampDuration(
        options.durationSeconds ?? billing.ref_duration_seconds ?? 5,
        billing.max_duration,
    );
    const fps = Math.max(1, options.fps ?? 24);

    if (unit === 'megapixels' || unit === 'processed_megapixels') {
        const [w, h] = RES_DIMS[resolution] ?? RES_DIMS['1080p'];
        const frames = Math.max(1, Math.round(duration * fps));
        const megapixels = (w * h * frames) / 1_000_000;
        const falCostUsd = round6(megapixels * unitPrice);
        return {
            falCostUsd,
            credits: creditsFromFalUsd(falCostUsd, config),
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
            credits: creditsFromFalUsd(falCostUsd, config),
            billableUnits: blocks,
            unit,
        };
    }

    if (unit === 'minutes') {
        const minutes = duration / 60;
        const falCostUsd = round6(minutes * unitPrice);
        return {
            falCostUsd,
            credits: creditsFromFalUsd(falCostUsd, config),
            billableUnits: round6(minutes),
            unit,
        };
    }

    if (unit === 'video') {
        // Flat per-clip pricing; PixVerse doubles past 5s.
        const multiplier = duration > 5 || options.videoLong ? 2 : 1;
        const falCostUsd = round6(unitPrice * multiplier);
        return {
            falCostUsd,
            credits: creditsFromFalUsd(falCostUsd, config),
            billableUnits: multiplier,
            unit,
        };
    }

    // Default: per second (or per extension second)
    const falCostUsd = round6(duration * unitPrice);
    return {
        falCostUsd,
        credits: creditsFromFalUsd(falCostUsd, config),
        billableUnits: duration,
        unit: unit || 'seconds',
    };
}

function resolveUnitPrice(billing: ToolBilling, resolution: string): number {
    const tiers = billing.unit_price_by_resolution;
    if (tiers && typeof tiers === 'object') {
        const keyed = tiers[resolution] ?? tiers[resolution.toLowerCase()];
        if (typeof keyed === 'number' && keyed > 0) return keyed;
    }
    return Number(billing.unit_price) || 0;
}

function clampDuration(seconds: number, max: number | null | undefined): number {
    let d = Math.max(1, Number.isFinite(seconds) ? seconds : 5);
    if (max && max > 0) d = Math.min(d, max);
    return d;
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
