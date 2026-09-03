<?php

namespace App\Services;

use App\Models\FalPricingObservation;
use App\Models\FalPricingSyncRun;
use App\Services\Tools\ToolPricingTiers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ten-minute catalog publisher: fetch fal prices, validate, then atomically publish.
 */
class FalPricingSyncService
{
    public const VIDEO_TABLES = [
        'text_to_video_models',
        'image_to_video_models',
    ];

    public const ALL_TABLES = [
        'text_to_image_models',
        'text_to_video_models',
        'image_to_video_models',
        'text_to_voice_models',
        'text_to_music_models',
        'video_tools_models',
    ];

    private const BATCH_SIZE = 50;

    /**
     * @param  list<string>  $tables
     * @return array<string, mixed>
     */
    public function run(array $tables, bool $dryRun = false, bool $skipStatus = false, int $sleep = 2): array
    {
        $key = (string) config('services.fal.key');
        if ($key === '') {
            throw new \RuntimeException('FAL_KEY is not set');
        }

        $minCoverage = max(0.1, min(1.0, (float) config('credits.pricing_min_coverage', 1.0)));
        $maxRatio = max(1.1, (float) config('credits.pricing_max_change_ratio', 4.0));

        $run = FalPricingSyncRun::query()->create([
            'status' => 'running',
            'dry_run' => $dryRun,
            'tables' => $tables,
            'started_at' => now(),
        ]);

        $priced = 0;
        $priceFailed = 0;
        $deactivated = 0;
        $reactivated = 0;
        $quarantined = 0;
        $keptLastGood = 0;
        $published = 0;
        $requested = 0;
        $changeEvents = [];
        $publishQueue = [];

        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $select = ['id', 'endpoint_id', 'name', 'status', 'unit', 'unit_price'];
                if (Schema::hasColumn($table, 'unit_price_by_resolution')) {
                    $select[] = 'unit_price_by_resolution';
                }
                if (Schema::hasColumn($table, 'defaults')) {
                    $select[] = 'defaults';
                }
                if (Schema::hasColumn($table, 'status_missing_streak')) {
                    $select[] = 'status_missing_streak';
                }
                if (Schema::hasColumn($table, 'pricing_fetched_at')) {
                    $select[] = 'pricing_fetched_at';
                }

                $rows = DB::table($table)
                    ->whereNotNull('endpoint_id')
                    ->where('endpoint_id', '!=', '')
                    ->orderBy('id')
                    ->get($select);

                $requested += $rows->count();
                $chunks = $rows->chunk(self::BATCH_SIZE)->values();

                foreach ($chunks as $chunkIndex => $chunk) {
                    $endpointIds = $chunk->pluck('endpoint_id')->unique()->values()->all();
                    $statusMap = $skipStatus ? [] : $this->fetchStatusBatch($key, $endpointIds);
                    $priceMap = $this->fetchPricingBatch($key, $endpointIds);

                    foreach ($chunk as $row) {
                        $result = $this->decideRow($table, $row, $statusMap, $priceMap, $skipStatus, $maxRatio);
                        $this->recordObservation($run->id, $table, $row, $result);

                        if ($result['priced']) {
                            $priced++;
                        } else {
                            $priceFailed++;
                            $keptLastGood++;
                        }
                        if ($result['quarantine']) {
                            $quarantined++;
                        }
                        if (($result['changes']['status'] ?? null) === 'inactive') {
                            $deactivated++;
                        }
                        if (($result['changes']['status'] ?? null) === 'active') {
                            $reactivated++;
                        }
                        foreach ($result['events'] as $event) {
                            $changeEvents[] = $event;
                        }
                        if ($result['changes'] !== []) {
                            $publishQueue[] = [
                                'table' => $table,
                                'id' => (int) $row->id,
                                'changes' => $result['changes'],
                            ];
                        } elseif ($result['touch_fetched']) {
                            $touch = [];
                            if (Schema::hasColumn($table, 'pricing_fetched_at')) {
                                $touch['pricing_fetched_at'] = now();
                            }
                            if (Schema::hasColumn($table, 'pricing_checked_at')) {
                                $touch['pricing_checked_at'] = now();
                            }
                            if ($touch !== []) {
                                $publishQueue[] = [
                                    'table' => $table,
                                    'id' => (int) $row->id,
                                    'changes' => $touch,
                                ];
                            }
                        }
                    }

                    if ($sleep > 0 && $chunkIndex < $chunks->count() - 1) {
                        sleep($sleep);
                    }
                }
            }

            $coverage = $requested > 0 ? $priced / $requested : 0.0;
            $publishAllowed = $coverage + 1e-9 >= $minCoverage;

            if (! $publishAllowed) {
                $run->forceFill([
                    'status' => 'failed',
                    'requested' => $requested,
                    'observed' => $requested,
                    'priced' => $priced,
                    'published' => 0,
                    'quarantined' => $quarantined,
                    'kept_last_good' => $keptLastGood,
                    'deactivated' => 0,
                    'reactivated' => 0,
                    'coverage' => round($coverage, 4),
                    'error' => sprintf('Coverage %.2f below minimum %.2f — last-known-good prices kept.', $coverage, $minCoverage),
                    'finished_at' => now(),
                ])->save();

                Log::error('fal:sync-pricing aborted — coverage too low', [
                    'coverage' => $coverage,
                    'min' => $minCoverage,
                    'priced' => $priced,
                    'requested' => $requested,
                ]);

                return $this->summary($run->fresh(), $changeEvents, $priceFailed);
            }

            if (! $dryRun) {
                DB::transaction(function () use ($publishQueue, &$published) {
                    foreach ($publishQueue as $item) {
                        $item['changes']['updated_at'] = now();
                        DB::table($item['table'])->where('id', $item['id'])->update($item['changes']);
                        $published++;
                    }
                });

                if ($changeEvents !== [] && Schema::hasTable('model_change_logs')) {
                    $this->logChanges($changeEvents);
                }

                CatalogCache::forgetBrands();
            }

            $run->forceFill([
                'status' => 'success',
                'requested' => $requested,
                'observed' => $requested,
                'priced' => $priced,
                'published' => $dryRun ? 0 : $published,
                'quarantined' => $quarantined,
                'kept_last_good' => $keptLastGood,
                'deactivated' => $deactivated,
                'reactivated' => $reactivated,
                'coverage' => round($coverage, 4),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'requested' => $requested,
                'priced' => $priced,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();
            throw $e;
        }

        return $this->summary($run->fresh(), $changeEvents, $priceFailed);
    }

    /**
     * @param  array<string, string>|null  $statusMap
     * @param  array<string, array{unit: string|null, unit_price: float|null, currency: string|null, payload: array<string, mixed>}>  $priceMap
     * @return array{
     *   priced: bool,
     *   quarantine: bool,
     *   touch_fetched: bool,
     *   changes: array<string, mixed>,
     *   events: list<array<string, mixed>>,
     *   raw_unit: ?string,
     *   raw_price: ?float,
     *   currency: ?string,
     *   normalized_unit: ?string,
     *   normalized_price: ?float,
     *   fal_status: ?string,
     *   decision: string,
     *   anomaly: ?string,
     *   payload: ?array<string, mixed>
     * }
     */
    private function decideRow(
        string $table,
        object $row,
        ?array $statusMap,
        array $priceMap,
        bool $skipStatus,
        float $maxRatio,
    ): array {
        $endpointId = (string) $row->endpoint_id;
        $label = $row->name ?: $endpointId;
        $changes = [];
        $events = [];
        $priced = false;
        $quarantine = false;
        $touchFetched = false;
        $anomaly = null;
        $decision = 'keep';
        $normalizedUnit = null;
        $normalizedPrice = null;

        $price = $priceMap[$endpointId] ?? null;
        $rawUnit = is_array($price) ? ($price['unit'] ?? null) : null;
        $rawPrice = is_array($price) ? ($price['unit_price'] ?? null) : null;
        $currency = is_array($price) ? ($price['currency'] ?? null) : null;
        $payload = is_array($price) ? ($price['payload'] ?? null) : null;

        if (! $skipStatus && $statusMap !== null) {
            $status = $statusMap[$endpointId] ?? null;
            $streak = (int) ($row->status_missing_streak ?? 0);

            if ($status === null) {
                $streak++;
                if (Schema::hasColumn($table, 'status_missing_streak')) {
                    $changes['status_missing_streak'] = $streak;
                }
                if ($streak >= 2 && $row->status === 'active') {
                    $changes['status'] = 'inactive';
                    $events[] = $this->event($table, $endpointId, $label, 'status', $row->status, 'inactive');
                    $decision = 'deactivate_confirmed';
                }
            } else {
                if (Schema::hasColumn($table, 'status_missing_streak')) {
                    $changes['status_missing_streak'] = 0;
                }
                if ($status !== $row->status) {
                    $changes['status'] = $status;
                    $events[] = $this->event($table, $endpointId, $label, 'status', $row->status, $status);
                    $decision = $status === 'active' ? 'reactivate' : 'deactivate';
                }
            }
        }

        if ($price === null) {
            $anomaly = 'missing_price';
            $decision = $decision === 'keep' ? 'keep_last_good' : $decision;
        } elseif ($currency !== null && strtoupper((string) $currency) !== 'USD') {
            $anomaly = 'non_usd';
            $decision = 'keep_last_good';
        } else {
            [$unit, $unitPrice] = $this->normalize($table, $endpointId, $rawUnit, $rawPrice);
            $normalizedUnit = $unit;
            $normalizedPrice = $unitPrice;

            if ($unitPrice === null || $unitPrice <= 0) {
                $anomaly = 'invalid_price';
                $decision = 'keep_last_good';
            } else {
                $priced = true;
                $touchFetched = true;
                $oldPrice = $row->unit_price !== null ? (float) $row->unit_price : null;

                if ($oldPrice !== null && $oldPrice > 0) {
                    $ratio = $unitPrice / $oldPrice;
                    if ($ratio < (1 / $maxRatio) || $ratio > $maxRatio) {
                        $quarantine = true;
                        $anomaly = 'suspicious_change';
                        $decision = 'quarantine';
                        $priced = true;
                    }
                }

                if (! $quarantine) {
                    if ((string) $row->unit !== (string) $unit) {
                        $changes['unit'] = $unit;
                        $events[] = $this->event($table, $endpointId, $label, 'unit', $row->unit, $unit);
                    }

                    if ($oldPrice === null || round($oldPrice, 6) !== round($unitPrice, 6)) {
                        if ($table === 'video_tools_models') {
                            $this->applyToolPrice($row, $unitPrice, $changes, $events, $label);
                        } else {
                            $changes['unit_price'] = $unitPrice;
                            $events[] = $this->event($table, $endpointId, $label, 'unit_price', $oldPrice, $unitPrice);
                        }
                    }

                    if (Schema::hasColumn($table, 'pricing_fetched_at')) {
                        $changes['pricing_fetched_at'] = now();
                    }
                    if (Schema::hasColumn($table, 'pricing_checked_at')) {
                        $changes['pricing_checked_at'] = now();
                    }
                    if ($decision === 'keep') {
                        $decision = $changes === [] || (count($changes) === 1 && isset($changes['pricing_fetched_at']))
                            ? 'fresh'
                            : 'publish';
                    }
                }
            }
        }

        if (isset($changes['status_missing_streak']) && count($changes) === 1 && ! $touchFetched) {
            // streak-only write is still a change
        }

        return [
            'priced' => $priced,
            'quarantine' => $quarantine,
            'touch_fetched' => $touchFetched && ! $quarantine,
            'changes' => $quarantine ? array_intersect_key($changes, array_flip(['status', 'status_missing_streak'])) : $changes,
            'events' => $quarantine ? array_values(array_filter($events, fn ($e) => $e['field'] === 'status')) : $events,
            'raw_unit' => $rawUnit,
            'raw_price' => $rawPrice,
            'currency' => $currency,
            'normalized_unit' => $normalizedUnit,
            'normalized_price' => $normalizedPrice,
            'fal_status' => $statusMap[$endpointId] ?? null,
            'decision' => $decision,
            'anomaly' => $anomaly,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function recordObservation(int $runId, string $table, object $row, array $result): void
    {
        $rowData = [
            'sync_run_id' => $runId,
            'model_table' => $table,
            'model_id' => $row->id,
            'endpoint_id' => $row->endpoint_id,
            'name' => $row->name,
            'raw_unit' => $result['raw_unit'],
            'raw_price' => $result['raw_price'],
            'currency' => $result['currency'],
            'normalized_unit' => $result['normalized_unit'],
            'normalized_price' => $result['normalized_price'],
            'fal_status' => $result['fal_status'],
            'decision' => $result['decision'],
            'anomaly' => $result['anomaly'],
            'payload' => $result['payload'],
            'checked_at' => now(),
            'published_at' => $result['changes'] !== [] && ($result['decision'] === 'publish' || $result['decision'] === 'fresh')
                ? now()
                : null,
        ];

        if (Schema::hasColumn('fal_pricing_observations', 'observed_at')) {
            $rowData['observed_at'] = now();
        }
        if (Schema::hasColumn('fal_pricing_observations', 'status')) {
            $rowData['status'] = $result['anomaly'] ? 'anomaly' : 'valid';
        }
        if (Schema::hasColumn('fal_pricing_observations', 'raw_unit_price')) {
            $rowData['raw_unit_price'] = $result['raw_price'];
        }
        if (Schema::hasColumn('fal_pricing_observations', 'anomaly_reason')) {
            $rowData['anomaly_reason'] = $result['anomaly'];
        }
        if (Schema::hasColumn('fal_pricing_observations', 'raw_payload')) {
            $rowData['raw_payload'] = $result['payload'];
        }

        FalPricingObservation::query()->create($rowData);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<array<string, mixed>>  $events
     */
    private function applyToolPrice(object $row, float $unitPrice, array &$changes, array &$events, string $label): void
    {
        $defaults = $this->decodeDefaults($row->defaults ?? null);
        $oldPrice = $row->unit_price !== null ? (float) $row->unit_price : null;

        if (ToolPricingTiers::isTierScaleLocked($defaults)) {
            $this->applyLockedToolListPriceScale($row, $defaults ?? [], $unitPrice, $changes, $events, $label);

            return;
        }

        $changes['unit_price'] = $unitPrice;
        $events[] = $this->event('video_tools_models', (string) $row->endpoint_id, $label, 'unit_price', $oldPrice, $unitPrice);

        if ($oldPrice !== null && $oldPrice > 0) {
            $this->applyToolTierScale($row, $oldPrice, $unitPrice, $changes, $events, $label);
        }
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $changes
     * @param  list<array<string, mixed>>  $events
     */
    private function applyLockedToolListPriceScale(
        object $row,
        array $defaults,
        float $falListPrice,
        array &$changes,
        array &$events,
        string $label,
    ): void {
        $prevFal = isset($defaults['fal_list_unit_price']) && is_numeric($defaults['fal_list_unit_price'])
            ? (float) $defaults['fal_list_unit_price']
            : null;

        if ($prevFal === null || $prevFal <= 0) {
            $defaults['fal_list_unit_price'] = round($falListPrice, 6);
            $changes['defaults'] = json_encode($defaults, JSON_UNESCAPED_SLASHES);

            return;
        }

        if (abs($prevFal - $falListPrice) < 1e-9) {
            return;
        }

        $oldRowPrice = $row->unit_price !== null ? (float) $row->unit_price : null;
        $existing = ToolPricingTiers::normalize($row->unit_price_by_resolution ?? null);
        if ($existing === []) {
            $existing = ToolPricingTiers::hardcoded(
                (string) $row->endpoint_id,
                (string) ($row->unit ?? 'seconds'),
                $defaults,
            ) ?? [];
        }
        $scaledTiers = ToolPricingTiers::scale($existing, $prevFal, $falListPrice);
        if ($scaledTiers !== null && $scaledTiers !== []) {
            $changes['unit_price_by_resolution'] = json_encode($scaledTiers, JSON_UNESCAPED_SLASHES);
            $events[] = $this->event('video_tools_models', (string) $row->endpoint_id, $label, 'unit_price_by_resolution', 'locked-scaled', 'tiers');
        }

        if ($oldRowPrice !== null && $oldRowPrice > 0) {
            $scaledPrice = ToolPricingTiers::scale(['x' => $oldRowPrice], $prevFal, $falListPrice);
            if ($scaledPrice !== null) {
                $changes['unit_price'] = $scaledPrice['x'];
                $events[] = $this->event('video_tools_models', (string) $row->endpoint_id, $label, 'unit_price', $oldRowPrice, $scaledPrice['x']);
            }
        }

        $defaults['fal_list_unit_price'] = round($falListPrice, 6);
        $changes['defaults'] = json_encode($defaults, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<array<string, mixed>>  $events
     */
    private function applyToolTierScale(
        object $row,
        float $oldPrice,
        float $newPrice,
        array &$changes,
        array &$events,
        string $label,
    ): void {
        $defaults = $this->decodeDefaults($row->defaults ?? null);
        if (ToolPricingTiers::isTierScaleLocked($defaults)) {
            return;
        }

        $existing = ToolPricingTiers::normalize($row->unit_price_by_resolution ?? null);
        if ($existing === []) {
            $existing = ToolPricingTiers::hardcoded(
                (string) $row->endpoint_id,
                (string) ($changes['unit'] ?? $row->unit ?? 'seconds'),
                is_array($defaults) ? $defaults : [],
            ) ?? [];
        }

        $scaled = ToolPricingTiers::scale($existing, $oldPrice, $newPrice);
        if ($scaled !== null && $scaled !== []) {
            $changes['unit_price_by_resolution'] = json_encode($scaled, JSON_UNESCAPED_SLASHES);
            $events[] = $this->event(
                'video_tools_models',
                (string) $row->endpoint_id,
                $label,
                'unit_price_by_resolution',
                'scaled×'.round($newPrice / $oldPrice, 4),
                'tiers',
            );
        }

        if (
            is_array($defaults)
            && str_contains((string) $row->endpoint_id, 'kling-video/v2.5-turbo/pro/image-to-video')
            && is_array($defaults['pricing'] ?? null)
            && isset($defaults['pricing']['base_cost_usd'])
            && is_numeric($defaults['pricing']['base_cost_usd'])
        ) {
            $ratio = $newPrice / $oldPrice;
            if ($ratio >= 0.25 && $ratio <= 4.0) {
                $defaults['pricing']['base_cost_usd'] = round((float) $defaults['pricing']['base_cost_usd'] * $ratio, 6);
                $changes['defaults'] = json_encode($defaults, JSON_UNESCAPED_SLASHES);
            }
        }
    }

    /**
     * @param  list<string>  $endpointIds
     * @return array<string, string>|null
     */
    public function fetchStatusBatch(string $key, array $endpointIds): ?array
    {
        if ($endpointIds === []) {
            return [];
        }

        $models = $this->requestJson($key, 'https://api.fal.ai/v1/models?'.$this->idQuery($endpointIds), 'models');
        if ($models === null) {
            return null;
        }

        $map = [];
        $seen = [];
        foreach ($models as $model) {
            if (! is_array($model)) {
                continue;
            }
            $eid = $model['endpoint_id'] ?? null;
            if (! is_string($eid)) {
                continue;
            }
            $seen[$eid] = true;
            $status = $model['metadata']['status'] ?? null;
            if (! is_string($status) || $status === '') {
                continue;
            }
            $map[$eid] = strtolower($status) === 'active' ? 'active' : 'inactive';
        }

        foreach ($endpointIds as $eid) {
            if (! isset($seen[$eid]) && ! array_key_exists($eid, $map)) {
                // Omitted from a successful page — treat as unknown, not inactive.
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $endpointIds
     * @return array<string, array{unit: string|null, unit_price: float|null, currency: string|null, payload: array<string, mixed>}>
     */
    public function fetchPricingBatch(string $key, array $endpointIds): array
    {
        if ($endpointIds === []) {
            return [];
        }

        $map = [];
        $cursor = null;

        for ($page = 0; $page < 8; $page++) {
            $url = 'https://api.fal.ai/v1/models/pricing?'.$this->idQuery($endpointIds);
            if (is_string($cursor) && $cursor !== '') {
                $url .= '&cursor='.rawurlencode($cursor);
            }

            $body = $this->requestRaw($key, $url);
            if ($body === null) {
                return $map;
            }

            $prices = $body['prices'] ?? [];
            if (! is_array($prices)) {
                return $map;
            }

            foreach ($prices as $price) {
                if (! is_array($price)) {
                    continue;
                }
                $eid = $price['endpoint_id'] ?? null;
                if (! is_string($eid)) {
                    continue;
                }
                $map[$eid] = [
                    'unit' => isset($price['unit']) && is_string($price['unit']) ? $price['unit'] : null,
                    'unit_price' => isset($price['unit_price']) ? (float) $price['unit_price'] : null,
                    'currency' => isset($price['currency']) && is_string($price['currency']) ? $price['currency'] : 'USD',
                    'payload' => $price,
                ];
            }

            $hasMore = (bool) ($body['has_more'] ?? false);
            $cursor = $body['next_cursor'] ?? null;
            if (! $hasMore || ! is_string($cursor) || $cursor === '') {
                break;
            }
        }

        return $map;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function requestJson(string $key, string $url, string $listKey): ?array
    {
        $body = $this->requestRaw($key, $url);
        if ($body === null) {
            return null;
        }
        $list = $body[$listKey] ?? [];

        return is_array($list) ? $list : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestRaw(string $key, string $url): ?array
    {
        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Key '.$key,
                    'Accept' => 'application/json',
                ])->timeout(30)->connectTimeout(10)->get($url);
            } catch (Throwable $e) {
                Log::warning('fal pricing HTTP error', ['url' => $url, 'error' => $e->getMessage()]);
                if ($attempt < $maxRetries - 1) {
                    sleep($attempt + 1);
                    continue;
                }

                return null;
            }

            if ($response->successful()) {
                $json = $response->json();

                return is_array($json) ? $json : null;
            }

            if (in_array($response->status(), [429, 500, 502, 503, 504], true) && $attempt < $maxRetries - 1) {
                sleep(($attempt + 1) * ($response->status() === 429 ? 10 : 2));
                continue;
            }

            Log::warning('fal pricing HTTP failed', ['url' => $url, 'status' => $response->status()]);

            return null;
        }

        return null;
    }

    /**
     * @param  list<string>  $endpointIds
     */
    public function idQuery(array $endpointIds): string
    {
        return implode('&', array_map(
            static fn (string $id): string => 'endpoint_id='.rawurlencode($id),
            $endpointIds,
        ));
    }

    /**
     * @return array{0: string|null, 1: float|null}
     */
    public function normalize(string $table, string $endpointId, ?string $unit, ?float $unitPrice): array
    {
        if (in_array($table, self::VIDEO_TABLES, true)) {
            $normalized = app(FalVideoPricingNormalizer::class)->normalize($endpointId, $unit, $unitPrice);

            return [$normalized['unit'], $normalized['unit_price']];
        }

        return [
            $unit,
            $unitPrice !== null && $unitPrice > 0 ? round($unitPrice, 6) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeDefaults(mixed $defaults): ?array
    {
        if (is_array($defaults)) {
            return $defaults;
        }
        if (is_string($defaults) && $defaults !== '') {
            $decoded = json_decode($defaults, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * @return array{table: string, endpoint: string, name: string, field: string, old: mixed, new: mixed}
     */
    private function event(string $table, string $endpoint, string $name, string $field, mixed $old, mixed $new): array
    {
        return [
            'table' => $table,
            'endpoint' => $endpoint,
            'name' => $name,
            'field' => $field,
            'old' => $old,
            'new' => $new,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function logChanges(array $events): void
    {
        $now = now();
        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                'model_table' => $event['table'],
                'endpoint_id' => $event['endpoint'],
                'name' => $event['name'],
                'field' => $event['field'],
                'old_value' => $event['old'] === null ? null : (string) $event['old'],
                'new_value' => $event['new'] === null ? null : (string) $event['new'],
                'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('model_change_logs')->insert($chunk);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function summary(FalPricingSyncRun $run, array $events, int $priceFailed): array
    {
        $tables = is_array($run->tables) ? $run->tables : [];
        $activeCounts = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $active = DB::table($table)->where('status', 'active')->count();
            $total = DB::table($table)->whereNotNull('endpoint_id')->where('endpoint_id', '!=', '')->count();
            $activeCounts[$table] = ['active' => $active, 'inactive' => max(0, $total - $active)];
        }

        return [
            'run' => $run,
            'priced' => (int) $run->priced,
            'price_failed' => $priceFailed,
            'deactivated' => (int) $run->deactivated,
            'reactivated' => (int) $run->reactivated,
            'quarantined' => (int) $run->quarantined,
            'kept_last_good' => (int) $run->kept_last_good,
            'coverage' => $run->coverage !== null ? (float) $run->coverage : 0.0,
            'total_active' => array_sum(array_column($activeCounts, 'active')),
            'total_inactive' => array_sum(array_column($activeCounts, 'inactive')),
            'active_counts' => $activeCounts,
            'duration' => $run->started_at && $run->finished_at
                ? round($run->started_at->diffInSeconds($run->finished_at, true), 1)
                : 0,
            'dry_run' => (bool) $run->dry_run,
            'failed' => $run->status === 'failed',
            'error' => $run->error,
            'events' => $events,
        ];
    }

    public function latestSuccessfulRun(): ?FalPricingSyncRun
    {
        return FalPricingSyncRun::query()
            ->where('status', 'success')
            ->where('dry_run', false)
            ->orderByDesc('id')
            ->first();
    }
}
