<?php

namespace App\Console\Commands;

use App\Services\FalVideoPricingNormalizer;
use App\Services\TelegramNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Refresh unit/unit_price for EVERY endpoint in EVERY model table from fal.
 *
 * Designed to run from cron. Never called on the user request path — generation
 * reads pricing from the DB only (see FalPricingService).
 */
class FalSyncPricing extends Command
{
    protected $signature = 'fal:sync-pricing
        {--table= : Limit to a single model table}
        {--sleep=2 : Seconds to wait between fal batch requests (50 models per batch)}
        {--skip-status : Only sync pricing, do not touch status}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Sync unit, unit_price and active/inactive status for all models across all tables from fal';

    /**
     * Video tables get pricing run through the video normalizer (Seedance tokens,
     * per-second labelling, etc). Other tables store fal values as-is.
     *
     * @var list<string>
     */
    private const VIDEO_TABLES = [
        'text_to_video_models',
        'image_to_video_models',
    ];

    /**
     * fal accepts 1-50 endpoint IDs per pricing/status request. Batching keeps
     * us well under the rate limit (2 requests per 50 models instead of 100).
     */
    private const BATCH_SIZE = 50;

    /** @var list<string> */
    private const ALL_TABLES = [
        'text_to_image_models',
        'text_to_video_models',
        'image_to_video_models',
        'text_to_voice_models',
        'text_to_music_models',
        'video_tools_models',
    ];

    public function handle(): int
    {
        $key = config('services.fal.key');

        if (! $key) {
            $this->error('FAL_KEY is not set in .env');
            Log::error('fal:sync-pricing aborted — FAL_KEY is not set');

            return self::FAILURE;
        }

        $sleep = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');
        $skipStatus = (bool) $this->option('skip-status');
        $only = $this->option('table');

        $tables = $only ? [$only] : self::ALL_TABLES;

        $startedAt = microtime(true);
        Log::info('fal:sync-pricing started', [
            'tables' => $tables,
            'dry_run' => $dryRun,
            'skip_status' => $skipStatus,
        ]);

        $priced = 0;
        $priceFailed = 0;
        $deactivated = 0;
        $reactivated = 0;

        // Every detected change (status/unit/unit_price) for the Telegram report + audit log.
        $changeEvents = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Skipping missing table: {$table}");

                continue;
            }

            $rows = DB::table($table)
                ->whereNotNull('endpoint_id')
                ->where('endpoint_id', '!=', '')
                ->orderBy('id')
                ->get(['endpoint_id', 'name', 'status', 'unit', 'unit_price']);

            $count = $rows->count();
            $this->info("[{$table}] syncing {$count} endpoints...");

            // fal allows 1-50 endpoint IDs per request. Batch to avoid rate limits.
            $chunks = $rows->chunk(self::BATCH_SIZE)->values();

            foreach ($chunks as $chunkIndex => $chunk) {
                $endpointIds = $chunk->pluck('endpoint_id')->all();

                // One request for status, one for pricing — for the whole chunk.
                $statusMap = $skipStatus ? [] : $this->fetchStatusBatch($key, $endpointIds);
                $priceMap = $this->fetchPricingBatch($key, $endpointIds);

                foreach ($chunk as $row) {
                    $endpointId = $row->endpoint_id;
                    $label = $row->name ?: $endpointId;
                    $changes = [];

                    // 1) Status: only change when fal gives a definitive answer.
                    // null (transient failure or unknown) => keep current status.
                    if (! $skipStatus && $statusMap !== null) {
                        $status = $statusMap[$endpointId] ?? null;

                        if ($status !== null && $status !== $row->status) {
                            $changes['status'] = $status;

                            $changeEvents[] = [
                                'table' => $table,
                                'endpoint' => $endpointId,
                                'name' => $label,
                                'field' => 'status',
                                'old' => $row->status,
                                'new' => $status,
                            ];

                            if ($status === 'active') {
                                $reactivated++;
                            } else {
                                $deactivated++;
                            }
                        }
                    }

                    // 2) Pricing — only recorded when it actually changed.
                    $price = $priceMap[$endpointId] ?? null;

                    if ($price === null) {
                        $priceFailed++;
                        $this->warn("  ! {$endpointId}: no pricing returned");
                    } else {
                        [$unit, $unitPrice] = $this->normalize($table, $endpointId, $price['unit'], $price['unit_price']);

                        if ($unitPrice === null || $unitPrice <= 0) {
                            $priceFailed++;
                            $this->warn("  ! {$endpointId}: invalid price ({$price['unit_price']})");
                        } else {
                            $priced++;

                            $oldUnit = $row->unit;
                            $oldPrice = $row->unit_price !== null ? (float) $row->unit_price : null;

                            if ((string) $oldUnit !== (string) $unit) {
                                $changes['unit'] = $unit;
                                $changeEvents[] = [
                                    'table' => $table,
                                    'endpoint' => $endpointId,
                                    'name' => $label,
                                    'field' => 'unit',
                                    'old' => $oldUnit,
                                    'new' => $unit,
                                ];
                            }

                            if ($oldPrice === null || round($oldPrice, 6) !== round($unitPrice, 6)) {
                                $changes['unit_price'] = $unitPrice;
                                $changeEvents[] = [
                                    'table' => $table,
                                    'endpoint' => $endpointId,
                                    'name' => $label,
                                    'field' => 'unit_price',
                                    'old' => $oldPrice,
                                    'new' => $unitPrice,
                                ];
                            }
                        }
                    }

                    if ($changes === []) {
                        // nothing definitive to write
                    } elseif ($dryRun) {
                        $this->line("  ~ {$endpointId}: " . $this->describe($changes) . ' (dry-run)');
                    } else {
                        $changes['updated_at'] = now();
                        DB::table($table)->where('endpoint_id', $endpointId)->update($changes);
                        $this->line("  + {$endpointId}: " . $this->describe($changes));
                    }
                }

                // Breathe between batches (not after the final chunk).
                if ($sleep > 0 && $chunkIndex < $chunks->count() - 1) {
                    sleep($sleep);
                }
            }
        }

        $durationSeconds = round(microtime(true) - $startedAt, 1);

        // Snapshot current active/inactive counts per table.
        $activeCounts = $this->activeCounts($tables);
        $totalActive = array_sum(array_column($activeCounts, 'active'));
        $totalInactive = array_sum(array_column($activeCounts, 'inactive'));

        $this->newLine();
        $this->info(sprintf(
            'Done. priced=%d price_failed=%d deactivated=%d reactivated=%d changes=%d active=%d%s',
            $priced,
            $priceFailed,
            $deactivated,
            $reactivated,
            count($changeEvents),
            $totalActive,
            $dryRun ? ' (dry-run: nothing written)' : '',
        ));

        Log::info('fal:sync-pricing finished — cron job is working', [
            'priced' => $priced,
            'price_failed' => $priceFailed,
            'deactivated' => $deactivated,
            'reactivated' => $reactivated,
            'changes' => count($changeEvents),
            'active' => $totalActive,
            'duration_seconds' => $durationSeconds,
            'dry_run' => $dryRun,
        ]);

        // Persist the audit trail (skip on dry-run — nothing was written to models).
        if (! $dryRun && $changeEvents !== [] && Schema::hasTable('model_change_logs')) {
            $this->logChanges($changeEvents);
        }

        // Report the run result to Telegram.
        $this->reportToTelegram([
            'priced' => $priced,
            'price_failed' => $priceFailed,
            'deactivated' => $deactivated,
            'reactivated' => $reactivated,
            'total_active' => $totalActive,
            'total_inactive' => $totalInactive,
            'active_counts' => $activeCounts,
            'duration' => $durationSeconds,
            'dry_run' => $dryRun,
            'events' => $changeEvents,
        ]);

        return self::SUCCESS;
    }

    /**
     * Count active/inactive endpoints per table after the run.
     *
     * @param  list<string>  $tables
     * @return array<string, array{active: int, inactive: int}>
     */
    private function activeCounts(array $tables): array
    {
        $counts = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $active = DB::table($table)->where('status', 'active')->count();
            $total = DB::table($table)
                ->whereNotNull('endpoint_id')
                ->where('endpoint_id', '!=', '')
                ->count();

            $counts[$table] = [
                'active' => $active,
                'inactive' => max(0, $total - $active),
            ];
        }

        return $counts;
    }

    /**
     * @param  list<array{table: string, endpoint: string, name: string, field: string, old: mixed, new: mixed}>  $events
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
     * Build and send the Telegram run report.
     *
     * @param  array<string, mixed>  $data
     */
    private function reportToTelegram(array $data): void
    {
        $notifier = TelegramNotifier::forPricing();

        if (! $notifier->isConfigured()) {
            $this->warn('Telegram pricing bot not configured (TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID) — skipping report.');

            return;
        }

        $events = $data['events'];
        $deactivations = array_values(array_filter(
            $events,
            fn ($e) => $e['field'] === 'status' && $e['new'] !== 'active'
        ));
        $reactivations = array_values(array_filter(
            $events,
            fn ($e) => $e['field'] === 'status' && $e['new'] === 'active'
        ));
        $priceChanges = array_values(array_filter(
            $events,
            fn ($e) => $e['field'] === 'unit' || $e['field'] === 'unit_price'
        ));

        $lines = [];
        $lines[] = '<b>🔄 fal Pricing Sync — Done</b>';
        if ($data['dry_run']) {
            $lines[] = '<i>(dry-run: nothing written)</i>';
        }
        $lines[] = '';
        $lines[] = sprintf('✅ Active models: <b>%d</b>', $data['total_active']);
        $lines[] = sprintf('⛔ Inactive models: <b>%d</b>', $data['total_inactive']);
        $lines[] = sprintf('💲 Priced OK: %d   ⚠️ Price failed: %d', $data['priced'], $data['price_failed']);
        $lines[] = sprintf('🟢 Reactivated: %d   🔴 Deactivated: %d', $data['reactivated'], $data['deactivated']);
        $lines[] = sprintf('⏱ Duration: %ss', $data['duration']);

        if ($deactivations !== []) {
            $lines[] = '';
            $lines[] = '<b>🔴 Just deactivated:</b>';
            foreach ($deactivations as $e) {
                $lines[] = '• ' . $this->esc($e['name']) . ' <i>(' . $this->shortTable($e['table']) . ')</i>';
            }
        }

        if ($reactivations !== []) {
            $lines[] = '';
            $lines[] = '<b>🟢 Just reactivated:</b>';
            foreach ($reactivations as $e) {
                $lines[] = '• ' . $this->esc($e['name']) . ' <i>(' . $this->shortTable($e['table']) . ')</i>';
            }
        }

        if ($priceChanges !== []) {
            $lines[] = '';
            $lines[] = '<b>💲 Pricing changes:</b>';
            foreach ($priceChanges as $e) {
                $old = $e['old'] === null || $e['old'] === '' ? '—' : $e['old'];
                $lines[] = sprintf(
                    '• %s <i>(%s)</i>: %s %s → %s',
                    $this->esc($e['name']),
                    $this->shortTable($e['table']),
                    $e['field'],
                    $this->esc((string) $old),
                    $this->esc((string) $e['new']),
                );
            }
        }

        if ($events === []) {
            $lines[] = '';
            $lines[] = 'No changes this run.';
        }

        $notifier->send(implode("\n", $lines));
        $this->info('Telegram report sent.');
    }

    private function shortTable(string $table): string
    {
        return str_replace(['text_to_', 'image_to_', '_models'], ['', 'img→', ''], $table);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Resolve fal status for a batch of endpoints (1-50) in a single request.
     *
     * @param  list<string>  $endpointIds
     * @return array<string, string>|null  map endpoint_id => 'active'|'inactive'.
     *                                      Endpoints returned with an unknown/empty
     *                                      status are omitted (keep current status).
     *                                      Returns null on transient failure so the
     *                                      whole chunk keeps its current status.
     */
    private function fetchStatusBatch(string $key, array $endpointIds): ?array
    {
        if ($endpointIds === []) {
            return [];
        }

        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Key ' . $key,
                    'Content-Type' => 'application/json',
                ])->get('https://api.fal.ai/v1/models?' . $this->idQuery($endpointIds));
            } catch (\Throwable $e) {
                $this->warn("  Status batch fetch error: {$e->getMessage()}");

                return null;
            }

            if ($response->successful()) {
                $models = $response->json('models', []);
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

                    // Unknown/empty status: leave unset → caller keeps current value.
                    if (! is_string($status) || $status === '') {
                        continue;
                    }

                    $map[$eid] = strtolower($status) === 'active' ? 'active' : 'inactive';
                }

                // Requested but not returned at all → no longer on fal → inactive.
                foreach ($endpointIds as $eid) {
                    if (! isset($seen[$eid]) && ! array_key_exists($eid, $map)) {
                        $map[$eid] = 'inactive';
                    }
                }

                return $map;
            }

            if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                $wait = ($attempt + 1) * 10;
                $this->warn("  Rate limited (status batch), waiting {$wait}s...");
                sleep($wait);

                continue;
            }

            // Transient/unknown error: keep existing statuses for this chunk.
            $this->warn("  Status batch failed (HTTP {$response->status()}) — keeping current statuses.");

            return null;
        }

        return null;
    }

    /**
     * Build a repeated ?endpoint_id=a&endpoint_id=b query string (fal's expected form).
     *
     * @param  list<string>  $endpointIds
     */
    private function idQuery(array $endpointIds): string
    {
        return implode('&', array_map(
            static fn (string $id): string => 'endpoint_id=' . rawurlencode($id),
            $endpointIds,
        ));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function describe(array $changes): string
    {
        $parts = [];
        foreach (['status', 'unit', 'unit_price'] as $field) {
            if (array_key_exists($field, $changes)) {
                $parts[] = "{$field}={$changes[$field]}";
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Fetch pricing for a batch of endpoints (1-50) in a single request.
     *
     * @param  list<string>  $endpointIds
     * @return array<string, array{unit: string|null, unit_price: float|null}>
     *         map endpoint_id => price. Endpoints without pricing are simply absent.
     */
    private function fetchPricingBatch(string $key, array $endpointIds): array
    {
        if ($endpointIds === []) {
            return [];
        }

        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Key ' . $key,
                    'Content-Type' => 'application/json',
                ])->get('https://api.fal.ai/v1/models/pricing?' . $this->idQuery($endpointIds));
            } catch (\Throwable $e) {
                $this->warn("  Pricing batch fetch error: {$e->getMessage()}");

                return [];
            }

            if ($response->successful()) {
                $prices = $response->json('prices', []);
                $map = [];

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
                    ];
                }

                return $map;
            }

            if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                $wait = ($attempt + 1) * 10;
                $this->warn("  Rate limited (pricing batch), waiting {$wait}s...");
                sleep($wait);

                continue;
            }

            $this->warn("  Pricing batch failed (HTTP {$response->status()})");

            return [];
        }

        return [];
    }

    /**
     * @return array{0: string|null, 1: float|null}
     */
    private function normalize(string $table, string $endpointId, ?string $unit, ?float $unitPrice): array
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
}
