import { creditsFromFalUsd, type CreditsConfig } from '@/lib/imageCredits';

const DEFAULT_CONFIG: CreditsConfig = {
    markup: 1.25,
    usd_per_credit: 0.01,
};

type VoiceModelPricing = {
    unit_price?: number | string | null;
    unit?: string | null;
    endpoint_id?: string | null;
};

/**
 * Mirror of VoiceGenerationCostEstimator — character-based TTS pricing.
 */
export function estimateVoiceCredits(
    model: VoiceModelPricing | null | undefined,
    characterCount: number,
    config: CreditsConfig = DEFAULT_CONFIG,
): { credits: number; falCostUsd: number; billableUnits: number } {
    const chars = Math.max(0, characterCount);
    const rawPrice = model?.unit_price;
    const unitPrice =
        typeof rawPrice === 'number'
            ? rawPrice
            : Math.max(0, Number(String(rawPrice ?? '').replace(/[^0-9.\-eE]/g, '')) || 0);
    const unitRaw = String(model?.unit ?? '').toLowerCase().trim();

    if (unitPrice <= 0 || chars <= 0) {
        return { credits: 0, falCostUsd: 0, billableUnits: 0 };
    }

    let billableUnits = 0;
    let falCostUsd = 0;

    if (unitRaw.includes('1000') && unitRaw.includes('char')) {
        billableUnits = chars / 1000;
        falCostUsd = billableUnits * unitPrice;
    } else if (unitRaw.includes('char') && !unitRaw.includes('1000')) {
        billableUnits = chars;
        falCostUsd = billableUnits * unitPrice;
    } else if (unitRaw.includes('second') || unitRaw.includes('compute')) {
        billableUnits = Math.max(1, chars / 15);
        falCostUsd = billableUnits * unitPrice;
    } else {
        // Default TTS billing: per 1000 characters
        billableUnits = chars / 1000;
        falCostUsd = billableUnits * unitPrice;
    }

    falCostUsd = Math.round(falCostUsd * 1e6) / 1e6;
    const credits = creditsFromFalUsd(falCostUsd, config);

    return {
        falCostUsd,
        billableUnits,
        // Any billable job should show at least 1 credit once text exists
        credits: falCostUsd > 0 ? Math.max(1, credits) : 0,
    };
}
