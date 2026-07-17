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
                ['key' => 'video', 'accept' => 'video/*', 'required' => true, 'label_key' => 'upload'],
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
            'video-upscaler' => [
                [
                    'type' => 'choice',
                    'key' => 'scale',
                    'label_key' => 'scale',
                    'options' => $this->stringOptions($enums) ?: ['2x', '4x'],
                    'default' => $this->scaleDefault($defaults),
                ],
            ],
            'video-enhancer' => [
                [
                    'type' => 'choice',
                    'key' => 'resolution',
                    'label_key' => 'resolution',
                    'options' => $this->stringOptions($enums) ?: ['720p', '1080p'],
                    'default' => (string) ($defaults['target_resolution'] ?? '1080p'),
                ],
            ],
            'lip-sync' => [
                [
                    'type' => 'choice',
                    'key' => 'sync_mode',
                    'label_key' => 'syncMode',
                    'options' => $this->stringOptions($enums) ?: ['cut_off', 'loop', 'silence'],
                    'default' => (string) ($defaults['sync_mode'] ?? 'cut_off'),
                    'option_label_prefix' => 'syncModes',
                ],
            ],
            'face-swap-video' => [
                [
                    'type' => 'choice',
                    'key' => 'resolution',
                    'label_key' => 'resolution',
                    'options' => $this->stringOptions($enums) ?: ['540p', '720p'],
                    'default' => (string) ($defaults['resolution'] ?? '720p'),
                ],
            ],
            'video-background-remover' => [
                [
                    'type' => 'toggle',
                    'key' => 'refine_edges',
                    'label_key' => 'refineEdges',
                    'desc_key' => 'refineEdgesDesc',
                    'default' => (bool) ($defaults['refine_foreground_edges'] ?? true),
                ],
                [
                    'type' => 'toggle',
                    'key' => 'subject_is_person',
                    'label_key' => 'subjectPerson',
                    'desc_key' => 'subjectPersonDesc',
                    'default' => (bool) ($defaults['subject_is_person'] ?? true),
                ],
                [
                    'type' => 'choice',
                    'key' => 'output_codec',
                    'label_key' => 'outputCodec',
                    'options' => $this->stringOptions($enums) ?: ['vp9', 'h264'],
                    'default' => (string) ($defaults['output_codec'] ?? 'vp9'),
                ],
            ],
            'remove-subtitles-from-video' => [
                // Prompt stays server-side in model defaults — no client text field.
            ],
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
                    'key' => 'mode',
                    'label_key' => 'extendMode',
                    'options' => ['end', 'start'],
                    'default' => (string) ($defaults['mode'] ?? 'end'),
                    'option_label_prefix' => 'extendModes',
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
                    'type' => 'choice',
                    'key' => 'resolution',
                    'label_key' => 'resolution',
                    'options' => $this->stringOptions($enums) ?: ['480p', '720p'],
                    'default' => (string) ($defaults['resolution'] ?? '720p'),
                ],
            ],
            'denoise-video' => [
                [
                    'type' => 'slider',
                    'key' => 'noise',
                    'label_key' => 'noiseStrength',
                    'min' => 0,
                    'max' => 1,
                    'step' => 0.05,
                    'default' => (float) ($defaults['noise'] ?? 0.5),
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
     * @param  array<string, mixed>  $defaults
     */
    private function scaleDefault(array $defaults): string
    {
        if (isset($defaults['upscale_factor'])) {
            $n = (float) $defaults['upscale_factor'];
            if ($n >= 3.5) {
                return '4x';
            }
            if ($n >= 1.5) {
                return '2x';
            }
        }

        return '2x';
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
