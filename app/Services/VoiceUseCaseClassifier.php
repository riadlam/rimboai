<?php

namespace App\Services;

/**
 * Maps TTS voices into product use-case categories for the Voice Lab picker.
 * Uses known provider catalogs first, then keyword heuristics, then a stable weighted fallback.
 */
class VoiceUseCaseClassifier
{
    public const CATEGORIES = [
        'Conversational',
        'Narration',
        'Characters',
        'Social Media',
        'Entertainment',
        'Meditation',
    ];

    /** ElevenLabs premade + common shared first-name catalogs */
    private const KNOWN_BY_NAME = [
        // ElevenLabs default set
        'aria' => 'Narration',
        'roger' => 'Social Media',
        'sarah' => 'Entertainment',
        'laura' => 'Social Media',
        'charlie' => 'Conversational',
        'george' => 'Narration',
        'callum' => 'Characters',
        'river' => 'Meditation',
        'liam' => 'Conversational',
        'charlotte' => 'Narration',
        'alice' => 'Narration',
        'matilda' => 'Narration',
        'will' => 'Conversational',
        'jessica' => 'Conversational',
        'eric' => 'Conversational',
        'chris' => 'Conversational',
        'brian' => 'Narration',
        'daniel' => 'Narration',
        'lily' => 'Narration',
        'bill' => 'Narration',

        // xAI
        'eve' => 'Entertainment',
        'ara' => 'Conversational',
        'rex' => 'Narration',
        'sal' => 'Conversational',
        'leo' => 'Narration',

        // Gemini TTS (mythic names + documented traits)
        'kore' => 'Narration',
        'achernar' => 'Narration',
        'achird' => 'Conversational',
        'algenib' => 'Characters',
        'algieba' => 'Characters',
        'alnilam' => 'Narration',
        'aoede' => 'Social Media',
        'autonoe' => 'Characters',
        'callirrhoe' => 'Characters',
        'charon' => 'Narration',
        'despina' => 'Meditation',
        'enceladus' => 'Characters',
        'erinome' => 'Conversational',
        'fenrir' => 'Entertainment',
        'gacrux' => 'Narration',
        'iapetus' => 'Characters',
        'laomedeia' => 'Characters',
        'leda' => 'Social Media',
        'orus' => 'Narration',
        'puck' => 'Entertainment',
        'pulcherrima' => 'Social Media',
        'rasalgethi' => 'Characters',
        'sadachbia' => 'Meditation',
        'sadaltager' => 'Narration',
        'schedar' => 'Narration',
        'sulafat' => 'Meditation',
        'umbriel' => 'Characters',
        'vindemiatrix' => 'Narration',
        'zephyr' => 'Social Media',
        'zubenelgenubi' => 'Characters',
    ];

    /** @var list<array{0: string, 1: string}> category => keyword (checked in order) */
    private const KEYWORD_RULES = [
        ['Meditation', 'meditat'],
        ['Meditation', 'calm'],
        ['Meditation', 'serene'],
        ['Meditation', 'sooth'],
        ['Meditation', 'gentle'],
        ['Meditation', 'peaceful'],
        ['Meditation', 'relax'],
        ['Meditation', 'soft-spoken'],
        ['Meditation', 'mindful'],
        ['Meditation', 'wise woman'],
        ['Meditation', 'patient'],
        ['Characters', 'character'],
        ['Characters', 'villain'],
        ['Characters', 'hero'],
        ['Characters', 'knight'],
        ['Characters', 'fairy'],
        ['Characters', 'monster'],
        ['Characters', 'robot'],
        ['Characters', 'pirate'],
        ['Characters', 'wizard'],
        ['Characters', 'deep voice'],
        ['Characters', 'determined'],
        ['Characters', 'dramatic'],
        ['Social Media', 'social'],
        ['Social Media', 'tiktok'],
        ['Social Media', 'reels'],
        ['Social Media', 'influencer'],
        ['Social Media', 'upbeat'],
        ['Social Media', 'lively'],
        ['Social Media', 'bubbly'],
        ['Social Media', 'energetic'],
        ['Social Media', 'inspirational'],
        ['Social Media', 'lovely'],
        ['Entertainment', 'entertain'],
        ['Entertainment', 'host'],
        ['Entertainment', 'tv'],
        ['Entertainment', 'game show'],
        ['Entertainment', 'comedy'],
        ['Entertainment', 'excitable'],
        ['Entertainment', 'hyped'],
        ['Narration', 'narrat'],
        ['Narration', 'story'],
        ['Narration', 'documentary'],
        ['Narration', 'audiobook'],
        ['Narration', 'informative'],
        ['Narration', 'authoritative'],
        ['Narration', 'news'],
        ['Narration', 'mature'],
        ['Conversational', 'convers'],
        ['Conversational', 'casual'],
        ['Conversational', 'friendly'],
        ['Conversational', 'chat'],
        ['Conversational', 'natural'],
        ['Conversational', 'support'],
        ['Conversational', 'decent boy'],
        ['Conversational', 'warm'],
        ['Conversational', 'approachable'],
    ];

    /**
     * @param  list<string>|null  $tags
     */
    public function classify(
        string $name,
        ?string $description = null,
        ?string $voiceKey = null,
        ?array $tags = null,
    ): string {
        $haystack = strtolower(trim(implode(' ', array_filter([
            $name,
            $description,
            $voiceKey,
            is_array($tags) ? implode(' ', $tags) : null,
        ]))));

        $baseName = $this->normalizeName($name);
        $baseKey = $this->normalizeName((string) $voiceKey);

        foreach ([$baseName, $baseKey] as $knownKey) {
            if ($knownKey !== '' && isset(self::KNOWN_BY_NAME[$knownKey])) {
                return self::KNOWN_BY_NAME[$knownKey];
            }
        }

        foreach (self::KEYWORD_RULES as [$category, $keyword]) {
            if ($keyword !== '' && str_contains($haystack, $keyword)) {
                return $category;
            }
        }

        // Stable weighted fallback so every category can appear for large unnamed catalogs
        $seed = $baseKey !== '' ? $baseKey : ($baseName !== '' ? $baseName : $haystack);
        $bucket = abs(crc32($seed)) % 100;

        return match (true) {
            $bucket < 34 => 'Conversational',
            $bucket < 54 => 'Narration',
            $bucket < 69 => 'Characters',
            $bucket < 84 => 'Social Media',
            $bucket < 94 => 'Entertainment',
            default => 'Meditation',
        };
    }

    private function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));
        // "Craig (en)" / "Wise_Woman" / "Inspirational_girl"
        $value = preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? $value;
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
