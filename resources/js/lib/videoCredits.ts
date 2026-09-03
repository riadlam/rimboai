/**
 * Mirrors App\Services\Credits\VideoGenerationCostEstimator + FalEndpointPricingPolicy.
 * credits = ceil( (fal_cost_usd * markup) / usd_per_credit )
 * Optional floor from creditsConfig.min_credits.* when > 0.
 */

import { applyCreditFloor, creditsFromFalUsd, type CreditsConfig } from '@/lib/imageCredits';

export type VideoCreditModel = {
    endpoint_id?: string | null;
    unit?: string | null;
    unit_price?: number | string | null;
};

export type VideoCreditOptions = {
    durationSeconds?: number;
    audio?: boolean;
    voiceControl?: boolean;
    resolution?: string;
    aspect?: string;
    referenceVideoSeconds?: number;
    referenceImageCount?: number;
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
    const endpointId = (model?.endpoint_id || '').toLowerCase();
    const unit = normalizeUnit(model?.unit);
    const catalogPrice = normalizePrice(model?.unit_price);
    const durationSeconds = Math.max(1, options.durationSeconds ?? 5);
    const audio = Boolean(options.audio);
    const voice = Boolean(options.voiceControl);
    const resolution = (options.resolution || '720p').toLowerCase();
    const aspect = options.aspect || '16:9';
    const refVideo = Math.max(0, options.referenceVideoSeconds ?? 0);
    const refImages = Math.max(0, options.referenceImageCount ?? 0);

    if (endpointId.includes('grok-imagine-video')) {
        const perSecond = resolution === '480p' ? 0.05 : resolution === '1080p' ? 0.14 : 0.07;
        const imageFee = endpointId.includes('image-to-video') ? refImages * 0.002 : 0;
        return present(round6(durationSeconds * perSecond + imageFee), durationSeconds, 'seconds', perSecond, config);
    }

    if (endpointId.includes('pixverse/c1/reference-to-video')) {
        const silent = resolution === '360p' ? 0.03 : resolution === '540p' ? 0.04 : resolution === '1080p' ? 0.095 : 0.05;
        const withAudio = resolution === '360p' ? 0.04 : resolution === '540p' ? 0.05 : resolution === '1080p' ? 0.12 : 0.065;
        const price = audio ? withAudio : silent;
        return present(round6(durationSeconds * price), durationSeconds, 'seconds', price, config);
    }

    if (endpointId.includes('veo3') || /\/veo3(?:\.|\/|$)/.test(endpointId)) {
        let price = 0.2;
        if (resolution === '4k') price = audio ? 0.6 : 0.4;
        else if (audio) price = 0.4;
        return present(round6(durationSeconds * price), durationSeconds, 'seconds', price, config);
    }

    if (endpointId.includes('kling-video') && (endpointId.includes('/v3/') || endpointId.includes('/o3/'))) {
        const pro = endpointId.includes('/pro/') || endpointId.includes('/o3/pro/') || endpointId.includes('/o3/4k/');
        let price = pro ? 0.112 : 0.084;
        if (voice) price = pro ? 0.196 : 0.154;
        else if (audio) price = pro ? 0.168 : 0.126;
        return present(round6(durationSeconds * price), durationSeconds, 'seconds', price, config);
    }

    if (unit === 'tokens_per_1000' || endpointId.includes('seedance')) {
        return estimateTokenPriced(endpointId, catalogPrice, durationSeconds, resolution, aspect, refVideo, config);
    }

    if (unit === 'units' || unit === 'unit') {
        return { falCostUsd: 0, credits: 0, billableUnits: 0, unit: 'unsupported', unitPrice: 0 };
    }

    const audioMultiplier = audioMultiplierFor(endpointId, audio);
    const resolutionMultiplier = resolutionMultiplierFor(endpointId, resolution);
    const falCostUsd = round6(durationSeconds * catalogPrice * audioMultiplier * resolutionMultiplier);

    return present(falCostUsd, durationSeconds, unit || 'seconds', catalogPrice, config);
}

function estimateTokenPriced(
    endpointId: string,
    unitPrice: number,
    durationSeconds: number,
    resolution: string,
    aspect: string,
    referenceVideoSeconds: number,
    config?: CreditsConfig,
): VideoCreditEstimate {
    const [width, height] = dimensionsFor(resolution, aspect);
    const billableSeconds = durationSeconds + Math.max(0, referenceVideoSeconds);
    const tokens = (height * width * billableSeconds * 24) / 1024;
    let pricePerThousand = unitPrice > 0 ? unitPrice : 0.014;
    if (endpointId.includes('seedance-2.5') || endpointId.includes('seedance/2.5')) {
        pricePerThousand = resolution === '1080p' ? 0.0234 : 0.0214;
    } else if (endpointId.includes('seedance') && resolution === '4k') {
        pricePerThousand = 0.008;
    }
    let falCostUsd = (tokens / 1000) * pricePerThousand;
    if (referenceVideoSeconds > 0) falCostUsd *= 0.6;

    return present(round6(falCostUsd), Math.round(tokens * 10000) / 10000, 'tokens_per_1000', pricePerThousand, config);
}

function present(
    falCostUsd: number,
    billableUnits: number,
    unit: string,
    unitPrice: number,
    config?: CreditsConfig,
): VideoCreditEstimate {
    const baseCredits = falCostUsd > 0 ? Math.max(1, creditsFromFalUsd(falCostUsd, config)) : 0;

    return {
        falCostUsd,
        credits: applyCreditFloor(baseCredits, 'video', config),
        billableUnits,
        unit,
        unitPrice,
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
    if (id.includes('kling-video/o3/4k/reference-to-video')) return 1;
    if (id.includes('kling-video/o3/pro/reference-to-video')) return 1.25;
    if (id.includes('kling-video/o3/standard/reference-to-video')) return 4 / 3;
    if (id.includes('pixverse/c1/reference-to-video')) return 1.3;
    if (id.includes('kling') && (id.includes('v3') || id.includes('/o3/') || id.includes('v2.6'))) return 1.5;
    return 1;
}

function resolutionMultiplierFor(endpointId: string, resolution: string): number {
    const id = endpointId.toLowerCase();
    if (id.includes('pixverse/c1/reference-to-video')) return resolution === '1080p' ? 1.9 : 1;
    if (!id.includes('veo')) return 1;
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
