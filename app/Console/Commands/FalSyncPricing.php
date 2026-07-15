<?php

namespace App\Console\Commands;

use App\Services\FalVideoPricingNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        {--sleep=2 : Seconds to wait between fal requests}
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

    /** @var list<string> */
    private const ALL_TABLES = [
        'text_to_image_models',
        'text_to_video_models',
        'image_to_video_models',
        'text_to_voice_models',
        'text_to_music_models',
    ];

    public function handle(): int
    {
        $key = config('services.fal.key');

        if (! $key) {
            $this->error('FAL_KEY is not set in .env');

            return self::FAILURE;
        }

        $sleep = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');
        $skipStatus = (bool) $this->option('skip-status');
        $only = $this->option('table');

        $tables = $only ? [$only] : self::ALL_TABLES;

        $priced = 0;
        $priceFailed = 0;
        $deactivated = 0;
        $reactivated = 0;

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Skipping missing table: {$table}");

                continue;
            }

            $rows = DB::table($table)
                ->whereNotNull('endpoint_id')
                ->where('endpoint_id', '!=', '')
                ->orderBy('id')
                ->get(['endpoint_id', 'status']);

            $count = $rows->count();
            $this->info("[{$table}] syncing {$count} endpoints...");

            foreach ($rows->values() as $i => $row) {
                $endpointId = $row->endpoint_id;
                $changes = [];

                // 1) Status: only change when fal gives a definitive answer.
                if (! $skipStatus) {
                    $status = $this->fetchStatus($key, $endpointId);

                    if ($status !== null && $status !== $row->status) {
                        $changes['status'] = $status;

                        if ($status === 'active') {
                            $reactivated++;
                        } else {
                            $deactivated++;
                        }
                    }

                    if ($sleep > 0) {
                        sleep($sleep);
                    }
                }

                // 2) Pricing.
                $price = $this->fetchPricing($key, $endpointId);

                if ($price === null) {
                    $priceFailed++;
                    $this->warn("  ! {$endpointId}: no pricing returned");
                } else {
                    [$unit, $unitPrice] = $this->normalize($table, $endpointId, $price['unit'], $price['unit_price']);

                    if ($unitPrice === null || $unitPrice <= 0) {
                        $priceFailed++;
                        $this->warn("  ! {$endpointId}: invalid price ({$price['unit_price']})");
                    } else {
                        $changes['unit'] = $unit;
                        $changes['unit_price'] = $unitPrice;
                        $priced++;
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

                if ($sleep > 0 && $i < $count - 1) {
                    sleep($sleep);
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. priced=%d price_failed=%d deactivated=%d reactivated=%d%s',
            $priced,
            $priceFailed,
            $deactivated,
            $reactivated,
            $dryRun ? ' (dry-run: nothing written)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Resolve the fal status for an endpoint.
     *
     * Returns 'active'/'inactive' when fal answers definitively, or null when the
     * request fails transiently (429/5xx/timeout) so the current status is kept.
     */
    private function fetchStatus(string $key, string $endpointId): ?string
    {
        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Key ' . $key,
                    'Content-Type' => 'application/json',
                ])->get('https://api.fal.ai/v1/models', [
                    'endpoint_id' => $endpointId,
                ]);
            } catch (\Throwable $e) {
                $this->warn("  Status fetch error for {$endpointId}: {$e->getMessage()}");

                return null;
            }

            // Endpoint no longer exists on fal → definitively inactive.
            if ($response->status() === 404) {
                return 'inactive';
            }

            if ($response->successful()) {
                $models = $response->json('models', []);
                $first = $models[0] ?? null;

                if (! is_array($first)) {
                    return 'inactive';
                }

                $status = $first['metadata']['status'] ?? null;

                if (! is_string($status) || $status === '') {
                    return null;
                }

                return strtolower($status) === 'active' ? 'active' : 'inactive';
            }

            if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                $wait = ($attempt + 1) * 10;
                $this->warn("  Rate limited (status) on {$endpointId}, waiting {$wait}s...");
                sleep($wait);

                continue;
            }

            // Transient/unknown error: keep existing status.
            return null;
        }

        return null;
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
     * @return array{unit: string|null, unit_price: float|null}|null
     */
    private function fetchPricing(string $key, string $endpointId): ?array
    {
        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => 'Key ' . $key,
                'Content-Type' => 'application/json',
            ])->get('https://api.fal.ai/v1/models/pricing', [
                'endpoint_id' => $endpointId,
            ]);

            if ($response->successful()) {
                $prices = $response->json('prices', []);
                $first = $prices[0] ?? null;

                if (! is_array($first)) {
                    return null;
                }

                return [
                    'unit' => isset($first['unit']) && is_string($first['unit']) ? $first['unit'] : null,
                    'unit_price' => isset($first['unit_price']) ? (float) $first['unit_price'] : null,
                ];
            }

            if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                $wait = ($attempt + 1) * 10;
                $this->warn("  Rate limited on {$endpointId}, waiting {$wait}s...");
                sleep($wait);

                continue;
            }

            $this->warn("  Pricing fetch failed for {$endpointId} (HTTP {$response->status()})");

            return null;
        }

        return null;
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
