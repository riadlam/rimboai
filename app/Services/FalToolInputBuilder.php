<?php

namespace App\Services;

/**
 * Builds Fal queue inputs for specialized video tools.
 * Merges DB model defaults (server-side) with whitelisted client settings.
 */
class FalToolInputBuilder
{
    /**
     * @param  array<string, mixed>  $defaults  from video_tools_models.defaults
     * @param  array<string, mixed>  $settings  client control values
     * @param  array{video?: ?string, image?: ?string, audio?: ?string}  $urls
     * @return array{input: array<string, mixed>, duration_seconds: float, resolution: ?string, prompt: ?string}
     */
    public function build(string $toolSlug, string $endpointId, array $defaults, array $settings, array $urls): array
    {
        $videoUrl = $urls['video'] ?? null;
        $imageUrl = $urls['image'] ?? null;
        $audioUrl = $urls['audio'] ?? null;

        if (! is_string($videoUrl) || $videoUrl === '') {
            throw new \InvalidArgumentException('A video upload is required.');
        }

        $input = match ($toolSlug) {
            'video-upscaler' => $this->buildUpscaler($endpointId, $defaults, $settings, $videoUrl),
            'video-enhancer' => $this->buildEnhancer($endpointId, $defaults, $settings, $videoUrl),
            'lip-sync' => $this->buildLipSync($defaults, $settings, $videoUrl, $audioUrl),
            'face-swap-video' => $this->buildFaceSwap($defaults, $settings, $videoUrl, $imageUrl),
            'video-background-remover' => $this->buildBgRemover($endpointId, $defaults, $settings, $videoUrl),
            'remove-subtitles-from-video' => $this->buildSubtitleRemover($endpointId, $defaults, $videoUrl),
            'ai-video-extender' => $this->buildExtender($endpointId, $defaults, $settings, $videoUrl),
            'video-to-video' => $this->buildVideoToVideo($endpointId, $defaults, $settings, $videoUrl),
            'denoise-video' => $this->buildDenoise($endpointId, $defaults, $settings, $videoUrl),
            default => throw new \InvalidArgumentException('This tool is not wired for generation yet.'),
        };

        $duration = (float) ($settings['_duration_seconds'] ?? $settings['duration'] ?? 5);
        if ($toolSlug === 'ai-video-extender') {
            $duration = (float) ($settings['duration'] ?? $defaults['duration'] ?? 5);
        }

        return [
            'input' => $input,
            'duration_seconds' => max(1.0, $duration),
            'resolution' => isset($settings['resolution']) ? (string) $settings['resolution'] : ($defaults['target_resolution'] ?? $defaults['resolution'] ?? null),
            'prompt' => isset($settings['prompt']) && is_string($settings['prompt']) ? $settings['prompt'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildUpscaler(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        if (str_contains($endpointId, 'bytedance-upscaler')) {
            $input = array_merge($defaults, [
                'video_url' => $videoUrl,
            ]);
            if (! empty($settings['resolution'])) {
                $input['target_resolution'] = $this->mapByteDanceResolution((string) $settings['resolution']);
            }

            return $this->onlyKeys($input, [
                'video_url', 'target_resolution', 'target_fps', 'enhancement_preset',
                'enhancement_tier', 'scale_ratio', 'fidelity',
            ]);
        }

        // Topaz / RealESRGAN-style
        $factor = $this->scaleToFactor($settings['scale'] ?? null) ?? (float) ($defaults['upscale_factor'] ?? 2);
        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'upscale_factor' => $factor,
        ]);
        if (str_contains($endpointId, 'video-upscaler') && ! str_contains($endpointId, 'topaz')) {
            $input['scale'] = $factor;
            unset($input['upscale_factor'], $input['model']);
        }

        return $this->onlyKeys($input, [
            'video_url', 'model', 'upscale_factor', 'scale', 'target_fps',
            'compression', 'noise', 'halo', 'grain', 'recover_detail', 'H264_output',
        ]);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildEnhancer(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        if (str_contains($endpointId, 'seedvr')) {
            $input = array_merge($defaults, [
                'video_url' => $videoUrl,
                'upscale_mode' => $defaults['upscale_mode'] ?? 'target',
            ]);
            if (! empty($settings['resolution'])) {
                $input['target_resolution'] = (string) $settings['resolution'];
            }

            return $this->onlyKeys($input, [
                'video_url', 'upscale_mode', 'upscale_factor', 'target_resolution',
                'seed', 'noise_scale', 'output_format', 'output_quality', 'output_write_mode',
            ]);
        }

        return $this->buildUpscaler($endpointId, $defaults, $settings, $videoUrl);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildLipSync(array $defaults, array $settings, string $videoUrl, ?string $audioUrl): array
    {
        if (! is_string($audioUrl) || $audioUrl === '') {
            throw new \InvalidArgumentException('An audio upload is required for lip sync.');
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'audio_url' => $audioUrl,
        ]);
        if (! empty($settings['sync_mode'])) {
            $input['sync_mode'] = (string) $settings['sync_mode'];
        }

        return $this->onlyKeys($input, ['video_url', 'audio_url', 'sync_mode', 'model']);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildFaceSwap(array $defaults, array $settings, string $videoUrl, ?string $imageUrl): array
    {
        if (! is_string($imageUrl) || $imageUrl === '') {
            throw new \InvalidArgumentException('A face image is required for face swap.');
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'image_url' => $imageUrl,
            'mode' => $defaults['mode'] ?? 'person',
        ]);
        if (! empty($settings['resolution'])) {
            $input['resolution'] = (string) $settings['resolution'];
        }

        return $this->onlyKeys($input, [
            'video_url', 'image_url', 'mode', 'resolution', 'keyframe_id', 'seed', 'original_sound_switch',
        ]);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildBgRemover(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        if (str_contains($endpointId, 'bria/')) {
            $input = array_merge($defaults, ['video_url' => $videoUrl]);

            return $this->onlyKeys($input, [
                'video_url', 'background_color', 'preserve_audio', 'output_container_and_codec',
            ]);
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'refine_foreground_edges' => array_key_exists('refine_edges', $settings)
                ? (bool) $settings['refine_edges']
                : (bool) ($defaults['refine_foreground_edges'] ?? true),
            'subject_is_person' => array_key_exists('subject_is_person', $settings)
                ? (bool) $settings['subject_is_person']
                : (bool) ($defaults['subject_is_person'] ?? true),
        ]);
        if (! empty($settings['output_codec'])) {
            $input['output_codec'] = (string) $settings['output_codec'];
        }

        return $this->onlyKeys($input, [
            'video_url', 'output_codec', 'refine_foreground_edges', 'subject_is_person',
        ]);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function buildSubtitleRemover(string $endpointId, array $defaults, string $videoUrl): array
    {
        if (str_contains($endpointId, 'void-video-inpainting')) {
            return $this->onlyKeys(array_merge($defaults, [
                'video_url' => $videoUrl,
            ]), ['video_url', 'prompt', 'mask_prompt', 'quad_mask_video_url']);
        }

        return $this->onlyKeys(array_merge($defaults, [
            'video_url' => $videoUrl,
            'prompt' => $defaults['prompt'] ?? 'subtitles, captions, on-screen text',
        ]), ['video_url', 'prompt']);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildExtender(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        $duration = (float) ($settings['duration'] ?? $defaults['duration'] ?? 5);
        $mode = (string) ($settings['mode'] ?? $defaults['mode'] ?? 'end');
        $prompt = trim((string) ($settings['prompt'] ?? ''));

        if (str_contains($endpointId, 'pixverse')) {
            $input = array_merge($defaults, [
                'video_url' => $videoUrl,
                'duration' => (int) round($duration),
            ]);
            if ($prompt !== '') {
                $input['prompt'] = $prompt;
            }
            if (! empty($settings['resolution'])) {
                $input['resolution'] = (string) $settings['resolution'];
            }

            return $this->onlyKeys($input, [
                'video_url', 'prompt', 'duration', 'resolution', 'generate_audio_switch',
            ]);
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'duration' => $duration,
            'mode' => in_array($mode, ['start', 'end'], true) ? $mode : 'end',
        ]);
        if ($prompt !== '') {
            $input['prompt'] = $prompt;
        }

        return $this->onlyKeys($input, ['video_url', 'prompt', 'duration', 'mode', 'context']);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildVideoToVideo(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        $prompt = trim((string) ($settings['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException('An edit prompt is required.');
        }

        if (str_contains($endpointId, 'kling-video')) {
            return $this->onlyKeys([
                'video_url' => $videoUrl,
                'prompt' => $prompt,
            ], ['video_url', 'prompt']);
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'prompt' => $prompt,
        ]);
        if (! empty($settings['resolution'])) {
            $input['resolution'] = (string) $settings['resolution'];
        }

        return $this->onlyKeys($input, [
            'video_url', 'prompt', 'video_type', 'resolution', 'acceleration',
            'enable_auto_downsample', 'aspect_ratio', 'auto_downsample_min_fps',
            'enable_safety_checker', 'image_urls',
        ]);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildDenoise(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        if (str_contains($endpointId, 'seedvr')) {
            $input = array_merge($defaults, ['video_url' => $videoUrl]);
            if (array_key_exists('noise', $settings)) {
                $input['noise_scale'] = (float) $settings['noise'];
            }

            return $this->onlyKeys($input, [
                'video_url', 'upscale_mode', 'upscale_factor', 'target_resolution',
                'noise_scale', 'output_format', 'output_quality', 'output_write_mode',
            ]);
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'upscale_factor' => (float) ($defaults['upscale_factor'] ?? 1),
        ]);
        if (array_key_exists('noise', $settings)) {
            $input['noise'] = (float) $settings['noise'];
        }

        return $this->onlyKeys($input, [
            'video_url', 'model', 'upscale_factor', 'noise', 'compression', 'halo', 'grain', 'recover_detail',
        ]);
    }

    private function scaleToFactor(mixed $scale): ?float
    {
        if ($scale === null || $scale === '') {
            return null;
        }
        if (is_numeric($scale)) {
            return (float) $scale;
        }
        if (is_string($scale) && preg_match('/^(\d+(?:\.\d+)?)x$/i', $scale, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    private function mapByteDanceResolution(string $resolution): string
    {
        $r = strtolower($resolution);

        return match ($r) {
            '2k', '1440p' => '2k',
            '4k', '2160p' => '4k',
            default => '1080p',
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function onlyKeys(array $input, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
