<?php

namespace App\Services\Trends;

/**
 * Provider USD estimate for Sogni-style MiniMax H3 split + FlashVSR.
 */
class H3SplitCostEstimator
{
    public const H3_ENDPOINT = 'minimax/h3/reference-to-video';

    public const FLASHVSR_ENDPOINT = 'fal-ai/flashvsr/upscale/video';

    /** H3 gen is always billed at 768P in this workflow. */
    public const H3_RATE_768P = 0.06;

    public const FLASHVSR_PER_MEGAPIXEL = 0.0005;

    public const UPSCALED_WIDTH = 2560;

    public const UPSCALED_HEIGHT = 1440;

    public const FPS = 24.0;

    /**
     * @param  list<array{start?: float, end?: float, duration: float|int}>  $sections
     * @return array{
     *   fal_cost_usd: float,
     *   video_usd: float,
     *   upscale_usd: float,
     *   sheets_usd: float,
     *   billable_units: float,
     *   unit: string,
     *   unit_price: float,
     *   breakdown: array<string, mixed>
     * }
     */
    public function estimate(array $sections, int $imageCount = 2): array
    {
        $videoUsd = 0.0;
        $upscaleUsd = 0.0;
        $billableSeconds = 0.0;
        $sectionRows = [];

        foreach ($sections as $i => $section) {
            $sec = max(1.0, (float) ($section['duration'] ?? 0));
            $in = (int) max(1, (int) ceil($sec));
            $out = (int) max(5, min(15, (int) ceil($sec)));
            $billable = $in + $out;
            $h3 = round($billable * self::H3_RATE_768P, 6);
            $frames = (int) max(1, (int) round($sec * self::FPS));
            $megapixels = (self::UPSCALED_WIDTH * self::UPSCALED_HEIGHT * $frames) / 1_000_000;
            $flash = round($megapixels * self::FLASHVSR_PER_MEGAPIXEL, 6);

            $videoUsd += $h3;
            $upscaleUsd += $flash;
            $billableSeconds += $billable;
            $sectionRows[] = [
                'index' => $i,
                'duration' => $sec,
                'input_ceil' => $in,
                'output_ceil' => $out,
                'h3_usd' => $h3,
                'flashvsr_usd' => $flash,
                'frames' => $frames,
            ];
        }

        // First 5 reference images free on fal H3.
        $extraImages = max(0, $imageCount - 5);
        $imageUsd = round($extraImages * 0.08, 6);
        $videoUsd = round($videoUsd + $imageUsd, 6);
        $upscaleUsd = round($upscaleUsd, 6);
        $total = round($videoUsd + $upscaleUsd, 6);

        return [
            'fal_cost_usd' => $total,
            'video_usd' => $videoUsd,
            'upscale_usd' => $upscaleUsd,
            'sheets_usd' => 0.0,
            'billable_units' => $billableSeconds,
            'unit' => 'input_plus_output_seconds',
            'unit_price' => self::H3_RATE_768P,
            'breakdown' => [
                'provider' => 'fal',
                'workflow' => 'h3_split',
                'h3_endpoint' => self::H3_ENDPOINT,
                'flashvsr_endpoint' => self::FLASHVSR_ENDPOINT,
                'h3_resolution' => '768P',
                'h3_rate_usd' => self::H3_RATE_768P,
                'image_count' => $imageCount,
                'extra_image_usd' => $imageUsd,
                'upscale_target' => self::UPSCALED_WIDTH.'x'.self::UPSCALED_HEIGHT,
                'sections' => $sectionRows,
                'formula' => '(ceil(input)+ceil(output))*0.06 + flashvsr_megapixels*0.0005',
            ],
        ];
    }

    /**
     * Build a section plan from total duration without probing media (admin Estimate).
     *
     * @return list<array{start: float, end: float, duration: float}>
     */
    public function planFromDuration(float $durationSeconds): array
    {
        $durationSeconds = max(MotionSectionSplitter::MIN_SECTION_SECONDS, $durationSeconds);
        $splitter = app(MotionSectionSplitter::class);
        $cuts = [];
        $t = MotionSectionSplitter::TARGET_SECTION_SECONDS;
        while ($t < $durationSeconds - 0.5) {
            $cuts[] = round($t, 3);
            $t += MotionSectionSplitter::TARGET_SECTION_SECONDS;
        }

        return $splitter->packSections($durationSeconds, $cuts);
    }
}
