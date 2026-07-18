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

        // Seedance / some R2V endpoints accept aspect_ratio "auto"
        if (str_contains(strtolower($endpointId), 'seedance') && str_contains(strtolower($endpointId), 'reference')) {
            $input['aspect_ratio'] = $aspect === '16:9' || $aspect === '9:16' || $aspect === '1:1' ? $aspect : 'auto';
        } elseif (! empty($profile['i2v_aspect_auto']) && $mode === 'image-to-video') {
            // Grok I2V: preserve the source image framing (Fal default). Forcing 9:16
            // on a square logo / portrait photo is what made outputs look "ugly".
            $input['aspect_ratio'] = 'auto';
        } else {
            $input['aspect_ratio'] = $aspect;
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
            if (str_contains($id, 'kling-video') && str_contains($id, 'reference-to-video')) {
                $limit = (str_contains($id, '/o1/') || str_contains($id, '/4k/')) ? 7 : 4;
                $elements = $this->buildKlingElements(array_slice($imageUrls, 0, $limit));
                if ($elements !== []) {
                    $input['prompt'] = $this->withReferencePrefix($prompt, $this->referenceList('@Element', count($elements)));
                    $input['elements'] = $elements;
                }
            } elseif (str_contains($id, 'wan/v2.7/reference-to-video')) {
                if ($imageUrls !== []) {
                    $input['reference_image_urls'] = array_slice($imageUrls, 0, 5);
                }
                if ($videoUrls !== []) {
                    $input['reference_video_urls'] = array_slice($videoUrls, 0, 5);
                }
            } elseif (str_contains($id, 'pixverse/c1/reference-to-video')) {
                $references = $this->buildPixVerseReferences(array_slice($imageUrls, 0, 5));
                if ($references !== []) {
                    $input['prompt'] = $this->withReferencePrefix($prompt, $this->referenceList('@ref', count($references)));
                    $input['image_references'] = $references;
                }
            } elseif ($imageUrls !== []) {
                $input['image_urls'] = array_slice($imageUrls, 0, 9);
            }
            if ($videoUrls !== [] && ! str_contains($id, 'wan/v2.7/reference-to-video')) {
                $input['video_urls'] = array_slice($videoUrls, 0, 3);
            }
            if ($audioUrls !== []) {
                $input['audio_urls'] = array_slice($audioUrls, 0, 3);
            }
        }

        return [
            'input' => $input,
            'duration_seconds' => $durationSeconds,
            'duration_value' => is_string($durationValue) ? $durationValue : (string) $durationValue,
            'aspect_ratio' => $input['aspect_ratio'] ?? $aspect,
            'resolution' => $input['resolution'] ?? $resolution,
            'with_audio' => (bool) (($input['generate_audio'] ?? false) || ($input['generate_audio_switch'] ?? false)),
        ];
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

        // Grok Imagine Video only accepts 480p / 720p.
        if (str_contains($id, 'grok-imagine-video')) {
            return $resolution === '480p' ? '480p' : '720p';
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
     * @param  list<string>  $imageUrls
     * @return list<array{frontal_image_url: string}>
     */
    private function buildKlingElements(array $imageUrls): array
    {
        return array_values(array_map(
            static fn (string $url): array => ['frontal_image_url' => $url],
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
