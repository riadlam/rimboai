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
     * Snap measured duration UP to the smallest supported enum ≥ duration.
     *
     * @param  list<int|float|string>|null  $enums
     */
    public static function snapBillableDuration(float $seconds, ?array $enums, ?int $maxDuration = null): float
    {
        if ($seconds <= 0 || ! is_finite($seconds)) {
            return 0.0;
        }

        $steps = [];
        foreach ($enums ?? [] as $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $n = (float) $value;
            if ($n > 0) {
                $steps[] = $n;
            }
        }
        $steps = array_values(array_unique($steps));
        sort($steps, SORT_NUMERIC);

        if ($steps === []) {
            $d = max(1.0, (float) ceil($seconds - 1e-9));
            if ($maxDuration !== null && $maxDuration > 0) {
                $d = min($d, (float) $maxDuration);
            }

            return $d;
        }

        foreach ($steps as $step) {
            if ($step + 1e-9 >= $seconds) {
                return (float) $step;
            }
        }

        $last = (float) $steps[array_key_last($steps)];
        if ($maxDuration !== null && $maxDuration > 0) {
            return min($last, (float) $maxDuration);
        }

        return $last;
    }

    /**
     * Wan 2.2 family bills "video seconds" at 16fps (= num_frames / 16), not wall-clock.
     */
    public static function usesWan22VideoSeconds(string $endpointId): bool
    {
        $id = strtolower($endpointId);

        return str_contains($id, 'wan/v2.2-a14b/video-to-video')
            || str_contains($id, 'wan/v2.2-14b/animate/move');
    }

    /**
     * Match Lab FalVideoInputBuilder: odd frame count in [17, 161] at 16fps.
     */
    public static function wan22FramesForDuration(float $durationSeconds): int
    {
        $fps = 16;
        $frames = max(17, min(161, (int) round(max(0.0, $durationSeconds) * $fps) + 1));
        if ($frames % 2 === 0) {
            $frames = min(161, $frames + 1);
        }

        return $frames;
    }

    public static function wan22VideoSeconds(float $durationSeconds): float
    {
        return self::wan22FramesForDuration($durationSeconds) / 16.0;
    }

    /**
     * Flat per-clip Fal units (PixVerse swap / motion, VOID, etc.).
     * DB sometimes stores plurals / "video segments".
     */
    public static function isFlatVideoUnit(string $unit): bool
    {
        $normalized = strtolower(trim(str_replace(' ', '_', $unit)));

        return in_array($normalized, ['video', 'videos', 'video_segments'], true);
    }

    /**
     * @param  array{
     *   endpoint_id?: string|null,
     *   unit?: string|null,
     *   unit_price?: float|string|null,
     *   unit_price_by_resolution?: array<string, float|int|string>|null,
     *   duration_seconds?: float|int|null,
     *   duration_enums?: list<int|float|string>|null,
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
        $endpointId = (string) ($options['endpoint_id'] ?? '');
        $unit = $this->normalizeUnit($options['unit'] ?? 'seconds');
        $maxDuration = isset($options['max_duration']) ? (int) $options['max_duration'] : null;
        $duration = self::snapBillableDuration(
            (float) ($options['duration_seconds'] ?? 0),
            isset($options['duration_enums']) && is_array($options['duration_enums'])
                ? $options['duration_enums']
                : null,
            $maxDuration,
        );
        $fps = max(1.0, (float) ($options['fps'] ?? 24));
        $resolution = strtolower((string) ($options['resolution'] ?? '1080p'));
        $unitPrice = $this->resolveUnitPrice(
            (float) ($options['unit_price'] ?? 0),
            $options['unit_price_by_resolution'] ?? null,
            $resolution,
        );

        if (! ($duration > 0) || $unitPrice <= 0) {
            return $this->pack(0.0, 0.0, $unit !== '' ? $unit : 'seconds', $unitPrice, [
                'formula' => 'no_billable_duration',
                'duration_seconds' => $duration,
            ]);
        }

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

        if ($unit === 'minutes' || $unit === 'compute_seconds') {
            // compute_seconds is billed like wall seconds (VEED-style); minutes = duration/60.
            if ($unit === 'minutes') {
                $minutes = $duration / 60;
                $falCost = round($minutes * $unitPrice, 6);

                return $this->pack($falCost, round($minutes, 6), $unit, $unitPrice, [
                    'formula' => 'duration_minutes * unit_price',
                    'duration_seconds' => $duration,
                ]);
            }

            $falCost = round($duration * $unitPrice, 6);

            return $this->pack($falCost, $duration, $unit, $unitPrice, [
                'formula' => 'duration_seconds * unit_price',
                'duration_seconds' => $duration,
            ]);
        }

        if (self::isFlatVideoUnit($unit)) {
            $multiplier = $duration > 5 ? 2.0 : 1.0;
            $falCost = round($unitPrice * $multiplier, 6);

            return $this->pack($falCost, $multiplier, 'video', $unitPrice, [
                'formula' => 'unit_price * (duration>5 ? 2 : 1)',
                'duration_seconds' => $duration,
            ]);
        }

        // Wan 2.2: Fal video-seconds = num_frames / 16 (not wall-clock input length).
        if (self::usesWan22VideoSeconds($endpointId)) {
            $frames = self::wan22FramesForDuration($duration);
            $videoSeconds = $frames / 16.0;
            $falCost = round($videoSeconds * $unitPrice, 6);

            return $this->pack($falCost, $videoSeconds, 'video_seconds_16fps', $unitPrice, [
                'formula' => '(num_frames/16) * unit_price',
                'wall_duration_seconds' => $duration,
                'num_frames' => $frames,
                'frames_per_second' => 16,
                'video_seconds' => $videoSeconds,
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
            'credits' => $falCost > 0 ? $this->credits->fromFalUsd($falCost) : 0,
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
