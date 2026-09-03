<?php

namespace App\Services\Credits;

/**
 * Estimates fal USD cost for text-to-video from catalog unit/unit_price
 * plus explicit endpoint pricing policies.
 */
class VideoGenerationCostEstimator
{
    public function __construct(
        private readonly CreditCalculator $credits,
        private readonly FalEndpointPricingPolicy $policy,
    ) {}

    /**
     * @param  array{
     *   endpoint_id: string,
     *   unit?: string|null,
     *   unit_price?: float|string|null,
     *   duration_seconds?: int|null,
     *   audio?: bool|null,
     *   voice_control?: bool|null,
     *   resolution?: string|null,
     *   aspect?: string|null,
     *   reference_video_seconds?: float|int|null,
     *   reference_image_count?: int|null,
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
        $quoted = $this->policy->quoteVideo([
            'endpoint_id' => (string) ($options['endpoint_id'] ?? ''),
            'unit' => $options['unit'] ?? null,
            'unit_price' => (float) ($options['unit_price'] ?? 0),
            'duration_seconds' => $options['duration_seconds'] ?? 5,
            'audio' => (bool) ($options['audio'] ?? false),
            'voice_control' => (bool) ($options['voice_control'] ?? false),
            'resolution' => $options['resolution'] ?? '720p',
            'aspect' => $options['aspect'] ?? '16:9',
            'reference_video_seconds' => $options['reference_video_seconds'] ?? 0,
            'reference_image_count' => $options['reference_image_count'] ?? 0,
        ]);

        if ($quoted === null) {
            return [
                'fal_cost_usd' => 0.0,
                'credits' => 0,
                'billable_units' => 0.0,
                'unit' => (string) ($options['unit'] ?? 'unsupported'),
                'unit_price' => 0.0,
                'breakdown' => ['mode' => 'unsupported_unit', 'policy' => 'fail_closed'],
            ];
        }

        $credits = $quoted['fal_cost_usd'] > 0 ? max(1, $this->credits->fromFalUsd($quoted['fal_cost_usd'])) : 0;
        $breakdown = $quoted['breakdown'];
        $creditsBeforeFloor = $credits;
        $credits = $this->credits->applyFloor($credits, 'video');
        if ($creditsBeforeFloor > 0 && $credits !== $creditsBeforeFloor) {
            $breakdown['credits_before_floor'] = $creditsBeforeFloor;
            $breakdown['min_credits'] = $credits;
        }

        return [
            'fal_cost_usd' => $quoted['fal_cost_usd'],
            'credits' => $credits,
            'billable_units' => $quoted['billable_units'],
            'unit' => $quoted['unit'],
            'unit_price' => $quoted['unit_price'],
            'breakdown' => $breakdown,
        ];
    }
}
