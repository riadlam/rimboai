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

        // Image → video tools don't take a video input; every other tool requires one.
        $imageOnlyTools = ['animate-a-picture', 'motion-control'];
        if (! in_array($toolSlug, $imageOnlyTools, true) && (! is_string($videoUrl) || $videoUrl === '')) {
            throw new \InvalidArgumentException('A video upload is required.');
        }

        $input = match ($toolSlug) {
            'video-upscaler' => $this->buildUpscaler($endpointId, $defaults, $settings, $videoUrl),
            'video-enhancer', 'anime-video-enhancer' => $this->buildEnhancer($endpointId, $defaults, $settings, $videoUrl),
            'lip-sync' => $this->buildLipSync($defaults, $settings, $videoUrl, $audioUrl),
            'face-swap-video' => $this->buildFaceSwap($endpointId, $defaults, $settings, $videoUrl, $imageUrl),
            'video-background-remover' => $this->buildBgRemover($endpointId, $defaults, $settings, $videoUrl),
            'remove-subtitles-from-video' => $this->buildSubtitleRemover($endpointId, $defaults, $settings, $videoUrl),
            'ai-video-extender' => $this->buildExtender($endpointId, $defaults, $settings, $videoUrl),
            'video-to-video' => $this->buildVideoToVideo($endpointId, $defaults, $settings, $videoUrl, $imageUrl),
            'denoise-video' => $this->buildDenoise($endpointId, $defaults, $settings, $videoUrl),
            'animate-a-picture' => $this->buildImageToVideo($endpointId, $defaults, $settings, $imageUrl),
            'motion-control' => $this->buildMotionControl($defaults, $settings, $imageUrl),
            'ai-dance-generator' => $this->buildDanceMove($defaults, $settings, $videoUrl, $imageUrl),
            'video-to-anime-ai' => $this->buildAnime($endpointId, $defaults, $settings, $videoUrl),
            'ai-video-filters' => $this->buildFilters($endpointId, $defaults, $settings, $videoUrl),
            'ai-video-editor' => $this->buildEditor($endpointId, $defaults, $settings, $videoUrl),
            'ai-sound-effect-generator' => $this->buildSoundEffect($defaults, $settings, $videoUrl),
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
            $input = $this->applyClientResolution($input, $settings, 'target_resolution');

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
    private function buildFaceSwap(string $endpointId, array $defaults, array $settings, string $videoUrl, ?string $imageUrl): array
    {
        if (! is_string($imageUrl) || $imageUrl === '') {
            throw new \InvalidArgumentException('A face image is required for face swap.');
        }

        // Kling O3 / O1 V2V edit: video + face as @Element1 (not PixVerse swap fields).
        if (str_contains($endpointId, 'kling-video')) {
            $prompt = trim((string) ($settings['prompt'] ?? ''));
            if ($prompt === '') {
                $prompt = (string) ($defaults['prompt'] ?? 'Replace the person in the video with @Element1, matching face identity, skin tone, and lighting while keeping the original motion, camera, and framing.');
            }
            // Lab chips use @ImageN; this endpoint has no image_urls — remap to @ElementN.
            $prompt = preg_replace('/@Image(\d+)\b/i', '@Element$1', $prompt) ?? $prompt;
            if (! preg_match('/@Element\d+\b/i', $prompt)) {
                $prompt = 'Replace the person in the video with @Element1. '.$prompt;
            }

            return [
                'video_url' => $videoUrl,
                'prompt' => $prompt,
                'keep_audio' => (bool) ($settings['keep_audio'] ?? $defaults['keep_audio'] ?? true),
                'elements' => [
                    [
                        'frontal_image_url' => $imageUrl,
                        'reference_image_urls' => [$imageUrl],
                    ],
                ],
            ];
        }

        // PixVerse Swap — dedicated person/object/background swap.
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

        // Premium primary: Wan 2.7 edit-video (instruction-based, keeps the shot).
        if (str_contains($endpointId, 'wan/v2.7/edit-video')) {
            return $this->buildWan27Edit($defaults, $settings, $videoUrl, $prompt);
        }

        $strength = $this->clamp01($settings['strength'] ?? null);
        // Wan 2.2 v2v guidance_scale is a 1–10 float.
        $guidance = isset($settings['guidance_scale']) && is_numeric($settings['guidance_scale'])
            ? max(1.0, min(10.0, (float) $settings['guidance_scale']))
            : null;

        if (str_contains($endpointId, 'kling-video')) {
            // Lab chips use @ImageN; Kling edit has no image_urls — remap to @ElementN.
            $prompt = preg_replace('/@Image(\d+)\b/i', '@Element$1', $prompt) ?? $prompt;
            $input = [
                'video_url' => $videoUrl,
                'prompt' => $prompt,
                'keep_audio' => (bool) ($settings['keep_audio'] ?? $defaults['keep_audio'] ?? true),
            ];
            if (is_string($imageUrl) && $imageUrl !== '') {
                if (! preg_match('/@Element\d+\b/i', $prompt)) {
                    $input['prompt'] = 'Replace the person / subject in the video with @Element1. '.$prompt;
                }
                $input['elements'] = [
                    [
                        'frontal_image_url' => $imageUrl,
                        'reference_image_urls' => [$imageUrl],
                    ],
                ];
            }
            if ($strength !== null) {
                $input['strength'] = $strength;
            }
            if ($guidance !== null) {
                $input['cfg_scale'] = $guidance;
            }

            return $this->onlyKeys($input, [
                'video_url', 'prompt', 'keep_audio', 'elements', 'image_urls', 'strength', 'cfg_scale',
            ]);
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
        if (str_contains($endpointId, 'wan/')) {
            $input['enable_prompt_expansion'] = (bool) ($settings['enable_prompt_expansion'] ?? $defaults['enable_prompt_expansion'] ?? false);
        }
        $input = $this->applyClientGeometry($input, $settings);

        return $this->onlyKeys($input, [
            'video_url', 'prompt', 'resolution', 'acceleration', 'aspect_ratio',
            'enable_safety_checker', 'enable_prompt_expansion', 'strength', 'guidance_scale',
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

    /**
     * Image → video (animate-a-picture): Kling 2.1 std or Wan 2.7 i2v.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildImageToVideo(string $endpointId, array $defaults, array $settings, ?string $imageUrl): array
    {
        if (! is_string($imageUrl) || $imageUrl === '') {
            throw new \InvalidArgumentException('An image is required to animate.');
        }

        $prompt = trim((string) ($settings['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = 'Animate this image with natural, smooth, cinematic motion.';
        }
        $duration = (string) ($settings['duration'] ?? $defaults['duration'] ?? '5');

        if (str_contains($endpointId, 'wan/')) {
            $input = array_merge($defaults, [
                'image_url' => $imageUrl,
                'prompt' => $prompt,
                'duration' => (int) $duration,
                // Fal defaults this to true (rewrites prompt). Keep the user prompt literal.
                'enable_prompt_expansion' => (bool) ($settings['enable_prompt_expansion'] ?? $defaults['enable_prompt_expansion'] ?? false),
            ]);
            $input = $this->applyClientResolution($input, $settings, 'resolution');
            $negative = trim((string) ($settings['negative_prompt'] ?? $defaults['negative_prompt'] ?? ''));
            if ($negative !== '') {
                $input['negative_prompt'] = mb_substr($negative, 0, 500);
            }

            return $this->onlyKeys($input, [
                'image_url', 'prompt', 'duration', 'resolution', 'negative_prompt', 'enable_prompt_expansion',
            ]);
        }

        // Kling 2.1 standard
        $input = array_merge($defaults, [
            'image_url' => $imageUrl,
            'prompt' => $prompt,
            'duration' => $duration,
        ]);

        return $this->onlyKeys($input, ['image_url', 'prompt', 'duration', 'cfg_scale', 'negative_prompt']);
    }

    /**
     * Motion Control: PixVerse i2v with camera movement.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildMotionControl(array $defaults, array $settings, ?string $imageUrl): array
    {
        if (! is_string($imageUrl) || $imageUrl === '') {
            throw new \InvalidArgumentException('An image is required for motion control.');
        }

        $input = array_merge($defaults, [
            'image_url' => $imageUrl,
            'duration' => (string) ($settings['duration'] ?? $defaults['duration'] ?? '5'),
        ]);
        if (! empty($settings['camera_movement'])) {
            $input['camera_movement'] = (string) $settings['camera_movement'];
        }
        $prompt = trim((string) ($settings['prompt'] ?? ''));
        if ($prompt !== '') {
            $input['prompt'] = $prompt;
        }
        $input = $this->applyClientResolution($input, $settings, 'resolution');

        return $this->onlyKeys($input, [
            'image_url', 'prompt', 'resolution', 'duration', 'camera_movement', 'style', 'seed',
        ]);
    }

    /**
     * AI Dance Generator: Wan Animate Move (character image + drive video).
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildDanceMove(array $defaults, array $settings, ?string $videoUrl, ?string $imageUrl): array
    {
        if (! is_string($imageUrl) || $imageUrl === '') {
            throw new \InvalidArgumentException('A character image is required.');
        }
        if (! is_string($videoUrl) || $videoUrl === '') {
            throw new \InvalidArgumentException('A reference dance video is required.');
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'image_url' => $imageUrl,
        ]);
        if (! empty($settings['resolution'])) {
            $input['resolution'] = (string) $settings['resolution'];
        }

        return $this->onlyKeys($input, ['video_url', 'image_url', 'resolution', 'use_turbo']);
    }

    /**
     * Video to Anime: style-transfer on the source clip.
     * Wan 2.7 edit-video already keeps the shot — keep the prompt short (style only).
     * Wan 2.2 v2v needs low strength so it does not invent a new character.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildAnime(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        $detail = trim((string) ($settings['prompt'] ?? ''));
        // Match Fal's edit-video style-transfer pattern: short style instruction only.
        $prompt = 'Transform the video into anime style. Clean line art, cel shading, vibrant colors.';
        if ($detail !== '') {
            $prompt .= ' '.$detail;
        }

        // Wan 2.7 edit-video — instruction style transfer on the existing footage.
        if (str_contains($endpointId, 'wan/v2.7/edit-video')) {
            return $this->buildWan27Edit($defaults, $settings, $videoUrl, $prompt);
        }

        // Wan 2.2 / Kling fallback — low strength so the source identity wins.
        // Docs: 1.0 = fully prompt-based (new character), 0.0 = identical to input.
        $strength = $this->clamp01($settings['strength'] ?? ($defaults['strength'] ?? 0.35)) ?? 0.35;
        $strength = min($strength, 0.5);

        if (str_contains($endpointId, 'kling-video')) {
            return $this->onlyKeys([
                'video_url' => $videoUrl,
                'prompt' => $prompt,
                'strength' => $strength,
            ], ['video_url', 'prompt', 'strength']);
        }

        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'prompt' => $prompt,
            'strength' => $strength,
            'aspect_ratio' => $defaults['aspect_ratio'] ?? 'auto',
            'enable_prompt_expansion' => false,
        ]);
        $input = $this->applyClientGeometry($input, $settings);

        return $this->onlyKeys($input, [
            'video_url', 'prompt', 'resolution', 'acceleration',
            'aspect_ratio', 'enable_safety_checker', 'enable_prompt_expansion', 'strength',
        ]);
    }

    /**
     * AI Video Filters: Wan 2.2 v2v driven by a filter preset prompt.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildFilters(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        $filter = (string) ($settings['filter'] ?? 'cinematic');
        $look = match ($filter) {
            'vintage' => 'vintage retro film look, soft film grain, faded warm tones, nostalgic mood',
            'noir' => 'black and white film noir, high contrast, deep moody shadows',
            'vibrant' => 'vibrant highly saturated colors, punchy vivid contrast',
            'warm' => 'warm golden-hour color grade, cozy amber tones',
            'cold' => 'cool blue cinematic color grade, crisp cold tones',
            default => 'cinematic color grading, filmic teal and orange, dramatic lighting',
        };

        // Premium primary: Wan 2.7 edit-video applies the look without altering the footage.
        if (str_contains($endpointId, 'wan/v2.7/edit-video')) {
            $prompt = 'Apply a '.$look.' color grade to the entire video. Keep the same scene, subjects, motion and framing — only change the color, lighting and mood.';

            return $this->buildWan27Edit($defaults, $settings, $videoUrl, $prompt);
        }

        return $this->buildWanRestyle($endpointId, $defaults, $settings, $videoUrl, $look, $this->clamp01($settings['strength'] ?? null));
    }

    /**
     * AI Video Editor: prompt-driven Wan 2.2 v2v restyle.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildEditor(string $endpointId, array $defaults, array $settings, string $videoUrl): array
    {
        $prompt = trim((string) ($settings['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException('An edit prompt is required.');
        }

        // Premium primary: Wan 2.7 edit-video (instruction-based, keeps the shot).
        if (str_contains($endpointId, 'wan/v2.7/edit-video')) {
            return $this->buildWan27Edit($defaults, $settings, $videoUrl, $prompt);
        }

        return $this->buildWanRestyle($endpointId, $defaults, $settings, $videoUrl, $prompt, $this->clamp01($settings['strength'] ?? null));
    }

    /**
     * Shared Wan 2.7 edit-video payload — premium instruction-based restyle that
     * preserves the source shot. Driven purely by prompt + geometry (no strength).
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildWan27Edit(array $defaults, array $settings, string $videoUrl, string $prompt): array
    {
        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'prompt' => $prompt,
            'audio_setting' => $defaults['audio_setting'] ?? 'origin',
        ]);
        $input = $this->applyClientGeometry($input, $settings);

        return $this->onlyKeys($input, [
            'video_url', 'prompt', 'resolution', 'audio_setting',
            'enable_safety_checker', 'reference_image_url', 'aspect_ratio', 'duration',
        ]);
    }

    /**
     * Shared Wan 2.2 v2v / Kling O3 restyle payload.
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildWanRestyle(
        string $endpointId,
        array $defaults,
        array $settings,
        string $videoUrl,
        string $prompt,
        ?float $strength,
    ): array {
        if (str_contains($endpointId, 'kling-video')) {
            $input = ['video_url' => $videoUrl, 'prompt' => $prompt];
            if ($strength !== null) {
                $input['strength'] = $strength;
            }

            return $this->onlyKeys($input, ['video_url', 'prompt', 'strength']);
        }

        $input = array_merge($defaults, ['video_url' => $videoUrl, 'prompt' => $prompt]);
        if ($strength !== null) {
            $input['strength'] = $strength;
        }
        if (str_contains($endpointId, 'wan/')) {
            $input['enable_prompt_expansion'] = (bool) ($settings['enable_prompt_expansion'] ?? $defaults['enable_prompt_expansion'] ?? false);
        }
        $input = $this->applyClientGeometry($input, $settings);

        return $this->onlyKeys($input, [
            'video_url', 'prompt', 'resolution', 'acceleration', 'aspect_ratio', 'enable_safety_checker', 'enable_prompt_expansion', 'strength',
        ]);
    }

    /**
     * AI Sound Effect Generator: MMAudio v2 (video + prompt → video with audio).
     *
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildSoundEffect(array $defaults, array $settings, string $videoUrl): array
    {
        $prompt = trim((string) ($settings['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException('Describe the sound you want to generate.');
        }

        $duration = (float) ($settings['duration'] ?? $defaults['duration'] ?? 8);
        $input = array_merge($defaults, [
            'video_url' => $videoUrl,
            'prompt' => $prompt,
            'duration' => max(1.0, min(30.0, $duration)),
        ]);

        return $this->onlyKeys($input, [
            'video_url', 'prompt', 'duration', 'num_steps', 'cfg_strength', 'negative_prompt', 'seed',
        ]);
    }

    /**
     * Override defaults with client-selected resolution / aspect when provided.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function applyClientGeometry(array $input, array $settings): array
    {
        $input = $this->applyClientResolution($input, $settings, 'resolution');
        // "auto" is a UI convenience meaning "match the source aspect" — Wan has no
        // such enum, so we simply omit the field to let the model keep the input ratio.
        if (
            ! empty($settings['aspect_ratio'])
            && is_string($settings['aspect_ratio'])
            && strtolower($settings['aspect_ratio']) !== 'auto'
        ) {
            $input['aspect_ratio'] = $settings['aspect_ratio'];
        } else {
            unset($input['aspect_ratio']);
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function applyClientResolution(array $input, array $settings, string $key): array
    {
        if (! empty($settings['resolution']) && is_string($settings['resolution'])) {
            $input[$key] = strtolower($settings['resolution']);
        }

        return $input;
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
