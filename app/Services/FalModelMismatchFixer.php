<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Applies Fal vs DB mismatch fixes for admin compare screens.
 */
class FalModelMismatchFixer
{
    public function __construct(
        private FalModelInspector $inspector,
        private FalVideoPricingNormalizer $videoPricingNormalizer,
    ) {}

    /**
     * @param  list<string>|null  $fields  Null = fix all auto-fixable fields from current Fal inspect.
     * @return array{ok: bool, error?: string, applied: list<string>, skipped: list<string>, changes: array<string, array{from: mixed, to: mixed}>}
     */
    public function fix(Model $model, string $catalog, ?array $fields = null, bool $fresh = true): array
    {
        $endpointId = trim((string) $model->getAttribute('endpoint_id'));
        if ($endpointId === '') {
            return ['ok' => false, 'error' => 'Model has no endpoint_id', 'applied' => [], 'skipped' => [], 'changes' => []];
        }

        $inspect = $this->inspector->inspect($endpointId, $fresh);
        if (! ($inspect['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => (string) ($inspect['error'] ?? 'Fal inspect failed'),
                'applied' => [],
                'skipped' => [],
                'changes' => [],
            ];
        }

        $extracted = is_array($inspect['extracted'] ?? null) ? $inspect['extracted'] : [];
        $fixable = $this->proposedFixes($model, $catalog, $extracted, $endpointId);

        if ($fields !== null) {
            $wanted = array_fill_keys($fields, true);
            $fixable = array_filter(
                $fixable,
                static fn (array $fix, string $key): bool => isset($wanted[$key]),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        $applied = [];
        $skipped = [];
        $changes = [];

        foreach ($fixable as $key => $fix) {
            if (! ($fix['writable'] ?? true)) {
                $skipped[] = $key;

                continue;
            }

            if ($key === 'pricing') {
                $fromUnit = $model->getAttribute('unit');
                $fromPrice = $model->getAttribute('unit_price');
                $model->setAttribute('unit', $fix['unit']);
                $model->setAttribute('unit_price', $fix['unit_price']);
                $changes['unit'] = ['from' => $fromUnit, 'to' => $fix['unit']];
                $changes['unit_price'] = ['from' => $fromPrice, 'to' => $fix['unit_price']];
                $applied[] = 'pricing';

                continue;
            }

            if (! Schema::hasColumn($model->getTable(), $key)) {
                $skipped[] = $key;

                continue;
            }

            $from = $model->getAttribute($key);
            $model->setAttribute($key, $fix['value']);
            $changes[$key] = ['from' => $from, 'to' => $fix['value']];
            $applied[] = $key;
        }

        if ($applied !== []) {
            $model->save();
            $this->bustCatalogCaches();
        }

        $missingColumns = [];
        foreach ($skipped as $key) {
            if ($key !== 'pricing' && ! Schema::hasColumn($model->getTable(), $key)) {
                $missingColumns[] = $key;
            }
        }

        return [
            'ok' => true,
            'applied' => $applied,
            'skipped' => $skipped,
            'missing_columns' => $missingColumns,
            'changes' => $changes,
        ];
    }

    /**
     * Map issue field labels from the compare UI to fixer keys.
     */
    public function mapIssueField(string $issueField): ?string
    {
        $field = strtolower(trim($issueField));

        return match (true) {
            str_contains($field, 'unit_price') || $field === 'pricing' => 'pricing',
            $field === 'unit' || str_contains($field, 'billing unit') => 'pricing',
            str_contains($field, 'status') => 'status',
            str_contains($field, 'max_duration') => 'max_duration',
            str_contains($field, 'duration') || str_contains($field, 'enums') => 'enums',
            str_contains($field, 'resolution') => 'resolutions',
            str_contains($field, 'aspect') => 'aspect_ratios',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $extracted
     * @return array<string, array<string, mixed>>
     */
    private function proposedFixes(Model $model, string $catalog, array $extracted, string $endpointId): array
    {
        $out = [];

        $falStatus = isset($extracted['status']) ? strtolower((string) $extracted['status']) : null;
        if ($falStatus !== null && $falStatus !== '') {
            $out['status'] = [
                'writable' => Schema::hasColumn($model->getTable(), 'status'),
                'value' => $falStatus,
            ];
        }

        $falPrice = isset($extracted['unit_price']) && is_numeric($extracted['unit_price'])
            ? (float) $extracted['unit_price']
            : null;
        $falUnit = isset($extracted['unit']) ? (string) $extracted['unit'] : null;
        if ($falPrice !== null || ($falUnit !== null && $falUnit !== '')) {
            if ($catalog === 'video') {
                $normalized = $this->videoPricingNormalizer->normalize($endpointId, $falUnit, $falPrice);
                $out['pricing'] = [
                    'writable' => true,
                    'unit' => $normalized['unit'],
                    'unit_price' => $normalized['unit_price'],
                ];
            } else {
                $out['pricing'] = [
                    'writable' => true,
                    'unit' => $falUnit !== null && $falUnit !== '' ? $falUnit : $model->getAttribute('unit'),
                    'unit_price' => $falPrice ?? $model->getAttribute('unit_price'),
                ];
            }
        }

        $durations = $this->normalizeStringList($extracted['durations'] ?? []);
        if ($durations !== [] && Schema::hasColumn($model->getTable(), 'enums')) {
            $out['enums'] = [
                'writable' => true,
                'value' => $durations,
            ];
        }

        $maxDuration = isset($extracted['max_duration']) && is_numeric($extracted['max_duration'])
            ? (int) $extracted['max_duration']
            : null;
        if ($maxDuration !== null && Schema::hasColumn($model->getTable(), 'max_duration')) {
            $out['max_duration'] = [
                'writable' => true,
                'value' => $maxDuration,
            ];
        }

        $aspects = $this->normalizeAspectList($extracted['aspect_ratios'] ?? []);
        if ($aspects !== [] && Schema::hasColumn($model->getTable(), 'aspect_ratios')) {
            $out['aspect_ratios'] = [
                'writable' => true,
                'value' => $aspects,
            ];
        }

        $resolutions = $this->normalizeResolutionListForCatalog($extracted['resolutions'] ?? [], $catalog);
        if ($resolutions !== [] && Schema::hasColumn($model->getTable(), 'resolutions')) {
            $out['resolutions'] = [
                'writable' => true,
                'value' => $resolutions,
            ];
        }

        return $out;
    }

    private function bustCatalogCaches(): void
    {
        foreach ([
            'text_to_image_models' => 'text_to_image_categories',
            'text_to_video_models' => 'text_to_video_categories',
        ] as $models => $categories) {
            Cache::forget("catalog.brands.v4.{$models}.{$categories}");
            Cache::forget("catalog.brands.v3.{$models}.{$categories}");
        }
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function normalizeStringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $v) {
            if (is_scalar($v) && (string) $v !== '') {
                $out[] = (string) $v;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function normalizeAspectList(mixed $values): array
    {
        $out = [];
        foreach ($this->normalizeStringList($values) as $v) {
            $out[] = $v === 'auto' ? 'auto' : $v;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  mixed  $values
     * @return list<string>
     */
    private function normalizeResolutionListForCatalog(mixed $values, string $catalog): array
    {
        $out = [];
        foreach ($this->normalizeStringList($values) as $v) {
            $lower = strtolower($v);
            if ($catalog === 'image') {
                $out[] = match ($lower) {
                    '1k', '1024', '1024p' => '1K',
                    '2k', '2048', '2048p' => '2K',
                    '4k', '4096', '4096p' => '4K',
                    default => preg_match('/^\d+k$/i', $v) ? strtoupper($v) : $v,
                };
            } else {
                $out[] = match ($lower) {
                    '4k', '2160p', '2160' => '4K',
                    '1080p', '1080' => '1080p',
                    '720p', '720' => '720p',
                    '480p', '480' => '480p',
                    default => $v,
                };
            }
        }

        return array_values(array_unique($out));
    }
}
