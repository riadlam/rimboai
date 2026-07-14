<?php

namespace App\Services\Credits;

/**
 * Music pricing. Flat per-generation for audios-priced models.
 * Time-based units (seconds / minutes) use source duration when provided,
 * otherwise each model's default_duration_seconds assumption.
 */
class MusicGenerationCostEstimator
{
    public function __construct(
        private CreditCalculator $credits,
    ) {}

    /**
     * @param  array{unit?: string|null, unit_price?: float|string|null, default_duration_seconds?: int|null, max_duration?: int|null}  $model
     * @return array{credits: int, fal_cost_usd: float, billable_units: float, unit: string, assumed_seconds: int|null}
     */
    public function estimate(array $model, bool $autoEnhance = false, ?int $durationSeconds = null): array
    {
        $unitPrice = $this->normalizePrice($model['unit_price'] ?? null);
        $unitRaw = strtolower(trim((string) ($model['unit'] ?? '')));
        $assumedSeconds = $this->resolveAssumedSeconds($model, $durationSeconds);

        if ($unitPrice <= 0) {
            return [
                'credits' => 0,
                'fal_cost_usd' => 0.0,
                'billable_units' => 0.0,
                'unit' => $unitRaw !== '' ? $unitRaw : 'unknown',
                'assumed_seconds' => $assumedSeconds,
            ];
        }

        $billableUnits = 1.0;
        $unitLabel = $unitRaw !== '' ? $unitRaw : 'audios';

        if (str_contains($unitRaw, 'minute')) {
            $seconds = $assumedSeconds ?? 120;
            $billableUnits = max($seconds / 60, 1 / 60);
            $unitLabel = 'minutes';
        } elseif (str_contains($unitRaw, 'second') || str_contains($unitRaw, 'compute')) {
            $billableUnits = (float) max($assumedSeconds ?? 90, 1);
            $unitLabel = str_contains($unitRaw, 'compute') ? 'compute seconds' : 'seconds';
        } else {
            $billableUnits = 1.0;
            $unitLabel = str_contains($unitRaw, 'audio') ? 'audios' : ($unitRaw !== '' ? $unitRaw : 'audios');
        }

        $falCostUsd = round($billableUnits * $unitPrice, 6);
        $credits = $falCostUsd > 0 ? max(1, $this->credits->fromFalUsd($falCostUsd)) : 0;

        if ($autoEnhance) {
            $credits += 1;
        }

        return [
            'credits' => $credits,
            'fal_cost_usd' => $falCostUsd,
            'billable_units' => $billableUnits,
            'unit' => $unitLabel,
            'assumed_seconds' => $assumedSeconds,
        ];
    }

    /**
     * @param  array{default_duration_seconds?: int|null, max_duration?: int|null, unit?: string|null}  $model
     */
    private function resolveAssumedSeconds(array $model, ?int $durationSeconds): ?int
    {
        $max = $model['max_duration'] ?? null;
        $max = is_numeric($max) && (int) $max > 0 ? (int) $max : null;

        if (is_int($durationSeconds) && $durationSeconds > 0) {
            $rounded = max(1, $durationSeconds);

            return $max !== null ? min($max, $rounded) : $rounded;
        }

        $configured = $model['default_duration_seconds'] ?? null;
        if (is_numeric($configured) && (int) $configured > 0) {
            return (int) $configured;
        }

        if ($max !== null) {
            return min(180, max(60, (int) round($max * 0.5)));
        }

        $unitRaw = strtolower((string) ($model['unit'] ?? ''));
        if (str_contains($unitRaw, 'minute') || str_contains($unitRaw, 'second') || str_contains($unitRaw, 'compute')) {
            return 120;
        }

        return null;
    }

    private function normalizePrice(mixed $raw): float
    {
        if (is_int($raw) || is_float($raw)) {
            return max(0.0, (float) $raw);
        }

        if (! is_string($raw) || $raw === '') {
            return 0.0;
        }

        $cleaned = preg_replace('/[^0-9.\-eE]/', '', $raw) ?? '';

        return max(0.0, (float) $cleaned);
    }
}
