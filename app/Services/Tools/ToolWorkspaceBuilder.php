<?php

namespace App\Services\Tools;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds a client-safe tool workspace from video_tools_models.
 * Exposes product model names (not raw Fal endpoint IDs) for the picker.
 */
class ToolWorkspaceBuilder
{
    /**
     * @return array{
     *   available: bool,
     *   tool_slug: string,
     *   model_id: int|null,
     *   models: list<array<string, mixed>>,
     *   billing: array<string, mixed>|null,
     *   uploads: list<array<string, mixed>>,
     *   controls: list<array<string, mixed>>,
     *   notices: list<string>,
     * }
     */
    public function build(string $toolSlug): array
    {
        $models = $this->activeModels($toolSlug);
        $primary = $models->first();

        if (! $primary) {
            return [
                'available' => false,
                'tool_slug' => $toolSlug,
                'model_id' => null,
                'models' => [],
                'billing' => null,
                'uploads' => [['key' => 'video', 'accept' => 'video/*', 'required' => true, 'label_key' => 'upload']],
                'controls' => [],
                'notices' => ['unavailable'],
            ];
        }

        $enums = $this->decodeJson($primary->enums);
        $defaults = $this->decodeJson($primary->defaults) ?? [];

        return [
            'available' => true,
            'tool_slug' => $toolSlug,
            'model_id' => (int) $primary->id,
            'models' => $models
                ->map(fn ($row) => $this->serializeModelOption($row))
                ->values()
                ->all(),
            'billing' => $this->billingFor($primary, $toolSlug, $enums, $defaults),
            'uploads' => $this->uploadsFor($toolSlug),
            'controls' => $this->controlsFor($toolSlug, $enums, $defaults),
            'notices' => $this->noticesFor($toolSlug, $primary),
        ];
    }

    /**
     * @return Collection<int, object>
     */
    private function activeModels(string $toolSlug): Collection
    {
        if (! Schema::hasTable('video_tools_models')) {
            return collect();
        }

        return DB::table('video_tools_models')
            ->where('tool_slug', $toolSlug)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('sort')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeModelOption(object $row): array
    {
        $enums = $this->decodeJson($row->enums);
        $defaults = $this->decodeJson($row->defaults) ?? [];
        $toolSlug = (string) ($row->tool_slug ?? '');

        return [
            'id' => (int) $row->id,
            'name' => (string) ($row->name ?: 'Model'),
            'description' => (string) ($row->description ?? ''),
            'is_primary' => (bool) ($row->is_primary ?? false),
            'image_url' => is_string($row->image_url ?? null) ? $row->image_url : null,
            'billing' => $this->billingFor($row, $toolSlug, $enums, $defaults),
        ];
    }

    /**
     * @param  list<mixed>|null  $enums
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function billingFor(object $row, string $toolSlug, ?array $enums, array $defaults): array
    {
        return [
            'unit' => (string) ($row->unit ?? 'seconds'),
            'unit_price' => (float) ($row->unit_price ?? 0),
            // Fal tiers that change $/s (or flat $/clip) by output resolution.
            'unit_price_by_resolution' => self::unitPriceByResolution(
                (string) $row->endpoint_id,
                (string) ($row->unit ?? 'seconds'),
            ),
            'max_duration' => $row->max_duration !== null ? (int) $row->max_duration : null,
            'ref_duration_seconds' => $row->ref_duration_seconds !== null
                ? (int) $row->ref_duration_seconds
                : 5,
            'duration_enums' => self::durationEnumsFor(
                $toolSlug,
                $enums,
                $defaults,
                $row->max_duration !== null ? (int) $row->max_duration : null,
            ),
        ];
    }

    private function primaryModel(string $toolSlug): ?object
    {
        return $this->activeModels($toolSlug)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function uploadsFor(string $slug): array
    {
        return match ($slug) {
            'lip-sync' => [
                ['key' => 'video', 'accept' => 'video/*', 'required' => true, 'label_key' => 'upload'],
                ['key' => 'audio', 'accept' => 'audio/*', 'required' => true, 'label_key' => 'uploadAudio'],
            ],
            'face-swap-video' => [
                ['key' => 'video', 'accept' => 'video/*', 'required' => true, 'label_key' => 'uploadTarget'],
                ['key' => 'image', 'accept' => 'image/*', 'required' => true, 'label_key' => 'uploadFace'],
            ],
            'video-to-video' => [
                ['key' => 'video', 'accept' => 'video/*', 'required' => true, 'label_key' => 'upload'],
            ],
            // Image → video tools take a single image input.
            'animate-a-picture', 'motion-control' => [
                ['key' => 'image', 'accept' => 'image/*', 'required' => true, 'label_key' => 'uploadImage'],
            ],
            // Dance generator: character image + a driving reference video.
            'ai-dance-generator' => [
                ['key' => 'image', 'accept' => 'image/*', 'required' => true, 'label_key' => 'uploadCharacter'],
                ['key' => 'video', 'accept' => 'video/*', 'required' => true, 'label_key' => 'uploadDriveVideo'],
            ],
            default => [
                ['key' => 'video', 'accept' => 'video/*', 'required' => true, 'label_key' => 'upload'],
            ],
        };
    }

    /**
     * @param  list<mixed>|null  $enums
     * @param  array<string, mixed>  $defaults
     * @return list<array<string, mixed>>
     */
    private function controlsFor(string $slug, ?array $enums, array $defaults): array
    {
        return match ($slug) {
            // 1️⃣ Video Upscaler — Scale 2× / 4×
            'video-upscaler' => [
                [
                    'type' => 'choice',
                    'key' => 'scale',
                    'label_key' => 'scale',
                    'options' => $this->scaleOptions($enums),
                    'default' => $this->scaleDefault($defaults),
                ],
            ],
            // 2️⃣ Video Enhancer — Strength + output resolution (SeedVR target_resolution)
            'video-enhancer' => [
                [
                    'type' => 'slider',
                    'key' => 'strength',
                    'label_key' => 'strength',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.05,
                    'default' => (float) ($defaults['noise_scale'] ?? $defaults['recover_detail'] ?? 0.5),
                ],
                $this->resolutionControl($enums, $defaults, ['720p', '1080p', '1440p', '2160p']),
            ],
            // Anime enhancer primary is Topaz (scale) — no resolution UI
            'anime-video-enhancer' => [
                [
                    'type' => 'slider',
                    'key' => 'strength',
                    'label_key' => 'strength',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.05,
                    'default' => (float) ($defaults['noise_scale'] ?? $defaults['recover_detail'] ?? 0.5),
                ],
            ],
            // 3️⃣ Lip Sync AI — Sync Mode
            'lip-sync' => [
                [
                    'type' => 'choice',
                    'key' => 'sync_mode',
                    'label_key' => 'syncMode',
                    'options' => $this->stringOptions($enums) ?: ['cut_off', 'loop', 'bounce', 'silence', 'remap'],
                    'default' => (string) ($defaults['sync_mode'] ?? 'cut_off'),
                    'option_label_prefix' => 'syncModes',
                ],
            ],
            // 4️⃣ Face Swap Video — no resolution/quality controls (face index not supported by model)
            'face-swap-video' => [],
            // 5️⃣ Video Background Remover — Background + Preserve Audio
            'video-background-remover' => [
                [
                    'type' => 'choice',
                    'key' => 'background',
                    'label_key' => 'background',
                    'options' => ['transparent', 'white', 'black'],
                    'default' => $this->backgroundDefault($defaults),
                    'option_label_prefix' => 'backgrounds',
                ],
                [
                    'type' => 'toggle',
                    'key' => 'preserve_audio',
                    'label_key' => 'preserveAudio',
                    'desc_key' => 'preserveAudioDesc',
                    'default' => (bool) ($defaults['preserve_audio'] ?? true),
                ],
            ],
            // 6️⃣ Video Subtitle Remover — prompt-driven
            'remove-subtitles-from-video' => [
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'subtitlePrompt',
                    'placeholder_key' => 'subtitlePromptPlaceholder',
                    'default' => (string) ($defaults['prompt'] ?? 'remove subtitles'),
                    'required' => false,
                ],
            ],
            // 7️⃣ AI Video Extender — Duration + Direction + optional prompt
            'ai-video-extender' => [
                [
                    'type' => 'choice',
                    'key' => 'duration',
                    'label_key' => 'extendDuration',
                    'options' => $this->stringOptions($enums) ?: ['2', '5', '8', '10'],
                    'default' => (string) ($defaults['duration'] ?? '5'),
                    'suffix' => 's',
                ],
                [
                    'type' => 'choice',
                    'key' => 'direction',
                    'label_key' => 'extendDirection',
                    'options' => ['forward', 'backward'],
                    'default' => $this->directionDefault($defaults),
                    'option_label_prefix' => 'directions',
                ],
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'extendPrompt',
                    'placeholder_key' => 'extendPromptPlaceholder',
                    'default' => '',
                    'required' => false,
                ],
            ],
            // 8️⃣ Video To Video — Wan 2.7 edit: prompt-driven, keeps the shot.
            'video-to-video' => [
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'editPrompt',
                    'placeholder_key' => 'editPromptPlaceholder',
                    'default' => '',
                    'required' => true,
                ],
                $this->aspectRatioControl($defaults, ['auto', '16:9', '9:16', '1:1', '4:5', '3:4']),
                $this->resolutionControl($enums, $defaults, ['720p', '1080p']),
            ],
            // 9️⃣ Denoise Video — single Strength slider
            'denoise-video' => [
                [
                    'type' => 'slider',
                    'key' => 'strength',
                    'label_key' => 'strength',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.05,
                    'default' => (float) ($defaults['noise'] ?? $defaults['noise_scale'] ?? 0.5),
                ],
            ],

            // Image to Animation — motion prompt + duration
            'animate-a-picture' => [
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'motionPrompt',
                    'placeholder_key' => 'motionPromptPlaceholder',
                    'default' => '',
                    'required' => true,
                ],
                [
                    'type' => 'choice',
                    'key' => 'duration',
                    'label_key' => 'duration',
                    'options' => $this->stringOptions($enums) ?: ['5', '10'],
                    'default' => (string) ($defaults['duration'] ?? '5'),
                    'suffix' => 's',
                ],
            ],

            // AI Dance Generator — output resolution only
            'ai-dance-generator' => [
                $this->resolutionControl($enums, $defaults, ['480p', '580p', '720p']),
            ],

            // Video to Anime — style detail + Wan edit resolution/aspect
            'video-to-anime-ai' => [
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'animeDetailPrompt',
                    'placeholder_key' => 'animeDetailPlaceholder',
                    'default' => '',
                    'required' => false,
                ],
                $this->aspectRatioControl($defaults, ['16:9', '9:16', '1:1', '4:5', '3:4']),
                $this->resolutionControl($enums, $defaults, ['720p', '1080p']),
            ],

            // AI Video Filters — Wan 2.7 edit: filter preset + res/aspect (prompt-driven)
            'ai-video-filters' => [
                [
                    'type' => 'choice',
                    'key' => 'filter',
                    'label_key' => 'filter',
                    'options' => ['cinematic', 'vintage', 'noir', 'vibrant', 'warm', 'cold'],
                    'default' => 'cinematic',
                    'option_label_prefix' => 'filters',
                ],
                $this->aspectRatioControl($defaults, ['auto', '16:9', '9:16', '1:1', '4:5', '3:4']),
                $this->resolutionControl($enums, $defaults, ['720p', '1080p']),
            ],

            // Motion Control — camera + duration + PixVerse resolution
            'motion-control' => [
                [
                    'type' => 'choice',
                    'key' => 'camera_movement',
                    'label_key' => 'cameraMovement',
                    'options' => [
                        'zoom_in', 'zoom_out', 'pan_left', 'pan_right',
                        'horizontal_left', 'horizontal_right', 'vertical_up', 'vertical_down', 'crane_up',
                    ],
                    'default' => 'zoom_in',
                    'option_label_prefix' => 'cameraMovements',
                ],
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'motionPrompt',
                    'placeholder_key' => 'motionPromptPlaceholder',
                    'default' => '',
                    'required' => false,
                ],
                $this->resolutionControl(null, $defaults, ['360p', '540p', '720p', '1080p']),
                [
                    'type' => 'choice',
                    'key' => 'duration',
                    'label_key' => 'duration',
                    'options' => $this->stringOptions($enums) ?: ['5', '8'],
                    'default' => (string) ($defaults['duration'] ?? '5'),
                    'suffix' => 's',
                ],
            ],

            // AI Video Editor — Wan 2.7 edit: edit prompt + res/aspect (prompt-driven)
            'ai-video-editor' => [
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'editPrompt',
                    'placeholder_key' => 'editPromptPlaceholder',
                    'default' => '',
                    'required' => true,
                ],
                $this->aspectRatioControl($defaults, ['auto', '16:9', '9:16', '1:1', '4:5', '3:4']),
                $this->resolutionControl($enums, $defaults, ['720p', '1080p']),
            ],

            // AI Sound Effect Generator — sound description prompt
            'ai-sound-effect-generator' => [
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'soundPrompt',
                    'placeholder_key' => 'soundPromptPlaceholder',
                    'default' => '',
                    'required' => true,
                ],
            ],

            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function noticesFor(string $slug, object $primary): array
    {
        $notices = [];
        $max = $primary->max_duration !== null ? (int) $primary->max_duration : null;

        if ($slug === 'remove-subtitles-from-video' && $max !== null) {
            $notices[] = 'max_duration';
        }

        if ($max !== null && $max <= 30) {
            $notices[] = 'max_duration';
        }

        return array_values(array_unique($notices));
    }

    /**
     * Billable duration steps for this tool/model.
     * Client + server snap measured duration UP to the next step.
     *
     * @param  list<mixed>|null  $enums
     * @param  array<string, mixed>|null  $defaults
     * @return list<int>
     */
    public static function durationEnumsFor(
        string $toolSlug,
        ?array $enums,
        ?array $defaults,
        ?int $maxDuration,
    ): array {
        $defaults = is_array($defaults) ? $defaults : [];

        if (is_array($defaults['duration_enums'] ?? null) && $defaults['duration_enums'] !== []) {
            return self::normalizeDurationEnums($defaults['duration_enums']);
        }

        // Numeric values in `enums` are duration steps (e.g. animate ['5','10'], extender ['2','5'…]).
        // Resolution / mode labels like 720p / 2x are ignored.
        $fromEnums = self::normalizeDurationEnums($enums ?? []);
        if ($fromEnums !== []) {
            return $fromEnums;
        }

        // Only when `enums` holds resolutions (not durations), e.g. video-to-anime 720p/1080p.
        $known = match ($toolSlug) {
            'video-to-anime-ai' => [5, 10],
            default => [],
        };
        if ($known !== []) {
            return $known;
        }

        // Continuous per-second models: allow every whole second up to max.
        if ($maxDuration !== null && $maxDuration > 0) {
            return range(1, $maxDuration);
        }

        return [5];
    }

    /**
     * @param  list<mixed>  $values
     * @return list<int>
     */
    private static function normalizeDurationEnums(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_int($value) || is_float($value)) {
                if ($value > 0) {
                    $out[] = (int) round((float) $value);
                }
                continue;
            }
            if (! is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            // Pure numeric duration steps only — skip 720p, 2x, Transparent, etc.
            if (! preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
                continue;
            }
            $n = (int) round((float) $trimmed);
            if ($n > 0) {
                $out[] = $n;
            }
        }

        $out = array_values(array_unique($out));
        sort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * Resolution-tiered Fal prices for models that bill differently by output size.
     * Keys are lowercase resolution labels; values are USD per billing unit.
     *
     * @return array<string, float>|null
     */
    public static function unitPriceByResolution(string $endpointId, string $unit): ?array
    {
        // Wan 2.7 family — $0.10/s @ 720p, $0.15/s @ 1080p
        // https://fal.ai/models/fal-ai/wan/v2.7/edit-video
        // https://fal.ai/models/fal-ai/wan/v2.7/image-to-video
        // https://fal.ai/models/fal-ai/wan/v2.7/text-to-video
        if (
            str_contains($endpointId, 'wan/v2.7/edit-video')
            || str_contains($endpointId, 'wan/v2.7/image-to-video')
            || str_contains($endpointId, 'wan/v2.7/text-to-video')
            || str_contains($endpointId, 'wan/v2.7/reference-to-video')
        ) {
            return [
                '720p' => 0.10,
                '1080p' => 0.15,
            ];
        }

        // Wan Animate Move / Wan 2.2 v2v — billed per "video second" (16fps-normalized).
        // https://fal.ai/models/fal-ai/wan/v2.2-14b/animate/move
        // https://fal.ai/models/fal-ai/wan/v2.2-a14b/video-to-video
        if (
            str_contains($endpointId, 'wan/v2.2-14b/animate/move')
            || str_contains($endpointId, 'wan/v2.2-a14b/video-to-video')
        ) {
            return [
                '480p' => 0.04,
                '580p' => 0.06,
                '720p' => 0.08,
            ];
        }

        // PixVerse V4.5 image-to-video — flat per clip; price changes with resolution (5s base).
        // https://fal.ai/models/fal-ai/pixverse/v4.5/image-to-video
        if (str_contains($endpointId, 'pixverse/v4.5/image-to-video') && $unit === 'video') {
            if (str_contains($endpointId, '/fast')) {
                return [
                    '360p' => 0.30,
                    '540p' => 0.30,
                    '720p' => 0.40,
                    '1080p' => 0.80,
                ];
            }

            return [
                '360p' => 0.15,
                '540p' => 0.15,
                '720p' => 0.20,
                '1080p' => 0.40,
            ];
        }

        // PixVerse Swap — flat per clip; price changes with resolution (5s base, doubles if longer).
        // https://fal.ai/models/fal-ai/pixverse/swap
        if (str_contains($endpointId, 'pixverse/swap') && $unit === 'video') {
            return [
                '360p' => 0.15,
                '540p' => 0.15,
                '720p' => 0.20,
            ];
        }

        // PixVerse V6 Extend — per second by resolution.
        // https://fal.ai/models/fal-ai/pixverse/v6/extend
        if (str_contains($endpointId, 'pixverse/v6/extend')) {
            return [
                '360p' => 0.03,
                '540p' => 0.03,
                '720p' => 0.045,
                '1080p' => 0.09,
            ];
        }

        return null;
    }

    /**
     * Lab-style resolution choice (filters duration enums like "5" out of the list).
     *
     * @param  list<mixed>|null  $enums
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $fallback
     * @return array<string, mixed>
     */
    private function resolutionControl(?array $enums, array $defaults, array $fallback): array
    {
        $options = $this->resolutionOptions($enums);
        if ($options === []) {
            $options = $fallback;
        }

        $default = (string) ($defaults['resolution'] ?? $defaults['target_resolution'] ?? ($options[0] ?? '720p'));
        if (! in_array($default, $options, true)) {
            $default = $options[0] ?? '720p';
        }

        return [
            'type' => 'choice',
            'key' => 'resolution',
            'label_key' => 'resolution',
            'options' => $options,
            'default' => $default,
            'ui' => 'resolution',
        ];
    }

    /**
     * Lab-style aspect ratio choice with mini preview boxes on the client.
     *
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $options
     * @return array<string, mixed>
     */
    private function aspectRatioControl(array $defaults, array $options): array
    {
        $default = (string) ($defaults['aspect_ratio'] ?? ($options[0] ?? '16:9'));
        if (! in_array($default, $options, true)) {
            $default = $options[0] ?? '16:9';
        }

        return [
            'type' => 'choice',
            'key' => 'aspect_ratio',
            'label_key' => 'aspectRatio',
            'options' => $options,
            'default' => $default,
            'option_label_prefix' => 'aspects',
            'ui' => 'aspect',
        ];
    }

    /**
     * @param  list<mixed>|null  $enums
     * @return list<string>
     */
    private function resolutionOptions(?array $enums): array
    {
        $out = [];
        foreach ($enums ?? [] as $value) {
            $s = strtolower(trim((string) $value));
            if ($s === '') {
                continue;
            }
            // Accept 480p / 720p / 1080p / 2k / 4k — skip pure duration numbers and modes.
            if (preg_match('/^(\d{3,4}p|[24]k)$/', $s)) {
                $out[] = $s;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<mixed>|null  $enums
     * @return list<string>
     */
    private function stringOptions(?array $enums): array
    {
        if (! is_array($enums) || $enums === []) {
            return [];
        }

        return array_values(array_map(static fn ($v) => (string) $v, $enums));
    }

    /**
     * @param  list<mixed>|null  $enums
     * @return list<string>
     */
    private function scaleOptions(?array $enums): array
    {
        $opts = $this->stringOptions($enums);
        // Only expose 2×/4× per product spec, even if catalog lists more.
        $filtered = array_values(array_filter($opts, static fn ($o) => in_array($o, ['2x', '4x'], true)));

        return $filtered !== [] ? $filtered : ['2x', '4x'];
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function scaleDefault(array $defaults): string
    {
        if (isset($defaults['upscale_factor'])) {
            $n = (float) $defaults['upscale_factor'];
            if ($n >= 3.5) {
                return '4x';
            }
        }

        return '2x';
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function backgroundDefault(array $defaults): string
    {
        $raw = strtolower((string) ($defaults['background_color'] ?? 'transparent'));

        return in_array($raw, ['transparent', 'white', 'black'], true) ? $raw : 'transparent';
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    private function directionDefault(array $defaults): string
    {
        $mode = (string) ($defaults['mode'] ?? 'end');

        return $mode === 'start' ? 'backward' : 'forward';
    }

    /**
     * @return ($value is null ? null : array<mixed>)
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
