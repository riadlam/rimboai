/**
 * Heuristics for video prompt complexity — used to prefer safer models.
 * Not a hard block; ranking only.
 */

export type VideoPromptRisk = {
    /** 0 = simple, 1 = moderate, 2 = high risk for single-shot R2V models like Veo */
    level: 0 | 1 | 2;
    reasons: string[];
    mentionCount: number;
    wordCount: number;
};

const EDIT_PATTERNS =
    /\b(morph|morphing|transition(?:s|ing)?|cut(?:s|ting)?|sequence|slideshow|every\s+\d|0\.\d\s*s|seconds?\s+each|one\s+at\s+a\s+time|then\s+(?:cut|switch|morph)|hard\s+cut|seamless\s+morph)\b/i;

const MULTI_SUBJECT =
    /\b(\d+\s+(?:different\s+)?(?:characters?|people|persons|faces|subjects?)|each\s+character|character\s+[a-z]|\bthen\b.+\bthen\b)\b/i;

export function analyzeVideoPrompt(prompt: string): VideoPromptRisk {
    const raw = prompt ?? '';
    const mentions = [...raw.matchAll(/@(image|video|audio)([1-9]\d*)\b/gi)];
    const mentionCount = mentions.length;
    const stripped = raw.replace(/@(image|video|audio)([1-9]\d*)\b/gi, ' ').trim();
    const words = stripped.split(/\s+/).filter(Boolean);
    const wordCount = words.length;

    const reasons: string[] = [];
    let level: 0 | 1 | 2 = 0;

    if (EDIT_PATTERNS.test(stripped)) {
        level = 2;
        reasons.push('edit-style timing / morph / cuts');
    }
    if (MULTI_SUBJECT.test(stripped) || mentionCount >= 4) {
        level = Math.max(level, 2) as 0 | 1 | 2;
        reasons.push('multi-character or many @mentions');
    } else if (mentionCount >= 2 || wordCount > 120) {
        level = Math.max(level, 1) as 0 | 1 | 2;
        if (mentionCount >= 2) reasons.push('multiple @mentions');
        if (wordCount > 120) reasons.push('long director-style prompt');
    } else if (wordCount > 60) {
        level = Math.max(level, 1) as 0 | 1 | 2;
        reasons.push('detailed prompt');
    }

    return { level, reasons, mentionCount, wordCount };
}
