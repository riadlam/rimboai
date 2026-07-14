/**
 * Mirrors App\Services\Credits\VideoGenerationCostEstimator for live UI estimates.
 * credits = ceil( (fal_cost_usd * markup) / usd_per_credit )
 */

import { creditsFromFalUsd, type CreditsConfig } from '@/lib/imageCredits';

export type VideoCreditModel = {
    endpoint_id?: string | null;
    unit?: string | null;
    unit_price?: number | string | null;
};

export type VideoCreditOptions = {
    durationSeconds?: number;
    audio?: boolean;
    resolution?: string;
    aspect?: string;
};

export type VideoCreditEstimate = {
    falCostUsd: number;
    credits: number;
    billableUnits: number;
    unit: string;
    unitPrice: number;
};

export function estimateVideoCredits(
    model: VideoCreditModel | null | undefined,
    options: VideoCreditOptions = {},
    config?: CreditsConfig,
): VideoCreditEstimate {
    const endpointId = model?.endpoint_id || '';
    const unit = normalizeUnit(model?.unit);
    const unitPrice = normalizePrice(model?.unit_price);
    const durationSeconds = Math.max(1, options.durationSeconds ?? 5);
    const audio = Boolean(options.audio);
    const resolution = (options.resolution || '720p').toLowerCase();
    const aspect = options.aspect || '16:9';

    if (unit === 'tokens_per_1000' || (unit === 'units' && endpointId.toLowerCase().includes('seedance'))) {
        return estimateTokenPriced(endpointId, unitPrice, durationSeconds, resolution, aspect, config);
    }

    const audioMultiplier = audioMultiplierFor(endpointId, audio);
    const resolutionMultiplier = resolutionMultiplierFor(endpointId, resolution);
    const billableUnits = durationSeconds;
    const falCostUsd = round6(billableUnits * unitPrice * audioMultiplier * resolutionMultiplier);

    return {
        falCostUsd,
        credits: creditsFromFalUsd(falCostUsd, config),
        billableUnits,
        unit: unit || 'seconds',
        unitPrice,
    };
}

function estimateTokenPriced(
    endpointId: string,
    unitPrice: number,
    durationSeconds: number,
    resolution: string,
    aspect: string,
    config?: CreditsConfig,
): VideoCreditEstimate {
    const [width, height] = dimensionsFor(resolution, aspect);
    const tokens = (height * width * durationSeconds * 24) / 1024;
    let pricePerThousand = unitPrice > 0 ? unitPrice : 0.014;
    if (endpointId.toLowerCase().includes('seedance') && resolution === '4k') {
        pricePerThousand = 0.008;
    }
    const falCostUsd = round6((tokens / 1000) * pricePerThousand);

    return {
        falCostUsd,
        credits: creditsFromFalUsd(falCostUsd, config),
        billableUnits: Math.round(tokens * 10000) / 10000,
        unit: 'tokens_per_1000',
        unitPrice: pricePerThousand,
    };
}

function dimensionsFor(resolution: string, aspect: string): [number, number] {
    const base = resolution === '480p' ? 480 : resolution === '1080p' ? 1080 : resolution === '4k' ? 2160 : 720;
    const parts = aspect.split(':').map((p) => parseInt(p, 10));
    const aw = Number.isFinite(parts[0]) && parts[0] > 0 ? parts[0] : 16;
    const ah = Number.isFinite(parts[1]) && parts[1] > 0 ? parts[1] : 9;

    if (aw >= ah) {
        return [Math.round((base * aw) / ah), base];
    }
    return [base, Math.round((base * ah) / aw)];
}

function audioMultiplierFor(endpointId: string, audio: boolean): number {
    if (!audio) return 1;
    const id = endpointId.toLowerCase();
    if (id.includes('kling') && (id.includes('v3') || id.includes('/o3/') || id.includes('v2.6'))) return 1.5;
    return 1;
}

function resolutionMultiplierFor(endpointId: string, resolution: string): number {
    if (!endpointId.toLowerCase().includes('veo')) return 1;
    if (resolution === '4k') return 2;
    if (resolution === '1080p') return 1.5;
    return 1;
}

function normalizeUnit(unit?: string | null): string {
    const u = (unit || '').trim().toLowerCase();
    if (u === 'second' || u === 'seconds') return 'seconds';
    if (u === 'unit' || u === 'units') return 'units';
    if (u === 'tokens_per_1000' || u === 'tokens' || u === 'token') return 'tokens_per_1000';
    return u || 'seconds';
}

function normalizePrice(price?: number | string | null): number {
    if (price == null || price === '') return 0;
    const n = typeof price === 'number' ? price : parseFloat(price);
    return Number.isFinite(n) && n > 0 ? n : 0;
}

function round6(n: number): number {
    return Math.round(n * 1e6) / 1e6;
}
