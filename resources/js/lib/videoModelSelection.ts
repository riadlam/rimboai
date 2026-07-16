import type { BrandModel } from '@/types';
import { analyzeVideoPrompt } from '@/lib/videoPromptHeuristics';
import { getMediaCaps, mediaTotal, supportsMediaMix, type MediaCounts } from '@/lib/videoMediaCaps';

export type VideoModelPick = {
    model: BrandModel;
    score: number;
    reason: string;
};

function endpointFamily(endpointId: string | null | undefined): 'seedance' | 'veo' | 'kling' | 'wan' | 'pixverse' | 'other' {
    const id = (endpointId || '').toLowerCase();
    if (id.includes('seedance')) return 'seedance';
    if (id.includes('veo')) return 'veo';
    if (id.includes('kling')) return 'kling';
    if (id.includes('wan/')) return 'wan';
    if (id.includes('pixverse')) return 'pixverse';
    return 'other';
}

/**
 * Score compatible models for the current media + prompt.
 * Higher = safer / better fit. Caller should filter with supportsMediaMix first.
 */
export function scoreVideoModel(model: BrandModel, counts: MediaCounts, prompt: string): VideoModelPick {
    const caps = getMediaCaps(model);
    const family = endpointFamily(model.endpoint_id);
    const risk = analyzeVideoPrompt(prompt);
    const total = mediaTotal(counts);
    let score = 100;
    const bits: string[] = [];

    if (total === 0) {
        // Text-only: prefer known cinematic models lightly by sort; keep baseline.
        if (family === 'veo') score += 5;
        if (family === 'kling') score += 3;
        bits.push('text-to-video');
    } else if (counts.images === 1 && counts.videos === 0 && counts.audios === 0) {
        // First-frame I2V — prefer models with first-frame support
        if (caps.supports_first_frame) {
            score += 40;
            bits.push('first-frame');
        }
        if (family === 'veo' || family === 'kling') score += 10;
        if (risk.level >= 2 && family === 'seedance') score += 5;
    } else {
        // Reference / multimodal path
        if (counts.videos > 0 || counts.audios > 0) {
            if (family === 'seedance' || family === 'wan') {
                score += 80;
                bits.push('multimodal');
            } else {
                score -= 50;
            }
        }

        if (counts.images >= 4) {
            if (family === 'kling') {
                score += 80;
                bits.push('kling multi-ref');
            } else if (family === 'wan') {
                score += 70;
                bits.push('wan multi-ref');
            } else if (family === 'seedance') {
                score += 70;
                bits.push('many images');
            } else if (family === 'pixverse') {
                score += 40;
                bits.push('pixverse refs');
            } else {
                score -= 80;
            }
        } else if (counts.images >= 2) {
            if (risk.level >= 2) {
                if (family === 'kling') {
                    score += 70;
                    bits.push('kling refs');
                } else if (family === 'wan') {
                    score += 60;
                    bits.push('wan refs');
                } else if (family === 'pixverse') {
                    score += 35;
                    bits.push('pixverse refs');
                } else if (family === 'seedance') {
                    score += 20;
                    bits.push('multi-image');
                } else if (family === 'veo') {
                    score -= 25;
                    bits.push('veo risky for edit prompts');
                }
            } else if (counts.images <= 3 && family === 'veo') {
                score += 35;
                bits.push('veo 2–3 images');
            } else if (family === 'kling') {
                score += 45;
                bits.push('kling refs');
            } else if (family === 'wan') {
                score += 40;
                bits.push('wan refs');
            } else if (family === 'pixverse') {
                score += 25;
                bits.push('pixverse refs');
            } else if (family === 'seedance') {
                score += 25;
                bits.push('seedance multi-image');
            }
        }

        if (risk.level >= 2 && counts.images >= 2 && family === 'veo') {
            score -= 40;
        }
    }

    // Prefer faster / cheaper tiers slightly when scores are close
    const id = (model.endpoint_id || '').toLowerCase();
    if (id.includes('/fast') || id.includes('lite')) score += 2;

    // Stable sort tie-breaker
    score += Math.max(0, 20 - (model.sort ?? 20)) * 0.01;

    return {
        model,
        score,
        reason: bits[0] || 'compatible',
    };
}

/**
 * Pick the best model for refs + prompt among compatible candidates.
 */
export function pickBestVideoModel(
    candidates: BrandModel[],
    counts: MediaCounts,
    prompt: string,
): VideoModelPick | null {
    const compatible = candidates.filter((m) => supportsMediaMix(m, counts));
    if (compatible.length === 0) return null;

    let best: VideoModelPick | null = null;
    for (const model of compatible) {
        const scored = scoreVideoModel(model, counts, prompt);
        if (!best || scored.score > best.score) best = scored;
    }
    return best;
}

/**
 * Whether we should force-switch away from the current model.
 * - Always if incompatible
 * - Soft: if best model is clearly safer (score gap) for heavy refs / risky prompts
 */
export function shouldAutoSwitchVideoModel(
    current: BrandModel | null | undefined,
    best: VideoModelPick | null,
    counts: MediaCounts,
    prompt: string,
    userLocked: boolean,
): { switch: boolean; reason: string } {
    if (!best) return { switch: false, reason: '' };

    if (!current || !supportsMediaMix(current, counts)) {
        return {
            switch: true,
            reason: `Switched to ${best.model.name} — your previous model can’t use these references safely.`,
        };
    }

    if (userLocked) {
        return { switch: false, reason: '' };
    }

    const currentFamily = endpointFamily(current.endpoint_id);
    if (['kling', 'wan', 'pixverse'].includes(currentFamily) && supportsMediaMix(current, counts)) {
        return { switch: false, reason: '' };
    }

    if (best.model.name === current.name && best.model.endpoint_id === current.endpoint_id) {
        return { switch: false, reason: '' };
    }

    const currentScore = scoreVideoModel(current, counts, prompt).score;
    const gap = best.score - currentScore;
    const risk = analyzeVideoPrompt(prompt);
    const heavyRefs = counts.images >= 4 || counts.videos > 0 || counts.audios > 0;
    const riskyVeo =
        currentFamily === 'veo' &&
        counts.images >= 2 &&
        risk.level >= 2;

    if (heavyRefs && gap >= 20) {
        return {
            switch: true,
            reason: `Switched to ${best.model.name} — better match for your references (avoids failed generations).`,
        };
    }

    if (riskyVeo && gap >= 15) {
        return {
            switch: true,
            reason: `Switched to ${best.model.name} — your prompt looks like a multi-shot edit; this model handles that more reliably.`,
        };
    }

    return { switch: false, reason: '' };
}
