<?php

namespace App\Services\Credits;

/**
 * PHP mirror of resources/js/lib/toolCredits.ts for server-side charging.
 */
class ToolGenerationCostEstimator
{
    public function __construct(
        private readonly CreditCalculator $credits,
    ) {}

    /**
     * @param  array{
     *   unit?: string|null,
     *   unit_price?: float|string|null,
     *   unit_price_by_resolution?: array<string, float|int|string>|null,
     *   duration_seconds?: float|int|null,
     *   max_duration?: int|null,
     *   resolution?: string|null,
     *   fps?: float|int|null,
     * }  $options
     * @return array{
     *   fal_cost_usd: float,
     *   credits: int,
     *   billable_units: float,
     *   unit: string,
     *   unit_price: float,
     *   breakdown: array<string, mixed>,
     * }
     */
    public function estimate(array $options): array
    {
        $unit = $this->normalizeUnit($options['unit'] ?? 'seconds');
        $maxDuration = isset($options['max_duration']) ? (int) $options['max_duration'] : null;
        $duration = $this->clampDuration((float) ($options['duration_seconds'] ?? 5), $maxDuration);
        $fps = max(1.0, (float) ($options['fps'] ?? 24));
        $resolution = strtolower((string) ($options['resolution'] ?? '1080p'));
        $unitPrice = $this->resolveUnitPrice(
            (float) ($options['unit_price'] ?? 0),
            $options['unit_price_by_resolution'] ?? null,
            $resolution,
        );

        if (in_array($unit, ['megapixels', 'processed_megapixels'], true)) {
            [$w, $h] = $this->dimsFor($resolution);
            $frames = max(1, (int) round($duration * $fps));
            $megapixels = ($w * $h * $frames) / 1_000_000;
            $falCost = round($megapixels * $unitPrice, 6);

            return $this->pack($falCost, $megapixels, $unit, $unitPrice, [
                'formula' => '(W*H*frames)/1e6 * unit_price',
                'width' => $w,
                'height' => $h,
                'frames' => $frames,
                'duration_seconds' => $duration,
            ]);
        }

        if ($unit === 'frames_30') {
            $frameFps = max(1.0, (float) ($options['fps'] ?? 30));
            $frames = max(1, (int) round($duration * $frameFps));
            $blocks = max(1, (int) ceil($frames / 30));
            $falCost = round($blocks * $unitPrice, 6);

            return $this->pack($falCost, (float) $blocks, $unit, $unitPrice, [
                'formula' => 'ceil(frames/30) * unit_price',
                'frames' => $frames,
                'duration_seconds' => $duration,
            ]);
        }

        if ($unit === 'minutes') {
            $minutes = $duration / 60;
            $falCost = round($minutes * $unitPrice, 6);

            return $this->pack($falCost, round($minutes, 6), $unit, $unitPrice, [
                'formula' => 'duration_minutes * unit_price',
                'duration_seconds' => $duration,
            ]);
        }

        if ($unit === 'video') {
            $multiplier = $duration > 5 ? 2.0 : 1.0;
            $falCost = round($unitPrice * $multiplier, 6);

            return $this->pack($falCost, $multiplier, $unit, $unitPrice, [
                'formula' => 'unit_price * (duration>5 ? 2 : 1)',
                'duration_seconds' => $duration,
            ]);
        }

        $falCost = round($duration * $unitPrice, 6);

        return $this->pack($falCost, $duration, $unit !== '' ? $unit : 'seconds', $unitPrice, [
            'formula' => 'duration_seconds * unit_price',
            'duration_seconds' => $duration,
        ]);
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array{
     *   fal_cost_usd: float,
     *   credits: int,
     *   billable_units: float,
     *   unit: string,
     *   unit_price: float,
     *   breakdown: array<string, mixed>,
     * }
     */
    private function pack(float $falCost, float $units, string $unit, float $unitPrice, array $breakdown): array
    {
        return [
            'fal_cost_usd' => $falCost,
            'credits' => $this->credits->fromFalUsd($falCost),
            'billable_units' => $units,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'breakdown' => $breakdown,
        ];
    }

    private function resolveUnitPrice(float $fallback, mixed $tiers, string $resolution): float
    {
        if (is_array($tiers)) {
            foreach ($tiers as $key => $value) {
                if (strtolower((string) $key) === $resolution && is_numeric($value)) {
                    return max(0.0, (float) $value);
                }
            }
        }

        return max(0.0, $fallback);
    }

    private function clampDuration(float $seconds, ?int $max): float
    {
        $d = max(1.0, $seconds);
        if ($max !== null && $max > 0) {
            $d = min($d, (float) $max);
        }

        return $d;
    }

    private function normalizeUnit(?string $unit): string
    {
        return strtolower(trim(str_replace(' ', '_', (string) $unit)));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimsFor(string $resolution): array
    {
        return match ($resolution) {
            '360p' => [640, 360],
            '480p' => [854, 480],
            '540p' => [960, 540],
            '580p' => [1024, 576],
            '720p' => [1280, 720],
            '1440p', '2k' => [2560, 1440],
            '2160p', '4k' => [3840, 2160],
            default => [1920, 1080],
        };
    }
}
