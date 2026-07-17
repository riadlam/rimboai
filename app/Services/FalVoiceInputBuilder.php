<?php

namespace App\Services;

/**
 * Builds fal TTS request payloads and reports which UI voice controls each endpoint supports.
 */
class FalVoiceInputBuilder
{
    /**
     * @param  array{
     *   text: string,
     *   voice?: string|null,
     *   audio_url?: string|null,
     *   stability?: int|float|null,
     *   clarity?: int|float|null,
     *   style?: int|float|null,
     *   speed?: int|float|null,
     *   exaggeration?: int|float|null,
     *   language?: string|null,
     * }  $options
     * @return array<string, mixed>
     */
    public function build(string $endpointId, array $options): array
    {
        $id = strtolower($endpointId);
        $text = trim((string) ($options['text'] ?? ''));
        $voice = (string) ($options['voice'] ?? '');
        $audioUrl = trim((string) ($options['audio_url'] ?? ''));
        $caps = $this->controlCapabilities($endpointId);

        if (str_contains($id, 'minimax/voice-clone') || str_ends_with($id, '/voice-clone')) {
            $input = [
                'audio_url' => $audioUrl,
                'text' => $text !== '' ? $text : 'Hello, this is a preview of your cloned voice!',
                'model' => 'speech-02-hd',
            ];

            return $input;
        }

        if (str_contains($id, 'chatterbox') && str_contains($id, 'text-to-speech')) {
            // Multilingual endpoint: custom clone uses `voice` = audio URL + custom_audio_language
            // English-only endpoint: `audio_url` + ASCII text only
            $isMultilingual = str_contains($id, 'multilingual');

            if ($isMultilingual) {
                $input = [
                    'text' => mb_substr($text, 0, 300),
                ];

                if ($audioUrl !== '') {
                    $input['voice'] = $audioUrl;
                    $input['custom_audio_language'] = $this->detectChatterboxLanguage($text, $options['language'] ?? null);
                } else {
                    $input['voice'] = $this->detectChatterboxLanguage($text, $options['language'] ?? null);
                }

                if ($caps['style']) {
                    // Multilingual exaggeration range is 0.25–2.0 (UI 0–100 → map into that band)
                    $ui = max(0.0, min(100.0, (float) ($options['style'] ?? $options['exaggeration'] ?? 25)));
                    $input['exaggeration'] = round(0.25 + ($ui / 100) * 1.75, 3);
                }

                return $input;
            }

            $input = [
                'text' => $text,
            ];
            if ($audioUrl !== '') {
                $input['audio_url'] = $audioUrl;
            }
            if ($caps['style']) {
                $input['exaggeration'] = $this->map01($options['style'] ?? $options['exaggeration'] ?? 25);
            }

            return $input;
        }

        if (str_contains($id, 'minimax')) {
            $voiceSetting = ['voice_id' => $voice];
            if ($caps['speed']) {
                $voiceSetting['speed'] = $this->mapSpeed($options['speed'] ?? 100, 0.5, 2.0);
            }

            return [
                'text' => $text,
                'voice_setting' => $voiceSetting,
            ];
        }

        if (str_contains($id, 'gemini')) {
            return [
                'prompt' => $text,
                'voice' => $voice,
            ];
        }

        $input = [
            'text' => $text,
            'voice' => $voice,
        ];

        // ElevenLabs shared TTS controls
        if ($caps['stability']) {
            $input['stability'] = $this->map01($options['stability'] ?? 50);
        }
        if ($caps['clarity']) {
            $input['similarity_boost'] = $this->map01($options['clarity'] ?? 75);
        }
        if ($caps['style']) {
            $input['style'] = $this->map01($options['style'] ?? 20);
        }
        if ($caps['speed'] && (str_contains($id, 'elevenlabs') || str_contains($id, 'eleven'))) {
            $input['speed'] = $this->mapSpeed($options['speed'] ?? 100, 0.7, 1.2);
        }

        return $input;
    }

    /**
     * @return array{stability: bool, clarity: bool, style: bool, speed: bool, requires_sample_audio: bool}
     */
    public function controlCapabilities(string $endpointId): array
    {
        $id = strtolower($endpointId);

        if (str_contains($id, 'minimax/voice-clone') || str_ends_with($id, '/voice-clone')) {
            return [
                'stability' => false,
                'clarity' => false,
                'style' => false,
                'speed' => false,
                'requires_sample_audio' => true,
            ];
        }

        if (str_contains($id, 'chatterbox')) {
            return [
                'stability' => false,
                'clarity' => false,
                'style' => true,
                'speed' => false,
                'requires_sample_audio' => true,
            ];
        }

        if (str_contains($id, 'elevenlabs') || str_contains($id, 'eleven')) {
            return [
                'stability' => true,
                'clarity' => true,
                'style' => true,
                'speed' => true,
                'requires_sample_audio' => false,
            ];
        }

        if (str_contains($id, 'minimax')) {
            return [
                'stability' => false,
                'clarity' => false,
                'style' => false,
                'speed' => true,
                'requires_sample_audio' => false,
            ];
        }

        // Gemini / Inworld / xAI: no matching numeric controls in fal schemas
        return [
            'stability' => false,
            'clarity' => false,
            'style' => false,
            'speed' => false,
            'requires_sample_audio' => false,
        ];
    }

    public function requiresSampleAudio(string $endpointId): bool
    {
        return $this->controlCapabilities($endpointId)['requires_sample_audio'];
    }

    /**
     * Map UI / locale hints to Chatterbox multilingual language codes.
     */
    private function detectChatterboxLanguage(string $text, mixed $hint = null): string
    {
        $allowed = [
            'english', 'arabic', 'danish', 'german', 'greek', 'spanish', 'finnish', 'french',
            'hebrew', 'hindi', 'italian', 'japanese', 'korean', 'malay', 'dutch', 'norwegian',
            'polish', 'portuguese', 'russian', 'swedish', 'swahili', 'turkish', 'chinese',
        ];

        if (is_string($hint)) {
            $normalized = strtolower(trim($hint));
            $aliases = [
                'en' => 'english',
                'ar' => 'arabic',
                'fr' => 'french',
                'de' => 'german',
                'es' => 'spanish',
                'it' => 'italian',
                'pt' => 'portuguese',
                'ru' => 'russian',
                'zh' => 'chinese',
                'ja' => 'japanese',
                'ko' => 'korean',
                'hi' => 'hindi',
                'he' => 'hebrew',
                'tr' => 'turkish',
                'nl' => 'dutch',
                'pl' => 'polish',
            ];
            $normalized = $aliases[$normalized] ?? $normalized;
            if (in_array($normalized, $allowed, true)) {
                return $normalized;
            }
        }

        // Script detection for common non-Latin text
        if (preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $text)) {
            return 'arabic';
        }
        if (preg_match('/[\x{0400}-\x{04FF}]/u', $text)) {
            return 'russian';
        }
        if (preg_match('/[\x{4E00}-\x{9FFF}]/u', $text)) {
            return 'chinese';
        }
        if (preg_match('/[\x{3040}-\x{30FF}]/u', $text)) {
            return 'japanese';
        }
        if (preg_match('/[\x{AC00}-\x{D7AF}]/u', $text)) {
            return 'korean';
        }
        if (preg_match('/[\x{0590}-\x{05FF}]/u', $text)) {
            return 'hebrew';
        }
        if (preg_match('/[\x{0900}-\x{097F}]/u', $text)) {
            return 'hindi';
        }

        return 'english';
    }

    private function map01(int|float|null $ui): float
    {
        $v = max(0.0, min(100.0, (float) ($ui ?? 0)));

        return round($v / 100, 3);
    }

    private function mapSpeed(int|float|null $uiPercent, float $min, float $max): float
    {
        $v = max(50.0, min(150.0, (float) ($uiPercent ?? 100))) / 100;

        return round(max($min, min($max, $v)), 3);
    }
}
