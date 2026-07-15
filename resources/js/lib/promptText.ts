/**
 * True when the prompt has real instructions — not empty and not only @asset mentions.
 */
export function hasMeaningfulPrompt(value: string, minLength = 2): boolean {
    const stripped = value
        .replace(/@(image|video|audio)([1-9]\d*)\b/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();
    return stripped.length >= minLength;
}
