<?php

namespace App\Services\Credits;

use App\Services\FalImageInputBuilder;

/**
 * Estimates fal USD cost for a text-to-image (or edit) job from catalog unit/unit_price.
 */
class ImageGenerationCostEstimator
{
    public function __construct(
        private readonly FalImageInputBuilder $inputBuilder,
        private readonly CreditCalculator $credits,
    ) {}

    /**
     * @param  array{
     *   endpoint_id: string,
     *   unit?: string|null,
     *   unit_price?: float|string|null,
     *   aspect?: string|null,
     *   resolution?: string|null,
     *   quantity?: int|null,
     *   reference_count?: int|null,
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
        $unitPrice = $this->normalizePrice($options['unit_price'] ?? null);
        $aspect = $options['aspect'] ?? '1:1';
        $resolution = strtoupper((string) ($options['resolution'] ?? '1K'));
        $quantity = max(1, min(4, (int) ($options['quantity'] ?? 1)));
        $referenceCount = max(0, min(8, (int) ($options['reference_count'] ?? 0)));

        // GPT "units" @ $1.00 from pricing sync is not a usable per-image rate.
        if ($this->isGptImage($endpointId)) {
            return $this->estimateGpt($endpointId, $resolution, $quantity, $referenceCount, $unit, $unitPrice);
        }

        return match ($unit) {
            'megapixels', 'processed_megapixels' => $this->estimateMegapixels(
                $endpointId,
                $unit,
                $unitPrice,
                $aspect,
                $resolution,
                $quantity,
                $referenceCount,
            ),
            default => $this->estimatePerImage(
                $endpointId,
                $unit,
                $unitPrice,
                $resolution,
                $quantity,
                $referenceCount,
            ),
        };
    }

    /**
     * @return array{fal_cost_usd: float, credits: int, billable_units: float, unit: string, unit_price: float, breakdown: array<string, mixed>}
     */
    private function estimatePerImage(
        string $endpointId,
        string $unit,
        float $unitPrice,
        string $resolution,
        int $quantity,
        int $referenceCount,
    ): array {
        $multiplier = $this->resolutionMultiplier($endpointId, $resolution);
        $billable = $quantity * $multiplier;
        // Banana / Gemini edit: same output pricing (refs do not multiply).
        $falCost = round($billable * $unitPrice, 6);

        return $this->present($falCost, $billable, $unit ?: 'images', $unitPrice, [
            'mode' => 'per_image',
            'quantity' => $quantity,
            'resolution_multiplier' => $multiplier,
            'reference_count' => $referenceCount,
            'reference_surcharge_usd' => 0,
        ]);
    }

    /**
     * @return array{fal_cost_usd: float, credits: int, billable_units: float, unit: string, unit_price: float, breakdown: array<string, mixed>}
     */
    private function estimateMegapixels(
        string $endpointId,
        string $unit,
        float $unitPrice,
        string $aspect,
        string $resolution,
        int $quantity,
        int $referenceCount,
    ): array {
        $outputMp = $this->inputBuilder->estimateOutputMegapixels($endpointId, $aspect, $resolution);
        $inputMp = $referenceCount > 0 ? (float) $referenceCount : 0.0; // fal often bills ~1MP per input
        $billable = ($outputMp * $quantity) + $inputMp;
        $falCost = round($billable * $unitPrice, 6);

        return $this->present($falCost, $billable, $unit, $unitPrice, [
            'mode' => 'per_megapixel',
            'output_megapixels' => $outputMp,
            'input_megapixels' => $inputMp,
            'quantity' => $quantity,
            'reference_count' => $referenceCount,
        ]);
    }

    /**
     * @return array{fal_cost_usd: float, credits: int, billable_units: float, unit: string, unit_price: float, breakdown: array<string, mixed>}
     */
    private function estimateGpt(
        string $endpointId,
        string $resolution,
        int $quantity,
        int $referenceCount,
        string $unit,
        float $unitPrice,
    ): array {
        // Approximate published fal GPT Image output prices (USD / image).
        $perImage = match ($resolution) {
            '4K' => 0.25,
            '2K' => 0.15,
            default => 0.06,
        };

        $base = $perImage * $quantity;
        // Input tokens raise edit cost — approximate +15% of base per reference.
        $refSurcharge = $referenceCount > 0 ? $base * 0.15 * $referenceCount : 0.0;
        $falCost = round($base + $refSurcharge, 6);
        $billable = (float) $quantity;

        return $this->present($falCost, $billable, 'gpt_tier', $perImage, [
            'mode' => 'gpt_tier',
            'quantity' => $quantity,
            'per_image_usd' => $perImage,
            'reference_count' => $referenceCount,
            'reference_surcharge_usd' => round($refSurcharge, 6),
            'catalog_unit' => $unit,
            'catalog_unit_price' => $unitPrice,
            'endpoint_id' => $endpointId,
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
            'credits' => $this->credits->fromFalUsd($falCost),
            'billable_units' => $billable,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'breakdown' => $breakdown,
        ];
    }

    private function resolutionMultiplier(string $endpointId, string $resolution): float
    {
        $id = strtolower($endpointId);

        // Nano Banana / Gemini gallery pricing: resolution scales the per-image rate.
        $scaled = str_contains($id, 'nano-banana') || str_contains($id, 'gemini');
        if (! $scaled) {
            return 1.0;
        }

        return match ($resolution) {
            '4K' => 2.0,
            '2K' => 1.5,
            default => 1.0,
        };
    }

    private function normalizeUnit(?string $unit): string
    {
        $unit = strtolower(trim((string) $unit));

        return match ($unit) {
            'image', 'images' => 'images',
            'megapixel', 'megapixels' => 'megapixels',
            'processed megapixel', 'processed megapixels' => 'processed_megapixels',
            'unit', 'units' => 'units',
            default => $unit !== '' ? $unit : 'images',
        };
    }

    private function normalizePrice(mixed $price): float
    {
        if ($price === null || $price === '') {
            return 0.0;
        }

        return max(0.0, (float) $price);
    }

    private function isGptImage(string $endpointId): bool
    {
        $id = strtolower($endpointId);

        return str_contains($id, 'gpt-image');
    }
}
