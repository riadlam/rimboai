<?php

namespace App\Services\Tools;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds a client-safe tool workspace from video_tools_models.
 * Never exposes Fal model names / endpoint_ids to the browser.
 */
class ToolWorkspaceBuilder
{
    /**
     * @return array{
     *   available: bool,
     *   tool_slug: string,
     *   model_id: int|null,
     *   billing: array<string, mixed>|null,
     *   uploads: list<array<string, mixed>>,
     *   controls: list<array<string, mixed>>,
     *   notices: list<string>,
     * }
     */
    public function build(string $toolSlug): array
    {
        $primary = $this->primaryModel($toolSlug);

        if (! $primary) {
            return [
                'available' => false,
                'tool_slug' => $toolSlug,
                'model_id' => null,
                'billing' => null,
                'uploads' => [['key' => 'video', 'accept' => 'video/*', 'required' => true, 'label_key' => 'upload']],
                'controls' => [],
                'notices' => ['unavailable'],
            ];
        }

        $enums = $this->decodeJson($primary->enums);
        $defaults = $this->decodeJson($primary->defaults);

        return [
            'available' => true,
            'tool_slug' => $toolSlug,
            'model_id' => (int) $primary->id,
            'billing' => [
                'unit' => (string) ($primary->unit ?? 'seconds'),
                'unit_price' => (float) ($primary->unit_price ?? 0),
                'max_duration' => $primary->max_duration !== null ? (int) $primary->max_duration : null,
                'ref_duration_seconds' => $primary->ref_duration_seconds !== null
                    ? (int) $primary->ref_duration_seconds
                    : 5,
            ],
            'uploads' => $this->uploadsFor($toolSlug),
            'controls' => $this->controlsFor($toolSlug, $enums, $defaults),
            'notices' => $this->noticesFor($toolSlug, $primary),
        ];
    }

    private function primaryModel(string $toolSlug): ?object
    {
        if (! Schema::hasTable('video_tools_models')) {
            return null;
        }

        return DB::table('video_tools_models')
            ->where('tool_slug', $toolSlug)
            ->where('status', 'active')
            ->orderByDesc('is_primary')
            ->orderBy('sort')
            ->first();
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
            // 2️⃣ Video Enhancer — single Strength slider (0 → 1)
            'video-enhancer' => [
                [
                    'type' => 'slider',
                    'key' => 'strength',
                    'label_key' => 'strength',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.05,
                    'default' => (float) ($defaults['noise_scale'] ?? 0.5),
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
            // 8️⃣ Video To Video — prompt (required) + Strength + Guidance
            'video-to-video' => [
                [
                    'type' => 'textarea',
                    'key' => 'prompt',
                    'label_key' => 'editPrompt',
                    'placeholder_key' => 'editPromptPlaceholder',
                    'default' => '',
                    'required' => true,
                ],
                [
                    'type' => 'slider',
                    'key' => 'strength',
                    'label_key' => 'strength',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.05,
                    'default' => (float) ($defaults['strength'] ?? 0.5),
                ],
                [
                    'type' => 'slider',
                    'key' => 'guidance_scale',
                    'label_key' => 'guidanceScale',
                    'min' => 1,
                    'max' => 10,
                    'step' => 0.5,
                    'default' => (float) ($defaults['guidance_scale'] ?? 5),
                ],
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
