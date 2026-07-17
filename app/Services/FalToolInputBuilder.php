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
            'remove-subtitles-from-video' => $this->buildSubtitleRemover($endpointId, $defaults, $settings, $videoUrl),
            'ai-video-extender' => $this->buildExtender($endpointId, $defaults, $settings, $videoUrl),
            'video-to-video' => $this->buildVideoToVideo($endpointId, $defaults, $settings, $videoUrl, $imageUrl),
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
        // Single "Strength" slider (0 → 1) maps to the model's restoration intensity.
        $strength = $this->clamp01($settings['strength'] ?? null);

        if (str_contains($endpointId, 'seedvr')) {
            $input = array_merge($defaults, [
                'video_url' => $videoUrl,
                'upscale_mode' => $defaults['upscale_mode'] ?? 'target',
            ]);
            if ($strength !== null) {
                $input['noise_scale'] = $strength;
            }

            return $this->onlyKeys($input, [
                'video_url', 'upscale_mode', 'upscale_factor', 'target_resolution',
                'seed', 'noise_scale', 'output_format', 'output_quality', 'output_write_mode',
            ]);
        }

        // Topaz-style fallback: map strength to detail recovery.
        $input = array_merge($defaults, ['video_url' => $videoUrl]);
        if ($strength !== null) {
            $input['recover_detail'] = $strength;
        }

        return $this->onlyKeys($input, [
            'video_url', 'model', 'upscale_factor', 'target_fps',
            'compression', 'noise', 'halo', 'grain', 'recover_detail', 'H264_output',
        ]);
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

        // No quality/resolution controls exposed — model handles output automatically.
        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'image_url' => $imageUrl,
            'mode' => $defaults['mode'] ?? 'person',
        ]);

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
        $bg = strtolower((string) ($settings['background'] ?? 'transparent'));
        $bg = in_array($bg, ['transparent', 'white', 'black'], true) ? $bg : 'transparent';
        $preserveAudio = array_key_exists('preserve_audio', $settings)
            ? (bool) $settings['preserve_audio']
            : (bool) ($defaults['preserve_audio'] ?? true);

        if (str_contains($endpointId, 'bria/')) {
            // Bria expects capitalized enum values.
            $input = array_merge($defaults, [
                'video_url' => $videoUrl,
                'background_color' => ucfirst($bg),
                'preserve_audio' => $preserveAudio,
            ]);
            // Transparent output needs an alpha-capable container/codec.
            if ($bg === 'transparent') {
                $input['output_container_and_codec'] = $defaults['output_container_and_codec'] ?? 'webm_vp9';
            }

            return $this->onlyKeys($input, [
                'video_url', 'background_color', 'preserve_audio', 'output_container_and_codec',
            ]);
        }

        // VEED-style fallback: only supports transparent (alpha) output.
        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'output_codec' => $defaults['output_codec'] ?? 'vp9',
        ]);

        return $this->onlyKeys($input, [
            'video_url', 'output_codec', 'refine_foreground_edges', 'subject_is_person',
        ]);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    private function buildSubtitleRemover(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        $prompt = trim((string) ($settings['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = (string) ($defaults['prompt'] ?? 'remove subtitles');
        }
        $maskPrompt = trim((string) ($settings['mask_prompt'] ?? ''));
        $cleanPrompt = trim((string) ($settings['clean_prompt'] ?? ''));

        if (str_contains($endpointId, 'void-video-inpainting')) {
            $input = array_merge($defaults, [
                'video_url' => $videoUrl,
                'prompt' => $prompt,
            ]);
            if ($maskPrompt !== '') {
                $input['mask_prompt'] = $maskPrompt;
            }
            if ($cleanPrompt !== '') {
                $input['clean_prompt'] = $cleanPrompt;
            }

            return $this->onlyKeys($input, ['video_url', 'prompt', 'mask_prompt', 'clean_prompt', 'quad_mask_video_url']);
        }

        return $this->onlyKeys(array_merge($defaults, [
            'video_url' => $videoUrl,
            'prompt' => $prompt,
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
        // Direction forward → append at end; backward → prepend at start.
        $direction = (string) ($settings['direction'] ?? '');
        $mode = match ($direction) {
            'backward' => 'start',
            'forward' => 'end',
            default => (string) ($settings['mode'] ?? $defaults['mode'] ?? 'end'),
        };
        $prompt = trim((string) ($settings['prompt'] ?? ''));

        if (str_contains($endpointId, 'pixverse')) {
            $input = array_merge($defaults, [
                'video_url' => $videoUrl,
                'duration' => (int) round($duration),
            ]);
            if ($prompt !== '') {
                $input['prompt'] = $prompt;
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
    private function buildVideoToVideo(string $endpointId, array $defaults, array $settings, string $videoUrl, ?string $imageUrl): array
    {
        $prompt = trim((string) ($settings['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException('An edit prompt is required.');
        }

        $strength = $this->clamp01($settings['strength'] ?? null);
        // Wan 2.2 v2v guidance_scale is a 1–10 float.
        $guidance = isset($settings['guidance_scale']) && is_numeric($settings['guidance_scale'])
            ? max(1.0, min(10.0, (float) $settings['guidance_scale']))
            : null;

        if (str_contains($endpointId, 'kling-video')) {
            $input = [
                'video_url' => $videoUrl,
                'prompt' => $prompt,
            ];
            if ($strength !== null) {
                $input['strength'] = $strength;
            }
            if ($guidance !== null) {
                $input['cfg_scale'] = $guidance;
            }

            return $this->onlyKeys($input, ['video_url', 'prompt', 'strength', 'cfg_scale']);
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'prompt' => $prompt,
        ]);
        if ($strength !== null) {
            $input['strength'] = $strength;
        }
        if ($guidance !== null) {
            $input['guidance_scale'] = $guidance;
        }

        return $this->onlyKeys($input, [
            'video_url', 'prompt', 'resolution', 'acceleration', 'aspect_ratio',
            'enable_safety_checker', 'strength', 'guidance_scale',
        ]);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildDenoise(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        // Single "Strength" slider (0 → 1) maps to the model's denoise amount.
        $strength = $this->clamp01($settings['strength'] ?? ($settings['noise'] ?? null));

        if (str_contains($endpointId, 'seedvr')) {
            $input = array_merge($defaults, ['video_url' => $videoUrl]);
            if ($strength !== null) {
                $input['noise_scale'] = $strength;
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
        if ($strength !== null) {
            $input['noise'] = $strength;
        }

        return $this->onlyKeys($input, [
            'video_url', 'model', 'upscale_factor', 'noise', 'compression', 'halo', 'grain', 'recover_detail',
        ]);
    }

    private function clamp01(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
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
