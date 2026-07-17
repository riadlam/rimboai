<?php

namespace App\Services\Credits;

/**
 * Estimates fal USD cost for text-to-speech from catalog unit / unit_price.
 */
class VoiceGenerationCostEstimator
{
    private const MINIMAX_CLONE_FEE_USD = 1.5;

    private const MINIMAX_PREVIEW_PER_1000_CHARS_USD = 0.3;

    public function __construct(
        private readonly CreditCalculator $credits,
    ) {}

    /**
     * @param  array{
     *   endpoint_id?: string|null,
     *   unit?: string|null,
     *   unit_price?: float|string|null,
     *   character_count?: int|null,
     *   sample_seconds?: float|int|null,
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
        $endpointId = strtolower(trim((string) ($options['endpoint_id'] ?? '')));
        $unitRaw = strtolower(trim((string) ($options['unit'] ?? '')));
        $unitPrice = $this->normalizePrice($options['unit_price'] ?? null);
        $chars = max(0, (int) ($options['character_count'] ?? 0));
        $sampleSeconds = isset($options['sample_seconds']) && is_numeric($options['sample_seconds'])
            ? max(0.0, (float) $options['sample_seconds'])
            : null;

        if ($this->isMiniMaxVoiceClone($endpointId)) {
            $cloneFee = (str_contains($unitRaw, 'generation') && $unitPrice > 0)
                ? $unitPrice
                : self::MINIMAX_CLONE_FEE_USD;
            $previewChars = max($chars, 1);
            $previewUsd = ($previewChars / 1000) * self::MINIMAX_PREVIEW_PER_1000_CHARS_USD;
            $falCost = round($cloneFee + $previewUsd, 6);

            return $this->present($falCost, 1.0, 'generations', $cloneFee, [
                'mode' => 'minimax_voice_clone',
                'character_count' => $chars,
                'clone_fee_usd' => $cloneFee,
                'preview_usd' => round($previewUsd, 6),
                'sample_seconds' => $sampleSeconds,
            ]);
        }

        if ($unitPrice <= 0 || $chars <= 0) {
            return $this->present(0.0, 0.0, $unitRaw ?: 'characters', $unitPrice, [
                'mode' => 'zero',
                'character_count' => $chars,
                'sample_seconds' => $sampleSeconds,
            ]);
        }

        // Per 1000 characters (most TTS models)
        if (str_contains($unitRaw, '1000') && str_contains($unitRaw, 'char')) {
            $billable = $chars / 1000;
            $falCost = round($billable * $unitPrice, 6);

            return $this->present($falCost, $billable, '1000 characters', $unitPrice, [
                'mode' => 'per_1000_characters',
                'character_count' => $chars,
                'sample_seconds' => $sampleSeconds,
            ]);
        }

        // Per character
        if (str_contains($unitRaw, 'char') && ! str_contains($unitRaw, '1000')) {
            $billable = (float) $chars;
            $falCost = round($billable * $unitPrice, 6);

            return $this->present($falCost, $billable, 'characters', $unitPrice, [
                'mode' => 'per_character',
                'character_count' => $chars,
                'sample_seconds' => $sampleSeconds,
            ]);
        }

        // Flat per generation
        if (str_contains($unitRaw, 'generation')) {
            $falCost = round($unitPrice, 6);

            return $this->present($falCost, 1.0, 'generations', $unitPrice, [
                'mode' => 'per_generation',
                'character_count' => $chars,
                'sample_seconds' => $sampleSeconds,
            ]);
        }

        // xAI-style compute seconds — rough TTS estimate (~15 chars/sec)
        if (str_contains($unitRaw, 'second') || str_contains($unitRaw, 'compute')) {
            $seconds = max(1.0, $chars / 15.0);
            $falCost = round($seconds * $unitPrice, 6);

            return $this->present($falCost, $seconds, $unitRaw ?: 'compute seconds', $unitPrice, [
                'mode' => 'per_compute_second',
                'character_count' => $chars,
                'estimated_seconds' => round($seconds, 2),
                'sample_seconds' => $sampleSeconds,
            ]);
        }

        // Fallback: treat price as per 1000 characters
        $billable = $chars / 1000;
        $falCost = round($billable * $unitPrice, 6);

        return $this->present($falCost, $billable, $unitRaw ?: '1000 characters', $unitPrice, [
            'mode' => 'fallback_per_1000_characters',
            'character_count' => $chars,
            'sample_seconds' => $sampleSeconds,
        ]);
    }

    private function isMiniMaxVoiceClone(string $endpointId): bool
    {
        return str_contains($endpointId, 'minimax/voice-clone') || str_ends_with($endpointId, '/voice-clone');
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array{fal_cost_usd: float, credits: int, billable_units: float, unit: string, unit_price: float, breakdown: array<string, mixed>}
     */
    private function present(float $falCost, float $billable, string $unit, float $unitPrice, array $breakdown): array
    {
        return [
            'fal_cost_usd' => $falCost,
            'credits' => max($falCost > 0 ? 1 : 0, $this->credits->fromFalUsd($falCost)),
            'billable_units' => $billable,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'breakdown' => $breakdown,
        ];
    }

    private function normalizePrice(float|string|null $price): float
    {
        if ($price === null || $price === '') {
            return 0.0;
        }

        return max(0.0, (float) $price);
    }
}
