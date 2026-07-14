/**
 * Mirrors App\Services\Credits\* for live UI estimates.
 * credits = ceil( (fal_cost_usd * markup) / usd_per_credit )
 */

export type CreditsConfig = {
    markup: number;
    usd_per_credit: number;
};

export type ImageCreditModel = {
    endpoint_id?: string | null;
    unit?: string | null;
    unit_price?: number | string | null;
};

export type ImageCreditOptions = {
    aspect?: string;
    resolution?: string;
    quantity?: number;
    referenceCount?: number;
};

export type ImageCreditEstimate = {
    falCostUsd: number;
    credits: number;
    billableUnits: number;
    unit: string;
    unitPrice: number;
};

const DEFAULT_CONFIG: CreditsConfig = {
    markup: 1.25,
    usd_per_credit: 0.01,
};

export function creditsFromFalUsd(falCostUsd: number, config: CreditsConfig = DEFAULT_CONFIG): number {
    const markup = config.markup > 0 ? config.markup : 1.25;
    const usdPerCredit = config.usd_per_credit > 0 ? config.usd_per_credit : 0.01;
    if (falCostUsd <= 0) return 0;
    return Math.ceil((falCostUsd * markup) / usdPerCredit);
}

export function estimateImageCredits(
    model: ImageCreditModel | null | undefined,
    options: ImageCreditOptions = {},
    config: CreditsConfig = DEFAULT_CONFIG,
): ImageCreditEstimate {
    const endpointId = model?.endpoint_id || '';
    const unit = normalizeUnit(model?.unit);
    const catalogPrice = normalizePrice(model?.unit_price);
    const aspect = options.aspect || '1:1';
    const resolution = (options.resolution || '1K').toUpperCase();
    const quantity = Math.max(1, Math.min(4, options.quantity ?? 1));
    const referenceCount = Math.max(0, Math.min(8, options.referenceCount ?? 0));

    if (isGptImage(endpointId)) {
        const perImage = resolution === '4K' ? 0.25 : resolution === '2K' ? 0.15 : 0.06;
        const base = perImage * quantity;
        const refSurcharge = referenceCount > 0 ? base * 0.15 * referenceCount : 0;
        const falCostUsd = round6(base + refSurcharge);
        return {
            falCostUsd,
            credits: creditsFromFalUsd(falCostUsd, config),
            billableUnits: quantity,
            unit: 'gpt_tier',
            unitPrice: perImage,
        };
    }

    if (unit === 'megapixels' || unit === 'processed_megapixels') {
        const outputMp = estimateOutputMegapixels(endpointId, aspect, resolution);
        const inputMp = referenceCount > 0 ? referenceCount : 0;
        const billableUnits = outputMp * quantity + inputMp;
        const falCostUsd = round6(billableUnits * catalogPrice);
        return {
            falCostUsd,
            credits: creditsFromFalUsd(falCostUsd, config),
            billableUnits,
            unit,
            unitPrice: catalogPrice,
        };
    }

    const multiplier = resolutionMultiplier(endpointId, resolution);
    const billableUnits = quantity * multiplier;
    const falCostUsd = round6(billableUnits * catalogPrice);

    return {
        falCostUsd,
        credits: creditsFromFalUsd(falCostUsd, config),
        billableUnits,
        unit: unit || 'images',
        unitPrice: catalogPrice,
    };
}

function resolutionMultiplier(endpointId: string, resolution: string): number {
    const id = endpointId.toLowerCase();
    const scaled = id.includes('nano-banana') || id.includes('gemini');
    if (!scaled) return 1;
    if (resolution === '4K') return 2;
    if (resolution === '2K') return 1.5;
    return 1;
}

function estimateOutputMegapixels(endpointId: string, aspect: string, resolution: string): number {
    const id = endpointId.toLowerCase();
    let maxEdge = 2048;
    if (id.includes('flux-2-pro')) maxEdge = 2560;
    if (id.includes('seedream') || id.includes('bytedance')) maxEdge = 4096;
    if (id.includes('gpt-image-2')) maxEdge = 3840;

    let edge = resolution === '4K' ? 3840 : resolution === '2K' ? 2048 : 1024;
    if (id.includes('seedream') || id.includes('bytedance')) {
        edge = resolution === '4K' ? 4096 : resolution === '2K' ? 2048 : 1024;
    }
    edge = Math.min(edge, maxEdge);

    const dims = dimensionsForAspect(aspect, edge);
    const mp = (dims.width * dims.height) / 1_000_000;
    return Math.max(1, Math.ceil(mp));
}

function dimensionsForAspect(aspect: string, longestEdge: number): { width: number; height: number } {
    const [wRatioRaw, hRatioRaw] = aspect.split(':').map((n) => parseInt(n, 10));
    const wRatio = Math.max(1, wRatioRaw || 1);
    const hRatio = Math.max(1, hRatioRaw || 1);

    let width: number;
    let height: number;
    if (wRatio >= hRatio) {
        width = longestEdge;
        height = Math.round((longestEdge * hRatio) / wRatio);
    } else {
        height = longestEdge;
        width = Math.round((longestEdge * wRatio) / hRatio);
    }

    width = Math.max(16, Math.round(width / 16) * 16);
    height = Math.max(16, Math.round(height / 16) * 16);
    return { width, height };
}

function normalizeUnit(unit?: string | null): string {
    const u = (unit || '').trim().toLowerCase();
    if (u === 'image' || u === 'images') return 'images';
    if (u === 'megapixel' || u === 'megapixels') return 'megapixels';
    if (u === 'processed megapixel' || u === 'processed megapixels') return 'processed_megapixels';
    if (u === 'unit' || u === 'units') return 'units';
    return u || 'images';
}

function normalizePrice(price?: number | string | null): number {
    if (price == null || price === '') return 0;
    const n = typeof price === 'number' ? price : parseFloat(price);
    return Number.isFinite(n) && n > 0 ? n : 0;
}

function isGptImage(endpointId: string): boolean {
    return endpointId.toLowerCase().includes('gpt-image');
}

function round6(n: number): number {
    return Math.round(n * 1e6) / 1e6;
}
