/**
 * Mirrors App\Services\Credits\* for live UI estimates.
 * credits = ceil( (fal_cost_usd * markup) / usd_per_credit )
 */

export type MinCreditsMap = {
    video: number;
    tool: number;
    music: number;
    voice: number;
};

export type CreditsConfig = {
    markup: number;
    usd_per_credit: number;
    starter_tokens?: number;
    min_credits?: Partial<MinCreditsMap>;
    elevenlabs_multiplier?: number;
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

const DEFAULT_MIN_CREDITS: MinCreditsMap = {
    video: 0,
    tool: 0,
    music: 0,
    voice: 0,
};

export const DEFAULT_CREDITS_CONFIG: CreditsConfig = {
    markup: 1.25,
    usd_per_credit: 0.01,
    starter_tokens: 25,
    min_credits: DEFAULT_MIN_CREDITS,
    elevenlabs_multiplier: 5,
};

export function resolveCreditsConfig(partial?: CreditsConfig): CreditsConfig {
    return {
        ...DEFAULT_CREDITS_CONFIG,
        ...partial,
        min_credits: {
            ...DEFAULT_MIN_CREDITS,
            ...partial?.min_credits,
        },
    };
}

export function minCreditsFor(config: CreditsConfig | undefined, product: keyof MinCreditsMap): number {
    return resolveCreditsConfig(config).min_credits?.[product] ?? DEFAULT_MIN_CREDITS[product];
}

export function applyCreditFloor(
    credits: number,
    product: keyof MinCreditsMap,
    config?: CreditsConfig,
): number {
    const min = minCreditsFor(config, product);
    if (min <= 0 || credits <= 0 || credits >= min) {
        return credits;
    }
    return min;
}

export function creditsFromFalUsd(
    falCostUsd: number,
    config: CreditsConfig = DEFAULT_CREDITS_CONFIG,
): number {
    const resolved = resolveCreditsConfig(config);
    const markup = resolved.markup > 0 ? resolved.markup : 1.25;
    const usdPerCredit = resolved.usd_per_credit > 0 ? resolved.usd_per_credit : 0.01;
    if (falCostUsd <= 0) return 0;
    return Math.ceil((falCostUsd * markup) / usdPerCredit);
}

export function estimateImageCredits(
    model: ImageCreditModel | null | undefined,
    options: ImageCreditOptions = {},
    config: CreditsConfig = DEFAULT_CREDITS_CONFIG,
): ImageCreditEstimate {
    const endpointId = model?.endpoint_id || '';
    const unit = normalizeUnit(model?.unit);
    const catalogPrice = normalizePrice(model?.unit_price);
    const aspect = options.aspect || '1:1';
    const resolution = (options.resolution || '1K').toUpperCase();
    const quantity = Math.max(1, Math.min(4, options.quantity ?? 1));
    const referenceCount = Math.max(0, Math.min(8, options.referenceCount ?? 0));

    if (isGptImage(endpointId)) {
        // Fal publish + ~10–15% token buffer. Lab: 1K→medium, 2K/4K→high.
        const perImage = gptPerImageUsd(endpointId, resolution);
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

/** Safe USD/image tiers matching ImageGenerationCostEstimator::gptPerImageUsd. */
function gptPerImageUsd(endpointId: string, resolution: string): number {
    const id = endpointId.toLowerCase();
    const isGpt2 = id.includes('gpt-image-2');

    if (isGpt2) {
        if (resolution === '4K') return 0.5;
        if (resolution === '2K') return 0.3;
        return 0.1;
    }

    // gpt-image-1.5 — no native 4K; 2K/4K UI both bill as high.
    if (resolution === '4K' || resolution === '2K') return 0.25;
    return 0.08;
}

function round6(n: number): number {
    return Math.round(n * 1e6) / 1e6;
}
