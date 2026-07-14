<?php

namespace App\Services\Credits;

/**
 * Estimates fal USD cost for text-to-speech from catalog unit / unit_price.
 */
class VoiceGenerationCostEstimator
{
    public function __construct(
        private readonly CreditCalculator $credits,
    ) {}

    /**
     * @param  array{
     *   endpoint_id?: string|null,
     *   unit?: string|null,
     *   unit_price?: float|string|null,
     *   character_count?: int|null,
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
        $unitRaw = strtolower(trim((string) ($options['unit'] ?? '')));
        $unitPrice = $this->normalizePrice($options['unit_price'] ?? null);
        $chars = max(0, (int) ($options['character_count'] ?? 0));

        if ($unitPrice <= 0 || $chars <= 0) {
            return $this->present(0.0, 0.0, $unitRaw ?: 'characters', $unitPrice, [
                'mode' => 'zero',
                'character_count' => $chars,
            ]);
        }

        // Per 1000 characters (most TTS models)
        if (str_contains($unitRaw, '1000') && str_contains($unitRaw, 'char')) {
            $billable = $chars / 1000;
            $falCost = round($billable * $unitPrice, 6);

            return $this->present($falCost, $billable, '1000 characters', $unitPrice, [
                'mode' => 'per_1000_characters',
                'character_count' => $chars,
            ]);
        }

        // Per character
        if (str_contains($unitRaw, 'char') && ! str_contains($unitRaw, '1000')) {
            $billable = (float) $chars;
            $falCost = round($billable * $unitPrice, 6);

            return $this->present($falCost, $billable, 'characters', $unitPrice, [
                'mode' => 'per_character',
                'character_count' => $chars,
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
            ]);
        }

        // Fallback: treat price as per 1000 characters
        $billable = $chars / 1000;
        $falCost = round($billable * $unitPrice, 6);

        return $this->present($falCost, $billable, $unitRaw ?: '1000 characters', $unitPrice, [
            'mode' => 'fallback_per_1000_characters',
            'character_count' => $chars,
        ]);
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
