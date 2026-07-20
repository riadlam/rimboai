<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live Fal model inspector for admin compare screens.
 * Fetches status + pricing + OpenAPI and extracts comparable capability fields.
 */
class FalModelInspector
{
    private string $key;

    private string $base = 'https://api.fal.ai/v1';

    public function __construct()
    {
        $this->key = (string) config('services.fal.key', '');
    }

    public function configured(): bool
    {
        return $this->key !== '';
    }

    /**
     * Lightweight mismatch summary for lab list tables (cached).
     *
     * @param  array<string, mixed>  $dbRow
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   total: int,
     *   high: int,
     *   medium: int,
     *   info: int,
     *   label: string,
     *   color: string
     * }
     */
    public function mismatchSummary(string $endpointId, array $dbRow, string $catalog, bool $fresh = false, bool $fetchIfMissing = true): array
    {
        $endpointId = trim($endpointId);
        if ($endpointId === '') {
            return [
                'ok' => false,
                'error' => 'No endpoint',
                'total' => 0,
                'high' => 0,
                'medium' => 0,
                'info' => 0,
                'label' => '—',
                'color' => 'gray',
            ];
        }

        $fingerprint = md5($endpointId.'|'.$catalog.'|'.json_encode([
            $dbRow['unit'] ?? null,
            $dbRow['unit_price'] ?? null,
            $dbRow['status'] ?? null,
            $dbRow['max_duration'] ?? null,
            $dbRow['enums'] ?? null,
            $dbRow['aspect_ratios'] ?? null,
            $dbRow['resolutions'] ?? null,
            $dbRow['updated_at'] ?? null,
        ]));
        $cacheKey = 'fal.model.mismatch.v2.'.$fingerprint;

        if (! $fresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if (! $fetchIfMissing && ! $fresh) {
            return [
                'ok' => true,
                'total' => 0,
                'high' => 0,
                'medium' => 0,
                'info' => 0,
                'label' => 'Scan',
                'color' => 'gray',
            ];
        }

        $inspect = $this->inspect($endpointId, $fresh);
        if (! ($inspect['ok'] ?? false)) {
            $summary = [
                'ok' => false,
                'error' => (string) ($inspect['error'] ?? 'Fal error'),
                'total' => 0,
                'high' => 0,
                'medium' => 0,
                'info' => 0,
                'label' => 'Fal error',
                'color' => 'danger',
            ];
            Cache::put($cacheKey, $summary, now()->addMinutes(5));

            return $summary;
        }

        $issues = $this->diff(
            is_array($inspect['extracted'] ?? null) ? $inspect['extracted'] : [],
            $dbRow,
            $catalog,
        );

        $high = 0;
        $medium = 0;
        $info = 0;
        foreach ($issues as $issue) {
            match ($issue['severity'] ?? 'info') {
                'high' => $high++,
                'medium' => $medium++,
                default => $info++,
            };
        }

        $total = count($issues);
        if ($total === 0) {
            $summary = [
                'ok' => true,
                'total' => 0,
                'high' => 0,
                'medium' => 0,
                'info' => 0,
                'label' => 'OK',
                'color' => 'success',
            ];
        } elseif ($high > 0) {
            $summary = [
                'ok' => true,
                'total' => $total,
                'high' => $high,
                'medium' => $medium,
                'info' => $info,
                'label' => $high.' high'.($medium > 0 ? " · {$medium} med" : ''),
                'color' => 'danger',
            ];
        } elseif ($medium > 0) {
            $summary = [
                'ok' => true,
                'total' => $total,
                'high' => 0,
                'medium' => $medium,
                'info' => $info,
                'label' => $medium.' medium',
                'color' => 'warning',
            ];
        } else {
            $summary = [
                'ok' => true,
                'total' => $total,
                'high' => 0,
                'medium' => 0,
                'info' => $info,
                'label' => $info.' note'.($info === 1 ? '' : 's'),
                'color' => 'info',
            ];
        }

        Cache::put($cacheKey, $summary, now()->addMinutes(15));

        return $summary;
    }

    /**
     * @return array{
     *   ok: bool,
     *   error?: string,
     *   endpoint_id: string,
     *   model: array<string, mixed>|null,
     *   pricing: array{unit: string|null, unit_price: float|null}|null,
     *   openapi: array<string, mixed>|null,
     *   extracted: array<string, mixed>,
     *   raw: array<string, mixed>
     * }
     */
    public function inspect(string $endpointId, bool $fresh = false): array
    {
        $endpointId = trim($endpointId);
        $empty = [
            'ok' => false,
            'endpoint_id' => $endpointId,
            'model' => null,
            'pricing' => null,
            'openapi' => null,
            'extracted' => [],
            'raw' => [],
        ];

        if ($endpointId === '') {
            return array_merge($empty, ['error' => 'Missing endpoint_id']);
        }

        if (! $this->configured()) {
            return array_merge($empty, ['error' => 'FAL_KEY is not set']);
        }

        $cacheKey = 'fal.model.inspect.v2.'.md5($endpointId);

        if (! $fresh) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $modelPayload = $this->fetchModel($endpointId);
            $pricing = $this->fetchPricing($endpointId);

            if ($modelPayload === null) {
                $result = array_merge($empty, [
                    'error' => 'Fal did not return this endpoint (missing or private)',
                    'pricing' => $pricing,
                ]);
                Cache::put($cacheKey, $result, now()->addMinutes(5));

                return $result;
            }

            $openapi = is_array($modelPayload['openapi'] ?? null) ? $modelPayload['openapi'] : null;
            $extracted = $this->extractCapabilities($endpointId, $modelPayload, $openapi, $pricing);

            $result = [
                'ok' => true,
                'endpoint_id' => $endpointId,
                'model' => $this->summarizeModel($modelPayload),
                'pricing' => $pricing,
                'openapi' => $openapi,
                'extracted' => $extracted,
                'raw' => [
                    'model' => $modelPayload,
                    'pricing' => $pricing,
                ],
            ];

            Cache::put($cacheKey, $result, now()->addMinutes(15));

            return $result;
        } catch (Throwable $e) {
            report($e);
            Log::warning('FalModelInspector failed', [
                'endpoint_id' => $endpointId,
                'error' => $e->getMessage(),
            ]);

            return array_merge($empty, ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchModel(string $endpointId): ?array
    {
        $response = Http::timeout(45)
            ->withHeaders($this->headers())
            ->get("{$this->base}/models", [
                'endpoint_id' => $endpointId,
                'expand' => 'openapi-3.0',
            ]);

        if (! $response->successful()) {
            Log::warning('Fal model fetch failed', [
                'endpoint_id' => $endpointId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $models = $response->json('models', []);
        if (! is_array($models) || $models === [] || ! is_array($models[0] ?? null)) {
            return null;
        }

        return $models[0];
    }

    /**
     * @return array{unit: string|null, unit_price: float|null}|null
     */
    private function fetchPricing(string $endpointId): ?array
    {
        $response = Http::timeout(30)
            ->withHeaders($this->headers())
            ->get("{$this->base}/models/pricing", [
                'endpoint_id' => $endpointId,
            ]);

        if (! $response->successful()) {
            return null;
        }

        $prices = $response->json('prices', []);
        if (! is_array($prices) || $prices === [] || ! is_array($prices[0] ?? null)) {
            return null;
        }

        $row = $prices[0];

        return [
            'unit' => isset($row['unit']) && is_string($row['unit']) ? $row['unit'] : null,
            'unit_price' => isset($row['unit_price']) && is_numeric($row['unit_price']) ? (float) $row['unit_price'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $model
     * @param  array<string, mixed>|null  $openapi
     * @param  array{unit: string|null, unit_price: float|null}|null  $pricing
     * @return array<string, mixed>
     */
    private function extractCapabilities(string $endpointId, array $model, ?array $openapi, ?array $pricing): array
    {
        $enums = $this->collectEnums($openapi);
        $metadata = is_array($model['metadata'] ?? null) ? $model['metadata'] : [];

        $aspect = $this->pickEnum($enums, ['aspect_ratio', 'aspectRatio', 'aspect']);
        $resolution = $this->pickEnum($enums, ['resolution', 'image_size', 'imageSize', 'size', 'output_resolution']);
        $duration = $this->pickEnum($enums, ['duration', 'duration_seconds', 'video_length']);
        $numImages = $this->pickEnum($enums, ['num_images', 'n', 'quantity']);

        $maxDuration = null;
        if ($duration !== []) {
            $numeric = array_values(array_filter($duration, static fn ($v) => is_numeric($v)));
            if ($numeric !== []) {
                $maxDuration = (int) max(array_map('floatval', $numeric));
            }
        }

        $unitRaw = $pricing['unit'] ?? null;
        $unitPrice = isset($pricing['unit_price']) && is_numeric($pricing['unit_price'])
            ? (float) $pricing['unit_price']
            : null;
        $unit = $unitRaw;

        // Fal often returns opaque unit="units" for Seedance; our DB stores tokens_per_1000.
        if ($this->shouldNormalizeVideoPricing($endpointId)) {
            $normalized = app(FalVideoPricingNormalizer::class)->normalize(
                $endpointId,
                is_string($unitRaw) ? $unitRaw : null,
                $unitPrice,
            );
            $unit = $normalized['unit'];
            $unitPrice = $normalized['unit_price'];
        }

        return [
            'status' => isset($metadata['status']) ? strtolower((string) $metadata['status']) : null,
            'category' => $metadata['category'] ?? ($model['category'] ?? null),
            'description' => $model['description'] ?? ($metadata['description'] ?? null),
            'unit_raw' => $unitRaw,
            'unit' => $unit,
            'unit_price' => $unitPrice,
            'aspect_ratios' => $aspect,
            'resolutions' => $resolution,
            'durations' => $duration,
            'max_duration' => $maxDuration,
            'quantities' => $numImages,
            'input_properties' => array_keys($enums),
            'all_enums' => $enums,
            'supports_audio_hint' => $this->propertyExists($openapi, ['generate_audio', 'with_audio', 'audio', 'enable_audio']),
            'has_image_url_input' => $this->propertyExists($openapi, ['image_url', 'image_urls', 'start_image_url', 'end_image_url']),
        ];
    }

    private function shouldNormalizeVideoPricing(string $endpointId): bool
    {
        $id = strtolower($endpointId);

        return str_contains($id, 'seedance')
            || str_contains($id, 'text-to-video')
            || str_contains($id, 'image-to-video')
            || str_contains($id, 'reference-to-video')
            || str_contains($id, 'kling')
            || str_contains($id, 'veo')
            || str_contains($id, 'minimax')
            || str_contains($id, 'wan/')
            || str_contains($id, 'sora')
            || str_contains($id, 'grok-');
    }

    /**
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>
     */
    private function summarizeModel(array $model): array
    {
        $metadata = is_array($model['metadata'] ?? null) ? $model['metadata'] : [];

        return [
            'endpoint_id' => $model['endpoint_id'] ?? null,
            'title' => $model['title'] ?? ($model['name'] ?? null),
            'category' => $metadata['category'] ?? ($model['category'] ?? null),
            'status' => $metadata['status'] ?? null,
            'description' => $model['description'] ?? ($metadata['description'] ?? null),
            'thumbnail_url' => $model['thumbnail_url'] ?? ($metadata['thumbnail_url'] ?? null),
            'tags' => $model['tags'] ?? ($metadata['tags'] ?? null),
            'metadata' => $metadata,
        ];
    }

    /**
     * Flatten OpenAPI property enums keyed by property name.
     *
     * @param  array<string, mixed>|null  $openapi
     * @return array<string, list<mixed>>
     */
    private function collectEnums(?array $openapi): array
    {
        if ($openapi === null) {
            return [];
        }

        $schemas = $openapi['components']['schemas'] ?? [];
        if (! is_array($schemas)) {
            return [];
        }

        $out = [];

        foreach ($schemas as $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $properties = $schema['properties'] ?? null;
            if (! is_array($properties)) {
                continue;
            }

            foreach ($properties as $name => $prop) {
                if (! is_string($name) || ! is_array($prop)) {
                    continue;
                }
                $values = $this->enumFromProperty($prop);
                if ($values === []) {
                    continue;
                }
                if (! isset($out[$name]) || count($values) > count($out[$name])) {
                    $out[$name] = $values;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $prop
     * @return list<mixed>
     */
    private function enumFromProperty(array $prop): array
    {
        if (isset($prop['enum']) && is_array($prop['enum']) && $prop['enum'] !== []) {
            return array_values($prop['enum']);
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $key) {
            $branches = $prop[$key] ?? null;
            if (! is_array($branches)) {
                continue;
            }
            foreach ($branches as $branch) {
                if (is_array($branch) && isset($branch['enum']) && is_array($branch['enum']) && $branch['enum'] !== []) {
                    return array_values($branch['enum']);
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string, list<mixed>>  $enums
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private function pickEnum(array $enums, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($enums[$key]) && $enums[$key] !== []) {
                return $enums[$key];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>|null  $openapi
     * @param  list<string>  $names
     */
    private function propertyExists(?array $openapi, array $names): bool
    {
        if ($openapi === null) {
            return false;
        }
        $schemas = $openapi['components']['schemas'] ?? [];
        if (! is_array($schemas)) {
            return false;
        }

        foreach ($schemas as $schema) {
            $properties = is_array($schema) ? ($schema['properties'] ?? null) : null;
            if (! is_array($properties)) {
                continue;
            }
            foreach ($names as $name) {
                if (array_key_exists($name, $properties)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Hardcoded Lab UI option sets (not per-model in the frontend today).
     *
     * @return array{resolutions: list<string>, aspect_ratios: list<string>, note: string}
     */
    public function labUiOptions(string $catalog): array
    {
        return match ($catalog) {
            'image' => [
                'resolutions' => ['1K', '2K', '4K'],
                'aspect_ratios' => ['1:1', '16:9', '9:16', '4:5', '3:4'],
                'note' => 'From ImageLabCreateForm (same chips for every image model)',
            ],
            'video' => [
                'resolutions' => ['720p', '1080p', '4K'],
                'aspect_ratios' => ['16:9', '9:16', '1:1', '4:5', '3:4'],
                'note' => 'From VideoLabCreateForm (same chips for every video model)',
            ],
            default => [
                'resolutions' => [],
                'aspect_ratios' => [],
                'note' => 'Voice/Music rely mostly on DB enums / duration fields',
            ],
        };
    }

    /**
     * Build mismatch list between Fal extracted capabilities and a DB row (+ Lab UI options).
     *
     * @param  array<string, mixed>  $extracted
     * @param  array<string, mixed>  $db
     * @return list<array{field: string, severity: string, fal: mixed, db: mixed, note: string}>
     */
    public function diff(array $extracted, array $db, string $catalog = ''): array
    {
        $issues = [];

        $falStatus = isset($extracted['status']) ? strtolower((string) $extracted['status']) : null;
        $dbStatus = isset($db['status']) ? strtolower((string) $db['status']) : null;
        if ($falStatus && $dbStatus && $falStatus !== $dbStatus) {
            $issues[] = [
                'field' => 'status',
                'severity' => 'high',
                'fal' => $falStatus,
                'db' => $dbStatus,
                'note' => 'Availability mismatch',
                'fixable' => true,
            ];
        }

        $falUnitRaw = isset($extracted['unit_raw'])
            ? strtolower((string) $extracted['unit_raw'])
            : (isset($extracted['unit']) ? strtolower((string) $extracted['unit']) : null);
        $falUnit = isset($extracted['unit']) ? strtolower((string) $extracted['unit']) : null;
        $dbUnit = isset($db['unit']) ? strtolower((string) $db['unit']) : null;
        if ($falUnit && $dbUnit && $falUnit !== $dbUnit) {
            $issues[] = [
                'field' => 'unit',
                'severity' => 'high',
                'fal' => $falUnit.($falUnitRaw && $falUnitRaw !== $falUnit ? " (fal raw: {$falUnitRaw})" : ''),
                'db' => $dbUnit,
                'note' => 'Billing unit mismatch after Fal→Rimbo normalization',
                'fixable' => true,
            ];
        }

        $falPrice = isset($extracted['unit_price']) && is_numeric($extracted['unit_price']) ? (float) $extracted['unit_price'] : null;
        $dbPrice = isset($db['unit_price']) && is_numeric($db['unit_price']) ? (float) $db['unit_price'] : null;
        if ($falPrice !== null && $dbPrice !== null && abs($falPrice - $dbPrice) > 0.000001) {
            $issues[] = [
                'field' => 'unit_price',
                'severity' => 'high',
                'fal' => $falPrice,
                'db' => $dbPrice,
                'note' => 'Price differs from Fal',
                'fixable' => true,
            ];
        }

        $falDurations = $this->normalizeStringList($extracted['durations'] ?? []);
        $dbEnums = $this->normalizeStringList($db['enums'] ?? []);
        if ($falDurations !== [] && $dbEnums !== []) {
            $extraInDb = array_values(array_diff($dbEnums, $falDurations));
            $missingInDb = array_values(array_diff($falDurations, $dbEnums));
            if ($extraInDb !== []) {
                $issues[] = [
                    'field' => 'enums / durations',
                    'severity' => 'high',
                    'fal' => $falDurations,
                    'db' => $dbEnums,
                    'note' => 'DB has duration values Fal does not advertise: '.implode(', ', $extraInDb),
                    'fixable' => true,
                ];
            }
            if ($missingInDb !== []) {
                $issues[] = [
                    'field' => 'enums / durations',
                    'severity' => 'medium',
                    'fal' => $falDurations,
                    'db' => $dbEnums,
                    'note' => 'Fal has duration values missing in DB: '.implode(', ', $missingInDb),
                    'fixable' => true,
                ];
            }
        } elseif ($falDurations !== [] && $dbEnums === []) {
            $issues[] = [
                'field' => 'enums / durations',
                'severity' => 'info',
                'fal' => $falDurations,
                'db' => null,
                'note' => 'DB enums empty — sync Fal durations',
                'fixable' => true,
            ];
        }

        $falMax = isset($extracted['max_duration']) && is_numeric($extracted['max_duration']) ? (int) $extracted['max_duration'] : null;
        $dbMax = isset($db['max_duration']) && is_numeric($db['max_duration']) ? (int) $db['max_duration'] : null;
        if ($falMax !== null && $dbMax !== null && $falMax !== $dbMax) {
            $issues[] = [
                'field' => 'max_duration',
                'severity' => 'medium',
                'fal' => $falMax,
                'db' => $dbMax,
                'note' => 'Max duration mismatch',
                'fixable' => true,
            ];
        } elseif ($falMax !== null && $dbMax === null) {
            $issues[] = [
                'field' => 'max_duration',
                'severity' => 'info',
                'fal' => $falMax,
                'db' => null,
                'note' => 'DB max_duration missing — sync from Fal',
                'fixable' => true,
            ];
        }

        $ui = $catalog !== '' ? $this->labUiOptions($catalog) : null;
        $dbAspects = $this->normalizeStringList($db['aspect_ratios'] ?? []);
        $dbResolutions = $this->normalizeResolutionList($db['resolutions'] ?? []);
        $uiRes = $dbResolutions !== [] ? $dbResolutions : $this->normalizeResolutionList($ui['resolutions'] ?? []);
        $uiAspect = $dbAspects !== [] ? $dbAspects : $this->normalizeStringList($ui['aspect_ratios'] ?? []);
        $oursSource = $dbResolutions !== [] || $dbAspects !== [] ? 'DB' : 'Lab UI defaults';

        $falRes = $this->normalizeResolutionList($extracted['resolutions'] ?? []);
        if ($falRes !== [] && $uiRes !== []) {
            $extraInUi = array_values(array_diff($uiRes, $falRes));
            $missingInUi = array_values(array_diff($falRes, $uiRes));
            if ($extraInUi !== []) {
                $issues[] = [
                    'field' => 'resolutions',
                    'severity' => 'high',
                    'fal' => $falRes,
                    'db' => $uiRes,
                    'note' => "{$oursSource} offers resolutions Fal does not list: ".implode(', ', $extraInUi),
                    'fixable' => true,
                ];
            }
            if ($missingInUi !== []) {
                $issues[] = [
                    'field' => 'resolutions',
                    'severity' => 'medium',
                    'fal' => $falRes,
                    'db' => $uiRes,
                    'note' => "Fal supports resolutions {$oursSource} does not show: ".implode(', ', $missingInUi),
                    'fixable' => true,
                ];
            }
        } elseif ($falRes !== []) {
            $issues[] = [
                'field' => 'resolutions',
                'severity' => 'info',
                'fal' => $falRes,
                'db' => $uiRes !== [] ? $uiRes : null,
                'note' => 'Sync Fal resolutions into DB so Lab UI can show only supported options',
                'fixable' => true,
            ];
        }

        $falAspect = $this->normalizeStringList($extracted['aspect_ratios'] ?? []);
        if ($falAspect !== [] && $uiAspect !== []) {
            $extraInUi = array_values(array_diff($uiAspect, $falAspect));
            $missingInUi = array_values(array_diff($falAspect, $uiAspect));
            if ($extraInUi !== []) {
                $issues[] = [
                    'field' => 'aspect_ratios',
                    'severity' => 'high',
                    'fal' => $falAspect,
                    'db' => $uiAspect,
                    'note' => "{$oursSource} offers aspect ratios Fal does not list: ".implode(', ', $extraInUi),
                    'fixable' => true,
                ];
            }
            if ($missingInUi !== []) {
                $issues[] = [
                    'field' => 'aspect_ratios',
                    'severity' => 'medium',
                    'fal' => $falAspect,
                    'db' => $uiAspect,
                    'note' => "Fal supports aspect ratios {$oursSource} does not show: ".implode(', ', $missingInUi),
                    'fixable' => true,
                ];
            }
        } elseif ($falAspect !== []) {
            $issues[] = [
                'field' => 'aspect_ratios',
                'severity' => 'info',
                'fal' => $falAspect,
                'db' => $uiAspect !== [] ? $uiAspect : null,
                'note' => 'Sync Fal aspect ratios into DB so Lab UI can show only supported options',
                'fixable' => true,
            ];
        }

        return $issues;
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
            if (is_scalar($v)) {
                $out[] = (string) $v;
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * Normalize resolution labels so "4K" and "4k" compare equal.
     *
     * @param  mixed  $values
     * @return list<string>
     */
    private function normalizeResolutionList(mixed $values): array
    {
        $out = [];
        foreach ($this->normalizeStringList($values) as $v) {
            $lower = strtolower($v);
            $out[] = match ($lower) {
                '4k' => '4k',
                '1080p', '1080' => '1080p',
                '720p', '720' => '720p',
                '480p', '480' => '480p',
                '1k' => '1k',
                '2k' => '2k',
                default => $lower,
            };
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Key '.$this->key,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }
}
