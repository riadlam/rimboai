import { creditsFromFalUsd, type CreditsConfig } from '@/lib/imageCredits';

const DEFAULT_CONFIG: CreditsConfig = {
    markup: 1.25,
    usd_per_credit: 0.01,
};

/** MiniMax voice-clone: flat clone fee + preview TTS chars (fal pricing). */
const MINIMAX_CLONE_FEE_USD = 1.5;
const MINIMAX_PREVIEW_PER_1000_CHARS_USD = 0.3;

export type VoiceModelPricing = {
    unit_price?: number | string | null;
    unit?: string | null;
    endpoint_id?: string | null;
    supports_audio?: boolean | null;
    tags?: string[] | null;
};

export type VoiceCreditEstimate = {
    credits: number;
    falCostUsd: number;
    billableUnits: number;
    mode: string;
    /** Sample seconds used for validation / display (clone models). */
    sampleSeconds: number | null;
};

/**
 * True when this catalog model needs a voice sample (clone / zero-shot).
 * Uses endpoint + tags as fallback when DB `supports_audio` was never set.
 */
export function isVoiceCloneModel(
    model: Pick<VoiceModelPricing, 'endpoint_id' | 'supports_audio' | 'tags'> | null | undefined,
): boolean {
    if (!model) return false;
    if (model.supports_audio === true) return true;

    const id = String(model.endpoint_id ?? '').toLowerCase();
    if (id.includes('voice-clone')) return true;
    if (id.includes('chatterbox') && id.includes('text-to-speech')) return true;

    const tags = (model.tags ?? []).map((t) => String(t).toLowerCase());
    return tags.includes('voice-clone') || tags.includes('sample-audio');
}

export function isMiniMaxVoiceClone(endpointId?: string | null): boolean {
    const id = String(endpointId ?? '').toLowerCase();
    return id.includes('minimax/voice-clone') || id.endsWith('/voice-clone');
}

export const CHATTERBOX_EN_ENDPOINT = 'fal-ai/chatterbox/text-to-speech';
export const CHATTERBOX_MULTI_ENDPOINT = 'fal-ai/chatterbox/text-to-speech/multilingual';
export const CHATTERBOX_EN_MAX_CHARS = 5000;
export const CHATTERBOX_MULTI_MAX_CHARS = 300;

/** Languages supported by Chatterbox multilingual (fal enum). English uses the longer ASCII endpoint. */
export const CHATTERBOX_LANGUAGES = [
    { id: 'english', labelKey: 'voice.langEnglish' },
    { id: 'arabic', labelKey: 'voice.langArabic' },
    { id: 'french', labelKey: 'voice.langFrench' },
    { id: 'spanish', labelKey: 'voice.langSpanish' },
    { id: 'german', labelKey: 'voice.langGerman' },
    { id: 'italian', labelKey: 'voice.langItalian' },
    { id: 'portuguese', labelKey: 'voice.langPortuguese' },
    { id: 'turkish', labelKey: 'voice.langTurkish' },
    { id: 'russian', labelKey: 'voice.langRussian' },
    { id: 'chinese', labelKey: 'voice.langChinese' },
    { id: 'japanese', labelKey: 'voice.langJapanese' },
    { id: 'korean', labelKey: 'voice.langKorean' },
    { id: 'hindi', labelKey: 'voice.langHindi' },
    { id: 'hebrew', labelKey: 'voice.langHebrew' },
    { id: 'dutch', labelKey: 'voice.langDutch' },
    { id: 'polish', labelKey: 'voice.langPolish' },
] as const;

export type ChatterboxLanguageId = (typeof CHATTERBOX_LANGUAGES)[number]['id'];

export function isChatterboxCloneModel(
    model: Pick<VoiceModelPricing, 'endpoint_id'> | null | undefined,
): boolean {
    const id = String(model?.endpoint_id ?? '').toLowerCase();
    return id.includes('chatterbox') && id.includes('text-to-speech');
}

export function isChatterboxEnglishEndpoint(endpointId?: string | null): boolean {
    const id = String(endpointId ?? '').toLowerCase();
    return id.includes('chatterbox') && id.includes('text-to-speech') && !id.includes('multilingual');
}

/** English → longer ASCII endpoint; all other langs → multilingual. */
export function chatterboxEndpointForLanguage(language: string): string {
    return language === 'english' ? CHATTERBOX_EN_ENDPOINT : CHATTERBOX_MULTI_ENDPOINT;
}

export function chatterboxMaxChars(endpointId?: string | null): number {
    const id = String(endpointId ?? '').toLowerCase();
    if (id.includes('chatterbox') && id.includes('multilingual')) return CHATTERBOX_MULTI_MAX_CHARS;
    if (id.includes('chatterbox') && id.includes('text-to-speech')) return CHATTERBOX_EN_MAX_CHARS;
    return 70000;
}

/** MiniMax needs ≥10s; Chatterbox works with shorter clips. */
export function minVoiceSampleSeconds(endpointId?: string | null): number {
    return isMiniMaxVoiceClone(endpointId) ? 10 : 3;
}

/**
 * Mirror of VoiceGenerationCostEstimator.
 * Clone MiniMax: $1.50/request + $0.30/1k preview chars.
 * Chatterbox / preset TTS: character units from catalog.
 */
export function estimateVoiceCredits(
    model: VoiceModelPricing | null | undefined,
    characterCount: number,
    config: CreditsConfig = DEFAULT_CONFIG,
    options: { sampleSeconds?: number | null } = {},
): VoiceCreditEstimate {
    const chars = Math.max(0, characterCount);
    const sampleSeconds =
        typeof options.sampleSeconds === 'number' && Number.isFinite(options.sampleSeconds) && options.sampleSeconds > 0
            ? options.sampleSeconds
            : null;

    const rawPrice = model?.unit_price;
    const unitPrice =
        typeof rawPrice === 'number'
            ? rawPrice
            : Math.max(0, Number(String(rawPrice ?? '').replace(/[^0-9.\-eE]/g, '')) || 0);
    const unitRaw = String(model?.unit ?? '').toLowerCase().trim();
    const endpointId = model?.endpoint_id ?? '';

    if (isMiniMaxVoiceClone(endpointId)) {
        const cloneFee = unitRaw.includes('generation') && unitPrice > 0 ? unitPrice : MINIMAX_CLONE_FEE_USD;
        const previewChars = Math.max(chars, 1);
        const previewUsd = (previewChars / 1000) * MINIMAX_PREVIEW_PER_1000_CHARS_USD;
        const falCostUsd = Math.round((cloneFee + previewUsd) * 1e6) / 1e6;
        const credits = falCostUsd > 0 ? Math.max(1, creditsFromFalUsd(falCostUsd, config)) : 0;

        return {
            falCostUsd,
            billableUnits: 1,
            credits,
            mode: 'minimax_voice_clone',
            sampleSeconds,
        };
    }

    if (unitPrice <= 0 || chars <= 0) {
        return { credits: 0, falCostUsd: 0, billableUnits: 0, mode: 'zero', sampleSeconds };
    }

    let billableUnits = 0;
    let falCostUsd = 0;
    let mode = 'fallback_per_1000_characters';

    if (unitRaw.includes('1000') && unitRaw.includes('char')) {
        billableUnits = chars / 1000;
        falCostUsd = billableUnits * unitPrice;
        mode = 'per_1000_characters';
    } else if (unitRaw.includes('char') && !unitRaw.includes('1000')) {
        billableUnits = chars;
        falCostUsd = billableUnits * unitPrice;
        mode = 'per_character';
    } else if (unitRaw.includes('second') || unitRaw.includes('compute')) {
        billableUnits = Math.max(1, chars / 15);
        falCostUsd = billableUnits * unitPrice;
        mode = 'per_compute_second';
    } else if (unitRaw.includes('generation')) {
        billableUnits = 1;
        falCostUsd = unitPrice;
        mode = 'per_generation';
    } else {
        billableUnits = chars / 1000;
        falCostUsd = billableUnits * unitPrice;
    }

    falCostUsd = Math.round(falCostUsd * 1e6) / 1e6;
    const credits = falCostUsd > 0 ? Math.max(1, creditsFromFalUsd(falCostUsd, config)) : 0;

    return {
        falCostUsd,
        billableUnits,
        credits,
        mode,
        sampleSeconds,
    };
}

/** Read duration (seconds) from a local audio File. */
export function readVoiceSampleDuration(file: File): Promise<number | null> {
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

export function formatVoiceSampleDuration(seconds: number | null | undefined): string {
    if (seconds == null || !Number.isFinite(seconds) || seconds <= 0) return '';
    const total = Math.round(seconds);
    if (total < 60) return `${total}s`;
    const m = Math.floor(total / 60);
    const s = total % 60;
    return `${m}:${String(s).padStart(2, '0')}`;
}
