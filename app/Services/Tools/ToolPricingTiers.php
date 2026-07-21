<?php

namespace App\Services\Tools;

/**
 * Resolution-tiered Fal prices for video tools.
 *
 * Source of truth order (money-safe):
 *  1) DB `unit_price_by_resolution` when present
 *  2) Hardcoded maps (safety net — never bill below these for known keys)
 *  3) Per-key MAX(db, hardcoded) so a stale/low DB row cannot undercharge
 *
 * Billing formulas (60fps×2, Kling base+extra, Wan frames) stay in the estimator —
 * Fal's pricing API does not expose them.
 */
class ToolPricingTiers
{
    /**
     * @param  array<string, mixed>|null  $dbTiers
     * @param  array<string, mixed>  $defaults
     * @return array<string, float>|null
     */
    public static function resolve(
        string $endpointId,
        string $unit,
        array $defaults = [],
        ?array $dbTiers = null,
    ): ?array {
        $code = self::hardcoded($endpointId, $unit, $defaults);
        $db = self::normalize($dbTiers);

        if ($db === []) {
            return $code;
        }
        if ($code === null || $code === []) {
            return $db;
        }

        // Never bill below the code safety net for known resolutions.
        $out = $db;
        foreach ($code as $key => $price) {
            $out[$key] = max((float) ($out[$key] ?? 0), (float) $price);
        }

        return $out;
    }

    /**
     * Decode DB JSON / array into lowercase resolution => positive float.
     *
     * @param  mixed  $tiers
     * @return array<string, float>
     */
    public static function normalize(mixed $tiers): array
    {
        if (is_string($tiers) && $tiers !== '') {
            $decoded = json_decode($tiers, true);
            $tiers = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($tiers) || $tiers === []) {
            return [];
        }

        $out = [];
        foreach ($tiers as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }
            $label = strtolower(trim((string) $key));
            if ($label === '' || str_starts_with($label, '_')) {
                continue;
            }
            if (! is_numeric($value) || (float) $value <= 0) {
                continue;
            }
            $out[$label] = round((float) $value, 6);
        }

        return $out;
    }

    /**
     * Proportionally rescale tiers when Fal base unit_price changes.
     * Returns null when scaling should be skipped (empty / invalid / Gaia locked).
     *
     * @param  array<string, float>  $tiers
     * @return array<string, float>|null
     */
    public static function scale(array $tiers, float $oldUnitPrice, float $newUnitPrice): ?array
    {
        if ($tiers === [] || $oldUnitPrice <= 0 || $newUnitPrice <= 0) {
            return null;
        }
        if (abs($oldUnitPrice - $newUnitPrice) < 1e-9) {
            return null;
        }

        $ratio = $newUnitPrice / $oldUnitPrice;
        // Guard against absurd Fal blips (typo / wrong unit) that would nuke margins.
        if ($ratio < 0.25 || $ratio > 4.0) {
            return null;
        }

        $out = [];
        foreach ($tiers as $key => $price) {
            $out[$key] = round((float) $price * $ratio, 6);
        }

        return $out;
    }

    /**
     * Gaia 2 (and similar) use intentionally halved Topaz tiers — do not rescale
     * from Fal's single endpoint list price (that price is the full Proteus rate).
     *
     * @param  array<string, mixed>|null  $defaults
     */
    public static function isTierScaleLocked(?array $defaults): bool
    {
        if (! is_array($defaults)) {
            return false;
        }
        if (! empty($defaults['pricing_tiers_locked'])) {
            return true;
        }
        $model = strtolower((string) ($defaults['model'] ?? ''));

        return str_contains($model, 'gaia 2') || $model === 'gaia2';
    }

    /**
     * Hardcoded Fal tier maps — safety net when DB is empty / stale-low.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, float>|null
     */
    public static function hardcoded(string $endpointId, string $unit, array $defaults = []): ?array
    {
        // Wan 2.7 edit-video — flat $0.10/s
        if (str_contains($endpointId, 'wan/v2.7/edit-video')) {
            return [
                '720p' => 0.10,
                '1080p' => 0.10,
            ];
        }

        if (
            str_contains($endpointId, 'wan/v2.7/image-to-video')
            || str_contains($endpointId, 'wan/v2.7/text-to-video')
            || str_contains($endpointId, 'wan/v2.7/reference-to-video')
        ) {
            return [
                '720p' => 0.10,
                '1080p' => 0.15,
            ];
        }

        if (
            str_contains($endpointId, 'wan/v2.2-14b/animate/move')
            || str_contains($endpointId, 'wan/v2.2-a14b/video-to-video')
        ) {
            return [
                '480p' => 0.04,
                '580p' => 0.06,
                '720p' => 0.08,
            ];
        }

        if (str_contains($endpointId, 'topaz/upscale/video')) {
            $model = strtolower((string) ($defaults['model'] ?? ''));
            $half = str_contains($model, 'gaia 2') || $model === 'gaia2';
            $tiers = [
                '720p' => 0.01,
                '1080p' => 0.02,
                '1440p' => 0.08,
                '2k' => 0.08,
                '2160p' => 0.08,
                '4k' => 0.08,
            ];
            if ($half) {
                foreach ($tiers as $key => $price) {
                    $tiers[$key] = round($price / 2, 6);
                }
            }

            return $tiers;
        }

        if (str_contains($endpointId, 'bytedance-upscaler')) {
            return [
                '1080p' => 0.0072,
                '2k' => 0.0144,
                '1440p' => 0.0144,
                '4k' => 0.0288,
                '2160p' => 0.0288,
            ];
        }

        $flatVideo = \App\Services\Credits\ToolGenerationCostEstimator::isFlatVideoUnit($unit);

        if (str_contains($endpointId, 'pixverse/v4.5/image-to-video') && $flatVideo) {
            if (str_contains($endpointId, '/fast')) {
                return [
                    '360p' => 0.30,
                    '540p' => 0.30,
                    '720p' => 0.40,
                    '1080p' => 0.80,
                ];
            }

            return [
                '360p' => 0.15,
                '540p' => 0.15,
                '720p' => 0.20,
                '1080p' => 0.40,
            ];
        }

        if (str_contains($endpointId, 'pixverse/swap') && $flatVideo) {
            return [
                '360p' => 0.15,
                '540p' => 0.15,
                '720p' => 0.20,
            ];
        }

        if (str_contains($endpointId, 'pixverse/v6/extend')) {
            return [
                '360p' => 0.03,
                '540p' => 0.03,
                '720p' => 0.045,
                '1080p' => 0.09,
            ];
        }

        return null;
    }

    /**
     * Kling 2.5 Turbo Pro: base clip + per-second beyond base.
     *
     * @param  array<string, mixed>  $defaults
     * @return array{base_cost_usd: float, base_seconds: float, extra_unit_price: float}
     */
    public static function klingTurboProPricing(array $defaults, float $unitPrice): array
    {
        $pricing = is_array($defaults['pricing'] ?? null) ? $defaults['pricing'] : [];

        $baseCost = isset($pricing['base_cost_usd']) && is_numeric($pricing['base_cost_usd'])
            ? max(0.0, (float) $pricing['base_cost_usd'])
            : 0.35;
        $baseSeconds = isset($pricing['base_seconds']) && is_numeric($pricing['base_seconds'])
            ? max(1.0, (float) $pricing['base_seconds'])
            : 5.0;
        $extra = $unitPrice > 0 ? $unitPrice : 0.07;

        return [
            'base_cost_usd' => $baseCost,
            'base_seconds' => $baseSeconds,
            'extra_unit_price' => $extra,
        ];
    }
}
