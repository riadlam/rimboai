<?php

namespace App\Console\Commands;

use App\Services\FalVideoPricingNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class FalNormalizeVideoPricing extends Command
{
    protected $signature = 'fal:normalize-video-pricing
                            {--table= : Limit to one table (text_to_video_models|image_to_video_models)}
                            {--dry-run : Show changes without writing}
                            {--skip-fetch : Only re-normalize existing unit/unit_price rows (no fal API)}';

    protected $description = 'Fetch fal pricing for video models and store clear unit labels (e.g. tokens_per_1000 vs seconds)';

    /** @var list<string> */
    private array $tables = [
        'text_to_video_models',
        'image_to_video_models',
    ];

    public function handle(FalVideoPricingNormalizer $normalizer): int
    {
        $key = config('services.fal.key');
        $dryRun = (bool) $this->option('dry-run');
        $skipFetch = (bool) $this->option('skip-fetch');
        $onlyTable = $this->option('table');

        if (! $skipFetch && ! $key) {
            $this->error('FAL_KEY is not set in .env');

            return self::FAILURE;
        }

        $tables = $this->tables;
        if (is_string($onlyTable) && $onlyTable !== '') {
            if (! in_array($onlyTable, $this->tables, true)) {
                $this->error('Invalid --table. Use text_to_video_models or image_to_video_models.');

                return self::FAILURE;
            }
            $tables = [$onlyTable];
        }

        $rows = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("Table missing: {$table}");
                continue;
            }

            $models = DB::table($table)
                ->whereNotNull('endpoint_id')
                ->where('endpoint_id', '!=', '')
                ->orderBy('sort')
                ->orderBy('name')
                ->get(['id', 'endpoint_id', 'name', 'unit', 'unit_price']);

            $this->info(($skipFetch ? 'Normalizing' : 'Fetching + normalizing')." {$models->count()} rows in {$table}...");

            foreach ($models as $i => $model) {
                $endpointId = (string) $model->endpoint_id;
                $rawUnit = $model->unit;
                $rawPrice = $model->unit_price !== null ? (float) $model->unit_price : null;

                if (! $skipFetch) {
                    $fetched = $this->fetchPricing($key, $endpointId);
                    if ($fetched !== null) {
                        $rawUnit = $fetched['unit'];
                        $rawPrice = $fetched['unit_price'];
                    } else {
                        $this->warn("  keep existing price for {$endpointId} (fetch failed)");
                    }

                    if ($i < $models->count() - 1) {
                        usleep(350_000);
                    }
                }

                $normalized = $normalizer->normalize($endpointId, is_string($rawUnit) ? $rawUnit : null, $rawPrice);

                $changed = strtolower((string) $model->unit) !== $normalized['unit']
                    || round((float) ($model->unit_price ?? 0), 6) !== round($normalized['unit_price'], 6);

                $rows[] = [
                    'table' => $table,
                    'endpoint' => $endpointId,
                    'name' => $model->name,
                    'old_unit' => $model->unit,
                    'old_price' => $model->unit_price,
                    'unit' => $normalized['unit'],
                    'unit_price' => $normalized['unit_price'],
                    'formula' => $normalized['formula'],
                    'notes' => $normalized['notes'],
                    'changed' => $changed ? 'yes' : 'no',
                ];

                if (! $dryRun && $changed) {
                    DB::table($table)->where('id', $model->id)->update([
                        'unit' => $normalized['unit'],
                        'unit_price' => $normalized['unit_price'],
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        $this->newLine();
        $this->table(
            ['table', 'endpoint', 'old_unit', 'old_price', 'unit', 'unit_price', 'changed'],
            array_map(fn ($r) => [
                $r['table'],
                $r['endpoint'],
                $r['old_unit'],
                $r['old_price'],
                $r['unit'],
                $r['unit_price'],
                $r['changed'],
            ], $rows),
        );

        $this->newLine();
        $this->info('Formulas:');
        foreach ($rows as $r) {
            if ($r['unit'] === 'tokens_per_1000' || $r['changed'] === 'yes') {
                $this->line("  • {$r['endpoint']}");
                $this->line("      {$r['formula']}");
                if ($r['notes'] !== '') {
                    $this->line("      {$r['notes']}");
                }
            }
        }

        if ($dryRun) {
            $this->warn('Dry run — no DB writes.');
        } else {
            $this->info('Done. unit / unit_price updated where needed.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{unit: string|null, unit_price: float|null}|null
     */
    private function fetchPricing(string $key, string $endpointId): ?array
    {
        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => 'Key '.$key,
                'Accept' => 'application/json',
            ])->get('https://api.fal.ai/v1/models/pricing', [
                'endpoint_id' => $endpointId,
            ]);

            if ($response->successful()) {
                $prices = $response->json('prices', []);
                $price = is_array($prices) && isset($prices[0]) && is_array($prices[0]) ? $prices[0] : null;
                if ($price === null) {
                    return null;
                }

                return [
                    'unit' => isset($price['unit']) && is_string($price['unit']) ? $price['unit'] : null,
                    'unit_price' => isset($price['unit_price']) ? (float) $price['unit_price'] : null,
                ];
            }

            if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                $wait = ($attempt + 1) * 8;
                $this->warn("  Rate limited on {$endpointId}, waiting {$wait}s...");
                sleep($wait);
                continue;
            }

            $this->warn("  Pricing fetch failed for {$endpointId}: HTTP ".$response->status());

            return null;
        }

        return null;
    }
}
