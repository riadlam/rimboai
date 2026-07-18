<?php

namespace App\Services;

/**
 * Maps Lab UI options (aspect / resolution / quantity) onto the correct
 * fal.ai input fields for each text-to-image endpoint family.
 *
 * Docs: https://fal.ai/docs/documentation/model-apis/model-arguments
 */
class FalImageInputBuilder
{
    private const ASPECTS = ['1:1', '16:9', '9:16', '4:5', '3:4'];

    private const RESOLUTIONS = ['1K', '2K', '4K'];

    /**
     * Per-endpoint capability profiles derived from fal OpenAPI schemas.
     * Anything not listed falls back to the safest common payload.
     *
     * @var array<string, array{mode: string, num_images?: bool, resolution_case?: string, quality?: bool}>
     */
    private const PROFILES = [
        // aspect_ratio + resolution + num_images
        'fal-ai/nano-banana-2' => ['mode' => 'aspect_resolution', 'num_images' => true],
        'fal-ai/nano-banana-pro' => ['mode' => 'aspect_resolution', 'num_images' => true],
        'fal-ai/gemini-3.1-flash-image-preview' => ['mode' => 'aspect_resolution', 'num_images' => true],
        'fal-ai/gemini-3-pro-image-preview' => ['mode' => 'aspect_resolution', 'num_images' => true],
        'fal-ai/kling-image/v3/text-to-image' => ['mode' => 'aspect_resolution', 'num_images' => true],

        // aspect_ratio + num_images (no resolution field)
        'fal-ai/nano-banana' => ['mode' => 'aspect_only', 'num_images' => true],
        'fal-ai/gemini-25-flash-image' => ['mode' => 'aspect_only', 'num_images' => true],
        'fal-ai/flux-pro/v1.1-ultra' => ['mode' => 'aspect_only', 'num_images' => true],

        // aspect_ratio + lowercase resolution (1k/2k)
        'xai/grok-imagine-image' => ['mode' => 'aspect_resolution', 'num_images' => true, 'resolution_case' => 'lower'],

        // image_size presets / {width,height} + num_images
        'fal-ai/flux-2/turbo' => ['mode' => 'image_size', 'num_images' => true, 'max_edge' => 2048],
        'fal-ai/flux-2-pro' => ['mode' => 'image_size', 'num_images' => false, 'max_edge' => 2560],
        'fal-ai/wan/v2.7/text-to-image' => ['mode' => 'image_size', 'num_images' => true, 'max_edge' => 2048],
        'fal-ai/qwen-image-2/text-to-image' => ['mode' => 'image_size', 'num_images' => true, 'max_edge' => 2048],
        'openai/gpt-image-2' => ['mode' => 'image_size', 'num_images' => true, 'max_edge' => 3840, 'quality' => true],

        // seedream: presets include auto_2K / auto_4K
        'fal-ai/bytedance/seedream/v5/lite/text-to-image' => ['mode' => 'seedream', 'num_images' => true, 'max_edge' => 4096],
        'fal-ai/bytedance/seedream/v4.5/text-to-image' => ['mode' => 'seedream', 'num_images' => true, 'max_edge' => 4096],
        'fal-ai/bytedance/seedream/v4/text-to-image' => ['mode' => 'seedream', 'num_images' => true, 'max_edge' => 4096],

        // OpenAI gpt-image-1.5: fixed sizes + quality
        'fal-ai/gpt-image-1.5' => ['mode' => 'gpt_image_legacy', 'num_images' => true, 'quality' => true],
    ];

    /**
     * Models that accept reference images and their fal parameter + edit route.
     *
     * @var array<string, array{edit?: string, param: 'image_urls'|'image_url'}>
     */
    private const REFERENCE_CAPABLE = [
        'fal-ai/nano-banana-2' => ['edit' => 'fal-ai/nano-banana-2/edit', 'param' => 'image_urls'],
        'fal-ai/nano-banana-pro' => ['edit' => 'fal-ai/nano-banana-pro/edit', 'param' => 'image_urls'],
        'fal-ai/nano-banana' => ['edit' => 'fal-ai/nano-banana/edit', 'param' => 'image_urls'],
        'fal-ai/gemini-3.1-flash-image-preview' => ['edit' => 'fal-ai/gemini-3.1-flash-image-preview/edit', 'param' => 'image_urls'],
        'fal-ai/gemini-3-pro-image-preview' => ['edit' => 'fal-ai/gemini-3-pro-image-preview/edit', 'param' => 'image_urls'],
        'fal-ai/gemini-25-flash-image' => ['edit' => 'fal-ai/gemini-25-flash-image/edit', 'param' => 'image_urls'],
        'fal-ai/flux-pro/v1.1-ultra' => ['param' => 'image_url'],
    ];

    /**
     * @param  array{prompt: string, aspect?: string|null, resolution?: string|null, quantity?: int|null, reference_urls?: array<int, string>|null}  $options
     * @return array<string, mixed>
     */
    public function build(string $endpointId, array $options): array
    {
        $prompt = trim((string) ($options['prompt'] ?? ''));
        $aspect = $this->normalizeAspect($options['aspect'] ?? null);
        $resolution = $this->normalizeResolution($options['resolution'] ?? null);
        $quantity = $this->normalizeQuantity($options['quantity'] ?? null);

        $profile = self::PROFILES[$endpointId] ?? $this->inferProfile($endpointId);
        $input = ['prompt' => $prompt];

        switch ($profile['mode']) {
            case 'aspect_resolution':
                $input['aspect_ratio'] = $aspect;
                $res = $resolution;
                if (($profile['resolution_case'] ?? null) === 'lower') {
                    $res = strtolower($resolution);
                    // grok only supports 1k / 2k
                    if ($res === '4k') {
                        $res = '2k';
                    }
                } elseif ($endpointId === 'fal-ai/kling-image/v3/text-to-image' && $resolution === '4K') {
                    $res = '2K';
                }
                $input['resolution'] = $res;
                break;

            case 'aspect_only':
                $input['aspect_ratio'] = $aspect;
                break;

            case 'image_size':
                $input['image_size'] = $this->toImageSize($aspect, $resolution, (int) ($profile['max_edge'] ?? 2048));
                if (! empty($profile['quality'])) {
                    $input['quality'] = $this->resolutionToQuality($resolution);
                }
                break;

            case 'seedream':
                $input['image_size'] = $this->toSeedreamSize($aspect, $resolution);
                break;

            case 'gpt_image_legacy':
                $input['image_size'] = $this->toGptImageLegacySize($aspect);
                $input['quality'] = $this->resolutionToQuality($resolution);
                break;
        }

        if (($profile['num_images'] ?? true) !== false) {
            $input['num_images'] = $quantity;
        }

        $referenceUrls = array_values(array_filter(
            $options['reference_urls'] ?? [],
            fn ($url) => is_string($url) && $url !== '',
        ));

        if ($referenceUrls !== []) {
            $capability = self::REFERENCE_CAPABLE[$endpointId] ?? null;
            if ($capability !== null) {
                $param = $capability['param'];
                if ($param === 'image_urls') {
                    $input['image_urls'] = array_slice($referenceUrls, 0, 14);
                } else {
                    $input['image_url'] = $referenceUrls[0];
                }
            }
        }

        return $input;
    }

    /**
     * @param  array<int, string>  $referenceUrls
     */
    public function resolveEndpoint(string $endpointId, array $referenceUrls): string
    {
        if ($referenceUrls === []) {
            return $endpointId;
        }

        $capability = self::REFERENCE_CAPABLE[$endpointId] ?? null;

        return $capability['edit'] ?? $endpointId;
    }

    /**
     * @param  array<int, string>  $referenceUrls
     */
    public function supportsReferences(string $endpointId, array $referenceUrls): bool
    {
        if ($referenceUrls === []) {
            return true;
        }

        return isset(self::REFERENCE_CAPABLE[$endpointId]);
    }

    /**
     * Whether fal exposes an edit / image-to-image route for this text-to-image model.
     */
    public function supportsVariations(string $endpointId): bool
    {
        return isset(self::REFERENCE_CAPABLE[$endpointId]);
    }

    /**
     * Estimated output megapixels for cost calculation (requested size, not result metadata).
     */
    public function estimateOutputMegapixels(string $endpointId, ?string $aspect = null, ?string $resolution = null): float
    {
        $aspect = $this->normalizeAspect($aspect);
        $resolution = $this->normalizeResolution($resolution);
        $profile = self::PROFILES[$endpointId] ?? $this->inferProfile($endpointId);
        $maxEdge = (int) ($profile['max_edge'] ?? 2048);

        $edge = match ($resolution) {
            '4K' => 3840,
            '2K' => 2048,
            default => 1024,
        };
        $edge = min($edge, $maxEdge);

        if (($profile['mode'] ?? '') === 'seedream') {
            $edge = match ($resolution) {
                '4K' => 4096,
                '2K' => 2048,
                default => 1024,
            };
        }

        if (($profile['mode'] ?? '') === 'gpt_image_legacy') {
            $dims = match ($aspect) {
                '16:9' => ['width' => 1536, 'height' => 1024],
                '9:16', '3:4', '4:5' => ['width' => 1024, 'height' => 1536],
                default => ['width' => 1024, 'height' => 1024],
            };
        } else {
            $dims = $this->dimensionsForAspect($aspect, $edge);
        }

        $mp = ($dims['width'] * $dims['height']) / 1_000_000;

        // fal megapixel billing typically rounds up, with a 1MP floor.
        return max(1.0, (float) ceil($mp));
    }

    /**
     * @return array{mode: string, num_images?: bool, max_edge?: int, quality?: bool, resolution_case?: string}
     */
    private function inferProfile(string $endpointId): array
    {
        $id = strtolower($endpointId);

        if (str_contains($id, 'seedream') || str_contains($id, 'bytedance')) {
            return ['mode' => 'seedream', 'num_images' => true, 'max_edge' => 4096];
        }

        if (str_contains($id, 'flux-2') || str_contains($id, 'wan/') || str_contains($id, 'qwen')) {
            return ['mode' => 'image_size', 'num_images' => true, 'max_edge' => 2048];
        }

        if (str_contains($id, 'gpt-image-1')) {
            return ['mode' => 'gpt_image_legacy', 'num_images' => true, 'quality' => true];
        }

        // Default for Gemini / Nano Banana style models
        return ['mode' => 'aspect_resolution', 'num_images' => true];
    }

    private function normalizeAspect(?string $aspect): string
    {
        $aspect = $aspect ? trim($aspect) : '1:1';
        // Legacy drafts / reused settings may still send 4:3.
        if ($aspect === '4:3') {
            $aspect = '4:5';
        }

        return in_array($aspect, self::ASPECTS, true) ? $aspect : '1:1';
    }

    private function normalizeResolution(?string $resolution): string
    {
        $resolution = $resolution ? strtoupper(trim($resolution)) : '1K';

        return in_array($resolution, self::RESOLUTIONS, true) ? $resolution : '1K';
    }

    private function normalizeQuantity(mixed $quantity): int
    {
        $n = (int) ($quantity ?? 1);

        return max(1, min(4, $n));
    }

    /**
     * Prefer fal presets at 1K; use explicit {width,height} for 2K/4K.
     *
     * @return string|array{width: int, height: int}
     */
    private function toImageSize(string $aspect, string $resolution, int $maxEdge): string|array
    {
        if ($resolution === '1K') {
            return match ($aspect) {
                '16:9' => 'landscape_16_9',
                '9:16' => 'portrait_16_9',
                '3:4' => 'portrait_4_3',
                // No standard Fal 4:5 preset — send explicit pixels.
                '4:5' => $this->dimensionsForAspect('4:5', min(1024, $maxEdge)),
                default => 'square_hd',
            };
        }

        $edge = match ($resolution) {
            '4K' => 3840,
            '2K' => 2048,
            default => 1024,
        };
        $edge = min($edge, $maxEdge);

        return $this->dimensionsForAspect($aspect, $edge);
    }

    /**
     * @return string|array{width: int, height: int}
     */
    private function toSeedreamSize(string $aspect, string $resolution): string|array
    {
        // Seedream accepts auto_2K / auto_4K (model picks framing) or presets / custom size.
        // When the user picked a specific aspect, prefer explicit dimensions.
        if ($aspect === '1:1' && $resolution === '2K') {
            return 'auto_2K';
        }
        if ($aspect === '1:1' && $resolution === '4K') {
            return 'auto_4K';
        }

        if ($resolution === '1K') {
            // Seedream prefers large sizes; map 1K UI to presets (≈1K–2K).
            return match ($aspect) {
                '16:9' => 'landscape_16_9',
                '9:16' => 'portrait_16_9',
                '3:4' => 'portrait_4_3',
                '4:5' => $this->dimensionsForAspect('4:5', 1024),
                default => 'square_hd',
            };
        }

        $edge = $resolution === '4K' ? 4096 : 2048;

        // Seedream requires width/height between 1920 and 4096 (or large total pixels).
        $dims = $this->dimensionsForAspect($aspect, $edge);
        $dims['width'] = max(1920, min(4096, $dims['width']));
        $dims['height'] = max(1920, min(4096, $dims['height']));

        return $dims;
    }

    private function toGptImageLegacySize(string $aspect): string
    {
        return match ($aspect) {
            '16:9' => '1536x1024',
            '9:16', '3:4', '4:5' => '1024x1536',
            default => '1024x1024',
        };
    }

    private function resolutionToQuality(string $resolution): string
    {
        return match ($resolution) {
            '4K', '2K' => 'high',
            default => 'medium',
        };
    }

    /**
     * @return array{width: int, height: int}
     */
    private function dimensionsForAspect(string $aspect, int $longestEdge): array
    {
        [$wRatio, $hRatio] = array_map('intval', explode(':', $aspect));
        $wRatio = max(1, $wRatio);
        $hRatio = max(1, $hRatio);

        if ($wRatio >= $hRatio) {
            $width = $longestEdge;
            $height = (int) round($longestEdge * $hRatio / $wRatio);
        } else {
            $height = $longestEdge;
            $width = (int) round($longestEdge * $wRatio / $hRatio);
        }

        // Many fal models require multiples of 16.
        $width = max(16, (int) (round($width / 16) * 16));
        $height = max(16, (int) (round($height / 16) * 16));

        return ['width' => $width, 'height' => $height];
    }
}
