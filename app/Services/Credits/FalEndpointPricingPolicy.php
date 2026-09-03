<?php

namespace App\Services\Credits;

/**
 * Explicit per-endpoint billing dimensions. Fal's pricing API scalar is the
 * base input, not a complete quote (resolution / audio / references / add-ons).
 */
class FalEndpointPricingPolicy
{
    /**
     * @param  array{
     *   endpoint_id: string,
     *   unit?: string|null,
     *   unit_price?: float,
     *   duration_seconds?: int|float,
     *   audio?: bool,
     *   voice_control?: bool,
     *   resolution?: string,
     *   aspect?: string,
     *   reference_video_seconds?: float|int|null,
     *   reference_image_count?: int,
     * }  $options
     * @return array{fal_cost_usd: float, unit: string, unit_price: float, billable_units: float, breakdown: array<string, mixed>}|null
     */
    public function quoteVideo(array $options): ?array
    {
        $id = strtolower(trim((string) ($options['endpoint_id'] ?? '')));
        $unit = $this->normalizeVideoUnit($options['unit'] ?? null);
        $catalog = max(0.0, (float) ($options['unit_price'] ?? 0));
        $duration = max(1, (int) ($options['duration_seconds'] ?? 5));
        $audio = (bool) ($options['audio'] ?? false);
        $voice = (bool) ($options['voice_control'] ?? false);
        $resolution = strtolower((string) ($options['resolution'] ?? '720p'));
        $aspect = (string) ($options['aspect'] ?? '16:9');

        if ($id === '') {
            return null;
        }

        if (str_contains($id, 'grok-imagine-video')) {
            return $this->quoteGrok($id, $duration, $resolution, (int) ($options['reference_image_count'] ?? 0));
        }

        if (str_contains($id, 'pixverse/c1/reference-to-video')) {
            return $this->quotePixverseC1($duration, $resolution, $audio);
        }

        if (str_contains($id, 'veo3') || preg_match('/\\/veo3(?:\\.|\\/|$)/', $id) === 1) {
            return $this->quoteVeo($duration, $resolution, $audio);
        }

        if (str_contains($id, 'kling-video') && (str_contains($id, '/v3/') || str_contains($id, '/o3/'))) {
            return $this->quoteKlingV3($id, $duration, $audio, $voice);
        }

        if ($unit === 'tokens_per_1000' || str_contains($id, 'seedance')) {
            return $this->quoteSeedance($id, $catalog, $duration, $resolution, $aspect, (float) ($options['reference_video_seconds'] ?? 0));
        }

        if ($unit === 'units' || $unit === 'unit') {
            return null;
        }

        $audioMul = $this->genericAudioMultiplier($id, $audio);
        $resMul = $this->genericResolutionMultiplier($id, $resolution);
        $price = $catalog > 0 ? $catalog : 0.0;
        if ($price <= 0) {
            return null;
        }

        $fal = round($duration * $price * $audioMul * $resMul, 6);

        return [
            'fal_cost_usd' => $fal,
            'unit' => $unit ?: 'seconds',
            'unit_price' => $price,
            'billable_units' => (float) $duration,
            'breakdown' => [
                'mode' => 'per_second',
                'duration_seconds' => $duration,
                'audio' => $audio,
                'audio_multiplier' => $audioMul,
                'resolution' => $resolution,
                'resolution_multiplier' => $resMul,
                'policy' => 'catalog_seconds',
            ],
        ];
    }

    /**
     * @param  array{
     *   endpoint_id: string,
     *   unit?: string|null,
     *   unit_price?: float,
     *   duration_seconds?: float,
     *   pass2?: bool,
     *   sam_mask?: bool,
     * }  $options
     * @return array{fal_cost_usd: float, unit: string, unit_price: float, billable_units: float, breakdown: array<string, mixed>}|null
     */
    public function quoteVoid(array $options): ?array
    {
        $id = strtolower((string) ($options['endpoint_id'] ?? ''));
        if (! str_contains($id, 'void-video-inpainting') && ! str_contains($id, 'void')) {
            return null;
        }

        $base = 0.05;
        $pass2 = (bool) ($options['pass2'] ?? false);
        $sam = (bool) ($options['sam_mask'] ?? false);
        $fal = $base + ($pass2 ? 0.05 : 0.0) + ($sam ? 0.05 : 0.0);

        return [
            'fal_cost_usd' => round($fal, 6),
            'unit' => 'video',
            'unit_price' => $base,
            'billable_units' => 1.0,
            'breakdown' => [
                'formula' => '0.05 + pass2(0.05) + sam(0.05)',
                'pass2' => $pass2,
                'sam_mask' => $sam,
                'policy' => 'void_flat',
            ],
        ];
    }

    /**
     * @return array{fal_cost_usd: float, unit: string, unit_price: float, billable_units: float, breakdown: array<string, mixed>}
     */
    private function quoteGrok(string $id, int $duration, string $resolution, int $images): array
    {
        $perSecond = match ($resolution) {
            '480p' => 0.05,
            '1080p' => 0.14,
            default => 0.07,
        };
        $imageFee = str_contains($id, 'image-to-video') ? max(0, $images) * 0.002 : 0.0;
        $fal = round(($duration * $perSecond) + $imageFee, 6);

        return [
            'fal_cost_usd' => $fal,
            'unit' => 'seconds',
            'unit_price' => $perSecond,
            'billable_units' => (float) $duration,
            'breakdown' => [
                'mode' => 'grok_resolution',
                'duration_seconds' => $duration,
                'resolution' => $resolution,
                'image_fee_usd' => $imageFee,
                'policy' => 'grok_imagine',
            ],
        ];
    }

    /**
     * @return array{fal_cost_usd: float, unit: string, unit_price: float, billable_units: float, breakdown: array<string, mixed>}
     */
    private function quotePixverseC1(int $duration, string $resolution, bool $audio): array
    {
        $silent = match ($resolution) {
            '360p' => 0.030,
            '540p' => 0.040,
            '1080p' => 0.095,
            default => 0.050,
        };
        $withAudio = match ($resolution) {
            '360p' => 0.040,
            '540p' => 0.050,
            '1080p' => 0.120,
            default => 0.065,
        };
        $price = $audio ? $withAudio : $silent;
        $fal = round($duration * $price, 6);

        return [
            'fal_cost_usd' => $fal,
            'unit' => 'seconds',
            'unit_price' => $price,
            'billable_units' => (float) $duration,
            'breakdown' => [
                'mode' => 'pixverse_c1',
                'duration_seconds' => $duration,
                'resolution' => $resolution,
                'audio' => $audio,
                'policy' => 'pixverse_c1',
            ],
        ];
    }

    /**
     * @return array{fal_cost_usd: float, unit: string, unit_price: float, billable_units: float, breakdown: array<string, mixed>}
     */
    private function quoteVeo(int $duration, string $resolution, bool $audio): array
    {
        $price = match (true) {
            $resolution === '4k' && $audio => 0.60,
            $resolution === '4k' => 0.40,
            $audio => 0.40,
            default => 0.20,
        };
        $fal = round($duration * $price, 6);

        return [
            'fal_cost_usd' => $fal,
            'unit' => 'seconds',
            'unit_price' => $price,
            'billable_units' => (float) $duration,
            'breakdown' => [
                'mode' => 'veo_resolution_audio',
                'duration_seconds' => $duration,
                'resolution' => $resolution,
                'audio' => $audio,
                'policy' => 'veo',
            ],
        ];
    }

    /**
     * @return array{fal_cost_usd: float, unit: string, unit_price: float, billable_units: float, breakdown: array<string, mixed>}
     */
    private function quoteKlingV3(string $id, int $duration, bool $audio, bool $voice): array
    {
        $pro = str_contains($id, '/pro/') || str_contains($id, '/o3/pro/') || str_contains($id, '/o3/4k/');
        if ($voice) {
            $price = $pro ? 0.196 : 0.154;
        } elseif ($audio) {
            $price = $pro ? 0.168 : 0.126;
        } else {
            $price = $pro ? 0.112 : 0.084;
        }
        $fal = round($duration * $price, 6);

        return [
            'fal_cost_usd' => $fal,
            'unit' => 'seconds',
            'unit_price' => $price,
            'billable_units' => (float) $duration,
            'breakdown' => [
                'mode' => 'kling_v3',
                'duration_seconds' => $duration,
                'audio' => $audio,
                'voice_control' => $voice,
                'tier' => $pro ? 'pro' : 'standard',
                'policy' => 'kling_v3',
            ],
        ];
    }

    /**
     * @return array{fal_cost_usd: float, unit: string, unit_price: float, billable_units: float, breakdown: array<string, mixed>}
     */
    private function quoteSeedance(
        string $id,
        float $catalog,
        int $duration,
        string $resolution,
        string $aspect,
        float $referenceVideoSeconds,
    ): array {
        [$width, $height] = $this->dimensionsFor($resolution, $aspect);
        $billableSeconds = $duration + max(0.0, $referenceVideoSeconds);
        $tokens = ($height * $width * $billableSeconds * 24) / 1024;

        $pricePerThousand = $catalog > 0 ? $catalog : 0.014;
        if (str_contains($id, 'seedance-2.5') || str_contains($id, 'seedance/2.5')) {
            $pricePerThousand = $resolution === '1080p' ? 0.0234 : 0.0214;
        } elseif (str_contains($id, 'seedance') && $resolution === '4k') {
            $pricePerThousand = 0.008;
        }

        $fal = ($tokens / 1000) * $pricePerThousand;
        if ($referenceVideoSeconds > 0) {
            $fal *= 0.6;
        }
        $fal = round($fal, 6);

        return [
            'fal_cost_usd' => $fal,
            'unit' => 'tokens_per_1000',
            'unit_price' => $pricePerThousand,
            'billable_units' => round($tokens, 4),
            'breakdown' => [
                'mode' => 'tokens_per_1000',
                'duration_seconds' => $duration,
                'reference_video_seconds' => $referenceVideoSeconds,
                'resolution' => $resolution,
                'aspect' => $aspect,
                'width' => $width,
                'height' => $height,
                'tokens' => round($tokens, 4),
                'price_per_1000_tokens' => $pricePerThousand,
                'formula' => '(H * W * (output + input_video) * 24) / 1024 / 1000 * unit_price',
                'policy' => 'seedance',
            ],
        ];
    }

    private function genericAudioMultiplier(string $id, bool $audio): float
    {
        if (! $audio) {
            return 1.0;
        }
        if (str_contains($id, 'kling-video/o3/4k/reference-to-video')) {
            return 1.0;
        }
        if (str_contains($id, 'kling-video/o3/pro/reference-to-video')) {
            return 1.25;
        }
        if (str_contains($id, 'kling-video/o3/standard/reference-to-video')) {
            return 4 / 3;
        }
        if (str_contains($id, 'pixverse/c1/reference-to-video')) {
            return 1.3;
        }
        if (str_contains($id, 'kling') && (str_contains($id, 'v3') || str_contains($id, '/o3/') || str_contains($id, 'v2.6'))) {
            return 1.5;
        }

        return 1.0;
    }

    private function genericResolutionMultiplier(string $id, string $resolution): float
    {
        if (str_contains($id, 'pixverse/c1/reference-to-video')) {
            return $resolution === '1080p' ? 1.9 : 1.0;
        }
        if (! str_contains($id, 'veo')) {
            return 1.0;
        }

        return match ($resolution) {
            '4k' => 2.0,
            '1080p' => 1.5,
            default => 1.0,
        };
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function dimensionsFor(string $resolution, string $aspect): array
    {
        $base = match ($resolution) {
            '480p' => 480,
            '1080p' => 1080,
            '4k' => 2160,
            default => 720,
        };

        $parts = array_map('intval', explode(':', $aspect));
        $aw = max(1, $parts[0] ?? 16);
        $ah = max(1, $parts[1] ?? 9);

        if ($aw >= $ah) {
            return [(int) round($base * $aw / $ah), $base];
        }

        return [$base, (int) round($base * $ah / $aw)];
    }

    private function normalizeVideoUnit(?string $unit): string
    {
        $unit = strtolower(trim((string) $unit));

        return match ($unit) {
            'second', 'seconds' => 'seconds',
            'unit', 'units' => 'units',
            'tokens_per_1000', 'tokens', 'token' => 'tokens_per_1000',
            default => $unit !== '' ? $unit : 'seconds',
        };
    }
}
