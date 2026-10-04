<?php

namespace App\Services;

/**
 * Maps Lab video UI options onto fal.ai text-to-video input fields.
 */
class FalVideoInputBuilder
{
    private const ASPECTS = ['16:9', '9:16', '1:1', '4:5', '3:4'];

    /**
     * @var array<string, array{
     *   duration_format?: 'string'|'int'|'veo_s',
     *   aspects?: list<string>,
     *   resolution?: bool,
     *   audio?: bool,
     *   audio_field?: string
     * }>
     */
    private const PROFILES = [
        'fal-ai/kling-video/v3/pro/text-to-video' => [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
            'audio' => true,
            'audio_field' => 'generate_audio',
        ],
        'fal-ai/kling-video/o3/pro/text-to-video' => [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
            'audio' => true,
            'audio_field' => 'generate_audio',
        ],
        'fal-ai/kling-video/o3/pro/reference-to-video' => [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
            'audio' => true,
            'audio_field' => 'generate_audio',
        ],
        'fal-ai/kling-video/o3/standard/reference-to-video' => [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
            'audio' => true,
            'audio_field' => 'generate_audio',
        ],
        'fal-ai/kling-video/o1/reference-to-video' => [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
            'audio' => false,
        ],
                        'fal-ai/kling-video/v2.6/pro/text-to-video' => [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
            'audio' => true,
            'audio_field' => 'generate_audio',
        ],
        'fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
            'audio' => false,
        ],
        'fal-ai/veo3.1' => [
            'duration_format' => 'veo_s',
            'aspects' => ['16:9', '9:16'],
            'resolution' => true,
            'audio' => true,
            'audio_field' => 'generate_audio',
        ],
        'fal-ai/veo3.1/fast' => [
            'duration_format' => 'veo_s',
            'aspects' => ['16:9', '9:16'],
            'resolution' => true,
            'audio' => true,
            'audio_field' => 'generate_audio',
        ],
        'fal-ai/veo3.1/lite' => [
            'duration_format' => 'veo_s',
            'aspects' => ['16:9', '9:16'],
            'resolution' => true,
            'audio' => true,
            'audio_field' => 'generate_audio',
        ],
        'fal-ai/sora-2/text-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16', '1:1'],
            'resolution' => true,
        ],
        'fal-ai/wan/v2.7/text-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16', '1:1', '4:5', '3:4'],
            'resolution' => true,
        ],
        'fal-ai/wan/v2.7/reference-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16', '1:1', '4:5', '3:4'],
            'resolution' => true,
        ],
        // MiniMax H3 — Image N / Video N refs, motion clips 2–15s, output 5–15s.
        'minimax/h3/reference-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['adaptive', '21:9', '16:9', '4:3', '1:1', '3:4', '9:16'],
            'resolution' => true,
        ],
        'fal-ai/pixverse/c1/reference-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16', '1:1', '4:5', '3:4'],
            'resolution' => true,
            'audio' => true,
            'audio_field' => 'generate_audio_switch',
        ],
        'bytedance/seedance-2.0/text-to-video' => [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
            'audio' => false,
        ],
        'xai/grok-imagine-video/text-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16', '1:1', '4:5', '3:4', '3:2', '2:3'],
            'resolution' => true,
        ],
        // I2V must keep duration as int — falling through to inferProfile used string "4"
        // which Fal rejects / mishandles. Aspect defaults to auto so the source image
        // is not stretched into 9:16/16:9.
        'xai/grok-imagine-video/image-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['auto', '16:9', '9:16', '1:1', '4:5', '3:4', '3:2', '2:3'],
            'resolution' => true,
            'i2v_aspect_auto' => true,
        ],
        // Gemini Omni Flash — duration MUST be int (3–10). Audio always on (no generate_audio field).
        'google/gemini-omni-flash' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16'],
        ],
        'google/gemini-omni-flash/image-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16'],
        ],
        'google/gemini-omni-flash/reference-to-video' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16'],
        ],
        // Edit has no duration/aspect on Fal — profile kept for duration clamp used in billing.
        'google/gemini-omni-flash/edit' => [
            'duration_format' => 'int',
            'aspects' => ['16:9', '9:16'],
        ],
    ];

    /**
     * @param  array{
     *   prompt: string,
     *   aspect?: string|null,
     *   resolution?: string|null,
     *   duration?: int|string|null,
     *   audio?: bool|null,
     *   allowed_durations?: array<int, int|string>|null,
     *   mode?: string|null,
     *   image_urls?: array<int, string>|null,
     *   video_urls?: array<int, string>|null,
     *   audio_urls?: array<int, string>|null,
     *   first_frame_param?: string|null,
     *   last_frame_param?: string|null
     * }  $options
     * @return array{input: array<string, mixed>, duration_seconds: int, duration_value: string, aspect_ratio: string, resolution: string|null, with_audio: bool}
     */
    public function build(string $endpointId, array $options): array
    {
        $profile = self::PROFILES[$endpointId] ?? $this->inferProfile($endpointId);
        $prompt = trim((string) ($options['prompt'] ?? ''));
        $aspect = $this->normalizeAspect($options['aspect'] ?? null, $profile['aspects'] ?? self::ASPECTS);
        $resolution = $this->normalizeResolution($options['resolution'] ?? null);
        $audio = (bool) ($options['audio'] ?? true);
        $mode = (string) ($options['mode'] ?? 'text-to-video');

        $durationSeconds = $this->resolveDurationSeconds(
            $options['duration'] ?? null,
            $options['allowed_durations'] ?? null,
            $profile,
        );

        // Some fal routes are stricter than their advertised duration enum.
        $durationSeconds = $this->constrainDurationForEndpoint($endpointId, $mode, $durationSeconds);

        $input = ['prompt' => $prompt];

        $durationValue = $this->formatDuration($durationSeconds, $profile['duration_format'] ?? 'string', $options['duration'] ?? null);
        $input['duration'] = $durationValue;

        // Wan 2.2 A14B uses num_frames + frames_per_second (no duration field).
        if (str_contains(strtolower($endpointId), 'wan/v2.2-a14b')) {
            unset($input['duration']);
            $fps = 16;
            $frames = max(17, min(161, (int) round($durationSeconds * $fps) + 1));
            // Prefer odd frame counts (Fal examples use 81).
            if ($frames % 2 === 0) {
                $frames = min(161, $frames + 1);
            }
            $input['num_frames'] = $frames;
            $input['frames_per_second'] = $fps;
            $durationSeconds = (int) max(1, round($frames / $fps));
            $durationValue = (string) $durationSeconds;
        }

        // Seedance / some R2V endpoints accept aspect_ratio "auto"
        if (str_contains(strtolower($endpointId), 'seedance') && str_contains(strtolower($endpointId), 'reference')) {
            $input['aspect_ratio'] = $aspect === '16:9' || $aspect === '9:16' || $aspect === '1:1' ? $aspect : 'auto';
        } elseif (
            ! empty($profile['i2v_aspect_auto'])
            && $mode === 'image-to-video'
            && ! str_contains(strtolower($endpointId), 'wan/v2.2-a14b')
        ) {
            // Grok I2V: preserve the source image framing (Fal default). Forcing 9:16
            // on a square logo / portrait photo is what made outputs look "ugly".
            // Wan 2.2 A14B rejects auto for many image sizes — use explicit 16:9 / 9:16 / 1:1.
            $input['aspect_ratio'] = 'auto';
        } else {
            $input['aspect_ratio'] = $aspect;
        }

        // Wan 2.2 A14B distributed GPUs only accept these three ratios (never auto).
        if (str_contains(strtolower($endpointId), 'wan/v2.2-a14b')) {
            $input['aspect_ratio'] = $this->mapWan22Aspect($aspect);
        }

        if (
            ! empty($profile['resolution'])
            || str_contains(strtolower($endpointId), 'seedance')
            || str_contains(strtolower($endpointId), 'veo')
            || str_contains(strtolower($endpointId), 'grok-imagine-video')
        ) {
            $input['resolution'] = $this->mapResolutionForFal($resolution, $endpointId);
        }

        if (! empty($profile['audio']) || str_contains(strtolower($endpointId), 'seedance') || str_contains(strtolower($endpointId), 'veo') || str_contains(strtolower($endpointId), 'kling')) {
            $field = $profile['audio_field'] ?? 'generate_audio';
            // Seedance R2V always supports generate_audio
            if (! empty($profile['audio']) || str_contains(strtolower($endpointId), 'seedance') || str_contains(strtolower($endpointId), 'veo') || (str_contains(strtolower($endpointId), 'kling') && (str_contains(strtolower($endpointId), 'v3') || str_contains(strtolower($endpointId), '/o3/') || str_contains(strtolower($endpointId), 'v2.6')))) {
                $input[$field] = $audio;
            }
        }

        $imageUrls = array_values(array_filter($options['image_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));
        $videoUrls = array_values(array_filter($options['video_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));
        $audioUrls = array_values(array_filter($options['audio_urls'] ?? [], fn ($u) => is_string($u) && $u !== ''));

        if ($mode === 'image-to-video' && $imageUrls !== []) {
            $param = (string) ($options['first_frame_param'] ?? 'image_url');
            $input[$param] = $imageUrls[0];
        }

        if ($mode === 'first-last-frame-to-video' && $imageUrls !== []) {
            $firstParam = (string) ($options['first_frame_param'] ?? 'first_frame_url');
            $lastParam = (string) ($options['last_frame_param'] ?? 'last_frame_url');
            $input[$firstParam] = $imageUrls[0];
            if (isset($imageUrls[1])) {
                $input[$lastParam] = $imageUrls[1];
            }

            // Kling O1 prompt can reference @Image1 / @Image2 for start/end frames.
            if (str_contains(strtolower($endpointId), 'kling-video/o1/') && str_contains(strtolower($endpointId), 'image-to-video')) {
                $input['prompt'] = $this->withReferencePrefix(
                    $prompt,
                    isset($imageUrls[1]) ? '@Image1 @Image2' : '@Image1',
                );
            }
        }

        if ($mode === 'reference-to-video') {
            $id = strtolower($endpointId);
            if (str_contains($id, 'kling-video') && str_contains($id, 'video-to-video/edit')) {
                if ($videoUrls !== [] && $imageUrls !== []) {
                    $elements = $this->buildKlingElements(array_slice($imageUrls, 0, 3));
                    $input['prompt'] = $this->normalizeKlingEditPrompt($prompt, count($elements));
                    $input['video_url'] = $videoUrls[0];
                    $input['elements'] = $elements;
                    $input['keep_audio'] = $audio;
                    unset($input['aspect_ratio'], $input['duration'], $input['resolution'], $input['generate_audio']);
                }
            } elseif (str_contains($id, 'kling-video') && str_contains($id, 'reference-to-video')) {
                // Character sheets → @Element1..N; optional motion sketch → extra video element.
                // Fal allows only one element with video_url. Do not send top-level video_urls.
                $limit = (str_contains($id, '/o1/') || str_contains($id, '/4k/')) ? 7 : 4;
                $imageLimit = $videoUrls !== [] ? max(1, $limit - 1) : $limit;
                $elements = $this->buildKlingElements(array_slice($imageUrls, 0, $imageLimit));
                $imageCount = count($elements);
                if ($videoUrls !== []) {
                    $elements[] = ['video_url' => $videoUrls[0]];
                }
                if ($elements !== []) {
                    $input['prompt'] = $this->normalizeKlingR2VPrompt($prompt, $imageCount, $videoUrls !== []);
                    $input['elements'] = $elements;
                }
                unset($input['video_urls'], $input['image_urls'], $input['resolution']);
            } elseif (str_contains($id, 'wan/v2.7/reference-to-video')) {
                if ($imageUrls !== []) {
                    $input['reference_image_urls'] = array_slice($imageUrls, 0, 5);
                }
                if ($videoUrls !== []) {
                    $input['reference_video_urls'] = array_slice($videoUrls, 0, 5);
                }
            } elseif (str_contains($id, 'minimax/h3') && str_contains($id, 'reference-to-video')) {
                // Kapwing/Seedance-style: @ImageN / @Video1 → "Image N" / "Video 1".
                if ($imageUrls !== []) {
                    $input['reference_image_urls'] = array_slice($imageUrls, 0, 9);
                }
                if ($videoUrls !== []) {
                    $input['reference_video_urls'] = array_slice($videoUrls, 0, 3);
                }
                if ($audioUrls !== []) {
                    $input['reference_audio_urls'] = array_slice($audioUrls, 0, 3);
                }
                $input['prompt'] = $this->normalizeMiniMaxH3Prompt($prompt);
                $input['prompt_expansion_mode'] = (string) ($options['prompt_expansion_mode'] ?? 'disabled');
                $input['enable_safety_checker'] = (bool) ($options['enable_safety_checker'] ?? true);
                unset($input['image_urls'], $input['video_urls'], $input['audio_urls'], $input['generate_audio']);
            } elseif (str_contains($id, 'pixverse/c1/reference-to-video')) {
                $references = $this->buildPixVerseReferences(array_slice($imageUrls, 0, 5));
                if ($references !== []) {
                    $input['prompt'] = $this->withReferencePrefix($prompt, $this->referenceList('@ref', count($references)));
                    $input['image_references'] = $references;
                }
            } elseif (str_contains($id, 'gemini-omni-flash/edit')) {
                // Edit: prompt + video_url only (no duration / aspect / resolution on Fal).
                if ($videoUrls !== []) {
                    $input['video_url'] = $videoUrls[0];
                }
                unset($input['aspect_ratio'], $input['duration'], $input['resolution']);
            } elseif (str_contains($id, 'gemini-omni-flash')) {
                // R2V: image_urls only (published schema has no video_urls / audio_urls; max 10).
                if ($imageUrls !== []) {
                    $input['image_urls'] = array_slice($imageUrls, 0, 10);
                }
            } elseif ($imageUrls !== []) {
                $maxImages = (str_contains($id, 'seedance-2.5') || str_contains($id, 'seedance/2.5')) ? 30 : 9;
                $input['image_urls'] = array_slice($imageUrls, 0, $maxImages);
            }
            if (
                $videoUrls !== []
                && ! str_contains($id, 'wan/v2.7/reference-to-video')
                && ! (str_contains($id, 'minimax/h3') && str_contains($id, 'reference-to-video'))
                && ! (str_contains($id, 'kling-video') && str_contains($id, 'reference-to-video'))
                && ! (str_contains($id, 'kling-video') && str_contains($id, 'video-to-video/edit'))
                && ! str_contains($id, 'gemini-omni-flash')
            ) {
                $maxVideos = (str_contains($id, 'seedance-2.5') || str_contains($id, 'seedance/2.5')) ? 10 : 3;
                $input['video_urls'] = array_slice($videoUrls, 0, $maxVideos);
            }
            if (
                $audioUrls !== []
                && ! (str_contains($id, 'minimax/h3') && str_contains($id, 'reference-to-video'))
                && ! (str_contains($id, 'kling-video') && str_contains($id, 'video-to-video/edit'))
                && ! str_contains($id, 'gemini-omni-flash')
            ) {
                $maxAudios = (str_contains($id, 'seedance-2.5') || str_contains($id, 'seedance/2.5')) ? 10 : 3;
                $input['audio_urls'] = array_slice($audioUrls, 0, $maxAudios);
            }
        }

        $input = $this->applyWanPromptDefaults($endpointId, $input, $options);
        $input = $this->applyNegativePrompt($endpointId, $input, $options);

        return [
            'input' => $input,
            'duration_seconds' => $durationSeconds,
            'duration_value' => is_string($durationValue) ? $durationValue : (string) $durationValue,
            'aspect_ratio' => $input['aspect_ratio'] ?? $aspect,
            'resolution' => $input['resolution'] ?? $resolution,
            // Omni Flash always returns synced audio (no generate_audio toggle on Fal).
            'with_audio' => (bool) (($input['generate_audio'] ?? false) || ($input['generate_audio_switch'] ?? false))
                || str_contains(strtolower($endpointId), 'gemini-omni-flash'),
        ];
    }

    /**
     * Wan T2V / I2V (and older Wan v2.2 V2V) default enable_prompt_expansion=true on Fal,
     * which rewrites prompts and invents props/text. Force false unless explicitly opted in.
     * Wan 2.7 reference-to-video / edit-video do not expose this field.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function applyWanPromptDefaults(string $endpointId, array $input, array $options): array
    {
        $id = strtolower($endpointId);
        if (! str_contains($id, 'wan/')) {
            return $input;
        }

        // Wan 2.7 I2V schema has no aspect_ratio — framing follows image_url.
        if (str_contains($id, 'image-to-video') && str_contains($id, 'wan/v2.7')) {
            unset($input['aspect_ratio']);
        }

        $supportsExpansion = str_contains($id, 'text-to-video')
            || str_contains($id, 'image-to-video')
            || (str_contains($id, 'video-to-video') && ! str_contains($id, 'edit-video'));

        if ($supportsExpansion) {
            $input['enable_prompt_expansion'] = (bool) ($options['enable_prompt_expansion'] ?? false);
        }

        return $input;
    }

    /**
     * Attach negative_prompt when the Fal endpoint accepts it (Wan T2V/I2V/R2V, Kling I2V).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function applyNegativePrompt(string $endpointId, array $input, array $options): array
    {
        $negative = trim((string) ($options['negative_prompt'] ?? ''));
        if ($negative === '') {
            return $input;
        }

        // Strip a leading "negative prompt:" label users sometimes paste from other UIs.
        $negative = preg_replace('/^\s*negative\s*prompts?\s*:\s*/i', '', $negative) ?? $negative;
        $negative = trim($negative);
        if ($negative === '') {
            return $input;
        }

        if (! VideoModelCapabilities::endpointSupportsNegativePrompt($endpointId)) {
            return $input;
        }

        $input['negative_prompt'] = mb_substr($negative, 0, 500);

        return $input;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, int|string>|null  $allowed
     */
    private function resolveDurationSeconds(mixed $duration, ?array $allowed, array $profile): int
    {
        if ($duration === 'auto' || $duration === 'Auto') {
            $fromAllowed = $this->parseAllowedSeconds($allowed);
            if ($fromAllowed !== []) {
                return max($fromAllowed);
            }

            return ($profile['duration_format'] ?? '') === 'veo_s' ? 8 : 5;
        }

        $seconds = $this->parseSecondsToken($duration);
        $fromAllowed = $this->parseAllowedSeconds($allowed);

        if ($fromAllowed !== []) {
            if ($seconds !== null && in_array($seconds, $fromAllowed, true)) {
                return $seconds;
            }

            // Nearest allowed
            $target = $seconds ?? 5;
            $best = $fromAllowed[0];
            $bestDist = abs($best - $target);
            foreach ($fromAllowed as $v) {
                $dist = abs($v - $target);
                if ($dist < $bestDist) {
                    $best = $v;
                    $bestDist = $dist;
                }
            }

            return $best;
        }

        return $seconds ?? 5;
    }

    /**
     * @param  array<int, int|string>|null  $allowed
     * @return list<int>
     */
    private function parseAllowedSeconds(?array $allowed): array
    {
        if ($allowed === null) {
            return [];
        }

        $out = [];
        foreach ($allowed as $token) {
            if ($token === 'auto' || $token === 'Auto') {
                continue;
            }
            $n = $this->parseSecondsToken($token);
            if ($n !== null) {
                $out[] = $n;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    private function parseSecondsToken(mixed $token): ?int
    {
        if (is_int($token)) {
            return max(1, $token);
        }
        if (! is_string($token) && ! is_numeric($token)) {
            return null;
        }
        $n = (int) preg_replace('/\D+/', '', (string) $token);

        return $n > 0 ? $n : null;
    }

    /**
     * fal's advertised duration enum for the shared veo3.x schema lists 4s/6s/8s, but the
     * reference-to-video (ingredients) route only accepts 8s. Force it so we never submit an
     * invalid duration (422) or bill for a duration fal will reject.
     */
    private function constrainDurationForEndpoint(string $endpointId, string $mode, int $seconds): int
    {
        $id = strtolower($endpointId);

        if (str_contains($id, 'veo') && ($mode === 'reference-to-video' || str_contains($id, 'reference-to-video'))) {
            return 8;
        }

        // Gemini Omni Flash T2V / I2V / R2V: Fal range is 3–10 seconds (integer).
        // Edit has no duration field — bill by measured clip length (do not clamp to 10).
        if (str_contains($id, 'gemini-omni-flash') && ! str_contains($id, '/edit')) {
            return max(3, min(10, $seconds));
        }

        // Wan 2.7 R2V only accepts 2–10 (I2V/T2V go to 15 — do not confuse them).
        if (str_contains($id, 'wan/v2.7/reference-to-video')) {
            return max(2, min(10, $seconds));
        }

        // Kling O3 / O1 R2V: 3–15 seconds (string enum on fal).
        if (str_contains($id, 'kling-video') && str_contains($id, 'reference-to-video')) {
            return max(3, min(15, $seconds));
        }

        // MiniMax H3 R2V: output duration 5–15 (motion refs also 2–15s).
        if (str_contains($id, 'minimax/h3') && str_contains($id, 'reference-to-video')) {
            return max(5, min(15, $seconds));
        }

        return $seconds;
    }

    /**
     * @return int|string
     */
    private function formatDuration(int $seconds, string $format, mixed $raw)
    {
        return match ($format) {
            'veo_s' => $seconds.'s',
            'int' => $seconds,
            default => (string) $seconds,
        };
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeAspect(?string $aspect, array $allowed): string
    {
        $aspect = $aspect ? trim($aspect) : (in_array('16:9', $allowed, true) ? '16:9' : ($allowed[0] ?? '16:9'));
        if (in_array($aspect, $allowed, true)) {
            return $aspect;
        }
        if ($aspect === 'auto' && in_array('auto', $allowed, true)) {
            return 'auto';
        }

        // Map unsupported ratios onto closest supported ones.
        return match ($aspect) {
            // Legacy UI value — treat as 4:5 social portrait.
            '4:3', '4:5' => in_array('4:5', $allowed, true)
                ? '4:5'
                : (in_array('9:16', $allowed, true) ? '9:16' : (in_array('3:4', $allowed, true) ? '3:4' : ($allowed[0] ?? '16:9'))),
            '3:4' => in_array('3:4', $allowed, true) ? '3:4' : (in_array('9:16', $allowed, true) ? '9:16' : ($allowed[0] ?? '16:9')),
            '3:2' => in_array('3:2', $allowed, true) ? '3:2' : (in_array('16:9', $allowed, true) ? '16:9' : ($allowed[0] ?? '16:9')),
            '2:3' => in_array('2:3', $allowed, true) ? '2:3' : (in_array('9:16', $allowed, true) ? '9:16' : ($allowed[0] ?? '16:9')),
            '1:1' => in_array('1:1', $allowed, true) ? '1:1' : ($allowed[0] ?? '16:9'),
            default => $allowed[0] ?? '16:9',
        };
    }

    /**
     * Wan 2.2 A14B only accepts 16:9 / 9:16 / 1:1 on the distributed GPU endpoint.
     * Portrait Lab ratios (4:5, 3:4) map to 9:16; everything else falls back to 16:9.
     */
    private function mapWan22Aspect(string $aspect): string
    {
        return match ($aspect) {
            '9:16', '4:5', '3:4', '2:3' => '9:16',
            '1:1' => '1:1',
            default => '16:9',
        };
    }

    private function normalizeResolution(?string $resolution): string
    {
        $resolution = strtoupper(trim((string) $resolution));

        return match ($resolution) {
            '1080P', '1080' => '1080p',
            '4K', '2160P' => '4k',
            '720P', '720' => '720p',
            default => in_array(strtolower((string) $resolution), ['720p', '1080p', '4k'], true)
                ? strtolower((string) $resolution)
                : '720p',
        };
    }

    private function mapResolutionForFal(string $resolution, string $endpointId): string
    {
        $id = strtolower($endpointId);

        // MiniMax H3: 480P / 768P / 2K / 4K (uppercase P on fal).
        if (str_contains($id, 'minimax/h3')) {
            return match (strtolower($resolution)) {
                '480p', '480P' => '480P',
                '1080p', '2k', '2K' => '2K',
                '4k', '4K' => '4K',
                default => '768P', // Lab 720p → native 768P
            };
        }

        // Grok Imagine Video only accepts 480p / 720p.
        if (str_contains($id, 'grok-imagine-video')) {
            return $resolution === '480p' ? '480p' : '720p';
        }

        // Wan 2.2 A14B: 480p / 580p / 720p only.
        if (str_contains($id, 'wan/v2.2-a14b')) {
            return match ($resolution) {
                '480p' => '480p',
                '580p' => '580p',
                default => '720p',
            };
        }

        // Veo accepts 720p / 1080p / 4k
        if (str_contains($id, 'veo')) {
            return match ($resolution) {
                '4k' => '4k',
                '1080p' => '1080p',
                default => '720p',
            };
        }

        return $resolution;
    }

    /**
     * @return array<string, mixed>
     */
    private function inferProfile(string $endpointId): array
    {
        $id = strtolower($endpointId);

        if (str_contains($id, 'seedance')) {
            return [
                'duration_format' => 'string',
                'aspects' => ['16:9', '9:16', '1:1', '4:5', '3:4'],
                'resolution' => true,
                'audio' => true,
                'audio_field' => 'generate_audio',
            ];
        }

        if (str_contains($id, 'veo')) {
            return [
                'duration_format' => 'veo_s',
                'aspects' => ['16:9', '9:16'],
                'resolution' => true,
                'audio' => true,
                'audio_field' => 'generate_audio',
            ];
        }

        if (str_contains($id, 'kling')) {
            return [
                'duration_format' => 'string',
                'aspects' => ['16:9', '9:16', '1:1'],
                'audio' => str_contains($id, 'v3') || str_contains($id, '/o3/') || str_contains($id, 'v2.6'),
                'audio_field' => 'generate_audio',
            ];
        }

        if (str_contains($id, 'wan/v2.2-a14b')) {
            return [
                'duration_format' => 'int',
                // Fal distributed GPU rejects "auto" for many image sizes — only these three.
                'aspects' => ['16:9', '9:16', '1:1'],
                'resolution' => true,
            ];
        }

        if (str_contains($id, 'wan/v2.7')) {
            return [
                'duration_format' => 'int',
                'aspects' => ['16:9', '9:16', '1:1', '4:5', '3:4'],
                'resolution' => true,
            ];
        }

        if (str_contains($id, 'grok-imagine-video')) {
            return [
                'duration_format' => 'int',
                'aspects' => str_contains($id, 'image-to-video')
                    ? ['auto', '16:9', '9:16', '1:1', '4:5', '3:4', '3:2', '2:3']
                    : ['16:9', '9:16', '1:1', '4:5', '3:4', '3:2', '2:3'],
                'resolution' => true,
                'i2v_aspect_auto' => str_contains($id, 'image-to-video'),
            ];
        }

        if (str_contains($id, 'gemini-omni-flash')) {
            return [
                'duration_format' => 'int',
                'aspects' => ['16:9', '9:16'],
            ];
        }

        if (str_contains($id, 'pixverse')) {
            return [
                'duration_format' => 'int',
                'aspects' => ['16:9', '9:16', '1:1', '4:5', '3:4'],
                'resolution' => true,
                'audio' => true,
                'audio_field' => 'generate_audio_switch',
            ];
        }

        return [
            'duration_format' => 'string',
            'aspects' => ['16:9', '9:16', '1:1'],
        ];
    }

    /**
     * Lab chips use @image1 / @video1 (normalized to @Image1 / @Video1).
     * Kling O3 edit binds faces via `elements` → @ElementN, and the clip via video_url → @Video1.
     * Leaving @ImageN in the prompt makes Fal look for image_urls and 422s with
     * "Invalid reference index 1 for image. Only 0 images provided."
     */
    private function normalizeKlingEditPrompt(string $prompt, int $elementCount): string
    {
        $prompt = trim($prompt);

        // Face/image chips → element tags (we only send images as elements on this route).
        $prompt = preg_replace('/@Image(\d+)\b/i', '@Element$1', $prompt) ?? $prompt;

        if ($prompt === '') {
            return 'Replace the person in @Video1 with @Element1, matching face identity, skin tone, and lighting while keeping the original motion, camera, and framing.';
        }

        if ($elementCount > 0 && ! preg_match('/@Element\d+\b/i', $prompt)) {
            $prompt = 'Replace the person in the video with @Element1. '.$prompt;
        }

        return $prompt;
    }

    /**
     * MiniMax H3 cites refs as "Image 1" / "Video 1" (not @Image1 / @Video1).
     */
    private function normalizeMiniMaxH3Prompt(string $prompt): string
    {
        $prompt = trim($prompt);
        $prompt = preg_replace('/@Image(\d+)\b/i', 'Image $1', $prompt) ?? $prompt;
        $prompt = preg_replace('/@Video(\d+)\b/i', 'Video $1', $prompt) ?? $prompt;
        $prompt = preg_replace('/@Audio(\d+)\b/i', 'Audio $1', $prompt) ?? $prompt;

        return $prompt;
    }

    /**
     * Hotel Lobby / Kapwing prompts use @Video1 + @ImageN. Kling O3 R2V only understands
     * @ElementN (characters as image elements, motion sketch as one video element).
     */
    private function normalizeKlingR2VPrompt(string $prompt, int $imageElementCount, bool $hasMotionVideo): string
    {
        $prompt = trim($prompt);

        $prompt = preg_replace('/@Image(\d+)\b/i', '@Element$1', $prompt) ?? $prompt;

        if ($hasMotionVideo) {
            $motionTag = '@Element'.($imageElementCount + 1);
            $prompt = preg_replace('/@Video1\b/i', $motionTag, $prompt) ?? $prompt;
        }

        if ($prompt === '') {
            $parts = [];
            for ($i = 1; $i <= $imageElementCount; $i++) {
                $parts[] = '@Element'.$i;
            }
            if ($hasMotionVideo) {
                $parts[] = 'Follow motion from @Element'.($imageElementCount + 1);
            }

            return implode('. ', $parts).'.';
        }

        if ($imageElementCount > 0 && ! preg_match('/@Element\d+\b/i', $prompt)) {
            $prompt = $this->withReferencePrefix($prompt, $this->referenceList('@Element', $imageElementCount));
        }

        return $prompt;
    }

    /**
     * @param  list<string>  $imageUrls
     * @return list<array{frontal_image_url: string, reference_image_urls: list<string>}>
     */
    private function buildKlingElements(array $imageUrls): array
    {
        return array_values(array_map(
            static fn (string $url): array => [
                'frontal_image_url' => $url,
                // Fal requires both fields for image elements (O3 edit / R2V).
                'reference_image_urls' => [$url],
            ],
            $imageUrls,
        ));
    }

    /**
     * @param  list<string>  $imageUrls
     * @return list<array{type: string, image_url: string, ref_name: string}>
     */
    private function buildPixVerseReferences(array $imageUrls): array
    {
        return array_values(array_map(
            static fn (string $url, int $index): array => [
                'type' => 'subject',
                'image_url' => $url,
                'ref_name' => 'ref'.($index + 1),
            ],
            $imageUrls,
            array_keys($imageUrls),
        ));
    }

    private function withReferencePrefix(string $prompt, string $references): string
    {
        if ($references === '' || str_contains($prompt, '@')) {
            return $prompt;
        }

        return "Use {$references} as the provided visual references. {$prompt}";
    }

    private function referenceList(string $prefix, int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        $labels = [];
        for ($i = 1; $i <= $count; $i++) {
            $labels[] = "{$prefix}{$i}";
        }

        return implode(', ', $labels);
    }
}
