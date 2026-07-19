<?php

namespace App\Services\Credits;

/**
 * Estimates fal USD cost for text-to-video from catalog unit/unit_price.
 *
 * Supported catalog units:
 * - seconds: duration_seconds × unit_price × multipliers
 * - tokens_per_1000: Seedance-style token billing
 *
 * User-facing credits: minimum 55 when charge > 0.
 */
class VideoGenerationCostEstimator
{
    private const MIN_CREDITS = 55;

    public function __construct(private readonly CreditCalculator $credits) {}

    /**
     * @param  array{
     *   endpoint_id: string,
     *   unit?: string|null,
     *   unit_price?: float|string|null,
     *   duration_seconds?: int|null,
     *   audio?: bool|null,
     *   resolution?: string|null,
     *   aspect?: string|null,
     * }  $options
     * @return array{
     *   fal_cost_usd: float,
     *   credits: int,
     *   billable_units: float,
     *   unit: string,
     *   unit_price: float,
     *   breakdown: array<string, mixed>
     * }
     */
    public function estimate(array $options): array
    {
        $endpointId = (string) ($options['endpoint_id'] ?? '');
        $unit = $this->normalizeUnit($options['unit'] ?? null);
        $unitPrice = max(0.0, (float) ($options['unit_price'] ?? 0));
        $durationSeconds = max(1, (int) ($options['duration_seconds'] ?? 5));
        $audio = (bool) ($options['audio'] ?? false);
        $resolution = strtolower((string) ($options['resolution'] ?? '720p'));
        $aspect = (string) ($options['aspect'] ?? '16:9');

        if ($unit === 'tokens_per_1000' || ($unit === 'units' && str_contains(strtolower($endpointId), 'seedance'))) {
            return $this->estimateTokenPriced($endpointId, $unitPrice, $durationSeconds, $resolution, $aspect);
        }

        $billable = (float) $durationSeconds;
        $audioMultiplier = $this->audioMultiplier($endpointId, $audio);
        $resolutionMultiplier = $this->resolutionMultiplier($endpointId, $resolution);
        $falCost = round($billable * $unitPrice * $audioMultiplier * $resolutionMultiplier, 6);
        $credits = $falCost > 0 ? max(1, $this->credits->fromFalUsd($falCost)) : 0;
        $breakdown = [
            'mode' => 'per_second',
            'duration_seconds' => $durationSeconds,
            'audio' => $audio,
            'audio_multiplier' => $audioMultiplier,
            'resolution' => $resolution,
            'resolution_multiplier' => $resolutionMultiplier,
        ];

        if ($credits > 0 && $credits < self::MIN_CREDITS) {
            $breakdown['credits_before_floor'] = $credits;
            $credits = self::MIN_CREDITS;
            $breakdown['min_credits'] = self::MIN_CREDITS;
        }

        return [
            'fal_cost_usd' => $falCost,
            'credits' => $credits,
            'billable_units' => $billable,
            'unit' => $unit ?: 'seconds',
            'unit_price' => $unitPrice,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @return array{
     *   fal_cost_usd: float,
     *   credits: int,
     *   billable_units: float,
     *   unit: string,
     *   unit_price: float,
     *   breakdown: array<string, mixed>
     * }
     */
    private function estimateTokenPriced(
        string $endpointId,
        float $unitPrice,
        int $durationSeconds,
        string $resolution,
        string $aspect,
    ): array {
        [$width, $height] = $this->dimensionsFor($resolution, $aspect);
        $tokens = ($height * $width * $durationSeconds * 24) / 1024;

        // Seedance gallery: 4k is $0.008 / 1000 tokens; other res use catalog unit_price.
        $pricePerThousand = $unitPrice > 0 ? $unitPrice : 0.014;
        if (str_contains(strtolower($endpointId), 'seedance') && $resolution === '4k') {
            $pricePerThousand = 0.008;
        }

        $falCost = round(($tokens / 1000) * $pricePerThousand, 6);
        $credits = $falCost > 0 ? max(1, $this->credits->fromFalUsd($falCost)) : 0;
        $breakdown = [
            'mode' => 'tokens_per_1000',
            'duration_seconds' => $durationSeconds,
            'resolution' => $resolution,
            'aspect' => $aspect,
            'width' => $width,
            'height' => $height,
            'tokens' => round($tokens, 4),
            'price_per_1000_tokens' => $pricePerThousand,
            'formula' => '(H * W * duration * 24) / 1024 / 1000 * unit_price',
        ];

        if ($credits > 0 && $credits < self::MIN_CREDITS) {
            $breakdown['credits_before_floor'] = $credits;
            $credits = self::MIN_CREDITS;
            $breakdown['min_credits'] = self::MIN_CREDITS;
        }

        return [
            'fal_cost_usd' => $falCost,
            'credits' => $credits,
            'billable_units' => round($tokens, 4),
            'unit' => 'tokens_per_1000',
            'unit_price' => $pricePerThousand,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * @return array{0: int, 1: int} [width, height]
     */
    private function dimensionsFor(string $resolution, string $aspect): array
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
            $height = $base;
            $width = (int) round($base * $aw / $ah);
        } else {
            $width = $base;
            $height = (int) round($base * $ah / $aw);
        }

        return [$width, $height];
    }

    private function audioMultiplier(string $endpointId, bool $audio): float
    {
        if (! $audio) {
            return 1.0;
        }

        $id = strtolower($endpointId);
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

    private function resolutionMultiplier(string $endpointId, string $resolution): float
    {
        $id = strtolower($endpointId);
        if (! str_contains($id, 'veo')) {
            if (str_contains($id, 'pixverse/c1/reference-to-video')) {
                return match ($resolution) {
                    '1080p' => 1.9,
                    default => 1.0,
                };
            }

            return 1.0;
        }

        return match ($resolution) {
            '4k' => 2.0,
            '1080p' => 1.5,
            default => 1.0,
        };
    }

    private function normalizeUnit(?string $unit): string
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
