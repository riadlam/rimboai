<?php

namespace App\Services;

/**
 * Maps raw fal pricing API rows onto clear billing units our estimators understand.
 *
 * fal often returns unit="units" for token-priced models (Seedance). That is NOT per-second.
 */
class FalVideoPricingNormalizer
{
    /**
     * @return array{
     *   unit: string,
     *   unit_price: float,
     *   formula: string,
     *   notes: string
     * }
     */
    public function normalize(string $endpointId, ?string $rawUnit, ?float $rawPrice): array
    {
        $id = strtolower(trim($endpointId));
        $rawUnit = strtolower(trim((string) $rawUnit));
        $price = $rawPrice !== null && $rawPrice > 0 ? round($rawPrice, 6) : null;

        if (str_contains($id, 'seedance')) {
            // Gallery: $0.014 / 1000 tokens (std), $0.0112 (fast), $0.008 (4k uses different rate in estimator).
            $fallback = str_contains($id, '/fast') ? 0.0112 : 0.014;
            $unitPrice = $price ?? $fallback;

            // If an old sync truncated 0.014 → 0.01, restore known gallery rate.
            if ($unitPrice > 0 && $unitPrice <= 0.011 && ! str_contains($id, '/fast') && ! str_contains($id, '/mini')) {
                $unitPrice = 0.014;
            }
            if (str_contains($id, '/fast') && $unitPrice > 0 && $unitPrice <= 0.01) {
                $unitPrice = 0.0112;
            }

            return [
                'unit' => 'tokens_per_1000',
                'unit_price' => $unitPrice,
                'formula' => 'tokens = (height * width * duration_seconds * 24) / 1024; fal_cost = (tokens / 1000) * unit_price',
                'notes' => '4k uses $0.008 per 1000 tokens (handled in estimator). Audio does not change Seedance price.',
            ];
        }

        if ($rawUnit === 'second' || $rawUnit === 'seconds' || $price !== null && $this->looksLikePerSecond($id, $rawUnit, $price)) {
            return [
                'unit' => 'seconds',
                'unit_price' => $price ?? 0.0,
                'formula' => 'fal_cost = duration_seconds * unit_price * audio_multiplier * resolution_multiplier',
                'notes' => $this->secondsNotes($id),
            ];
        }

        // Unknown opaque "units" — keep fal number but label clearly so we don't treat as seconds by accident.
        if ($rawUnit === 'unit' || $rawUnit === 'units') {
            return [
                'unit' => 'units',
                'unit_price' => $price ?? 0.0,
                'formula' => 'fal_cost = billable_units * unit_price (billable_units model-specific — needs review)',
                'notes' => 'fal returned opaque unit=units; do not assume per-second.',
            ];
        }

        return [
            'unit' => $rawUnit !== '' ? $rawUnit : 'seconds',
            'unit_price' => $price ?? 0.0,
            'formula' => 'fal_cost = duration_seconds * unit_price (default assumption)',
            'notes' => 'Fallback normalization.',
        ];
    }

    private function looksLikePerSecond(string $id, string $rawUnit, float $price): bool
    {
        if ($rawUnit !== '' && $rawUnit !== 'units' && $rawUnit !== 'unit') {
            return false;
        }

        // Typical fal video per-second rates land between ~$0.03 and ~$1.00.
        if (str_contains($id, 'kling') || str_contains($id, 'veo') || str_contains($id, 'sora') || str_contains($id, 'wan') || str_contains($id, 'grok') || str_contains($id, 'gemini-omni')) {
            return $price >= 0.03 && $price <= 2.0;
        }

        return false;
    }

    private function secondsNotes(string $id): string
    {
        if (str_contains($id, 'kling') && (str_contains($id, 'v3') || str_contains($id, '/o3/'))) {
            return 'Audio on ≈ 1.5× silent rate.';
        }
        if (str_contains($id, 'veo')) {
            return 'Higher resolution multiplies cost (1080p≈1.5×, 4k≈2×) in estimator.';
        }
        if (str_contains($id, 'gemini-omni')) {
            return 'Token-priced on Fal; Lab bills ≈$/s @720p. Audio always included (no toggle).';
        }

        return 'Per-second billing from fal pricing API.';
    }
}
