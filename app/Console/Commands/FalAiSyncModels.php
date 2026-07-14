<?php

namespace App\Console\Commands;

use App\Services\FalVideoPricingNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FalAiSyncModels extends Command
{
    protected $signature = 'fal:sync-models {--pricing : Also fetch unit/unit_price for each model} {--enums : Fetch max_duration and enums from OpenAPI schemas for video models}';

    protected $description = 'Fetch models from Fal AI API and sync into local tables';

    private const CATEGORY_TABLE_MAP = [
        'text-to-image' => 'text_to_image_models',
        'text-to-video' => 'text_to_video_models',
        'image-to-video' => 'image_to_video_models',
    ];

    private const TABLES = [
        'text_to_image_models',
        'text_to_video_models',
        'image_to_video_models',
    ];

    public function handle(): void
    {
        $key = config('services.fal.key');

        if (!$key) {
            $this->error('FAL_KEY is not set in .env');
            return;
        }

        foreach (self::CATEGORY_TABLE_MAP as $category => $table) {
            $this->info("Fetching {$category} models...");

            $response = Http::withHeaders([
                'Authorization' => 'Key ' . $key,
                'Content-Type' => 'application/json',
            ])->get("https://api.fal.ai/v1/models", [
                'category' => $category,
                'status' => 'active',
                'limit' => 50,
            ]);

            if ($response->failed()) {
                $this->error("Failed to fetch {$category} models: " . $response->body());
                continue;
            }

            $models = $response->json('models', []);

            foreach ($models as $model) {
                DB::table($table)->updateOrInsert(
                    ['endpoint_id' => $model['endpoint_id']],
                    [
                        'name' => $model['metadata']['group']['key'] ?? null,
                        'description' => $model['metadata']['description'] ?? null,
                        'status' => $model['metadata']['status'] ?? null,
                        'updated_at' => now(),
                    ]
                );
            }

            $this->info("Synced " . count($models) . " {$category} models.");
        }

        if ($this->option('pricing')) {
            $this->syncPricing($key);
        }

        if ($this->option('enums')) {
            $this->syncEnums($key);
        }

        $this->info('Done.');
    }

    private function syncPricing(string $key): void
    {
        foreach (self::TABLES as $table) {
            $endpoints = DB::table($table)
                ->pluck('endpoint_id');

            $count = $endpoints->count();
            $this->info("Fetching pricing for {$count} models in {$table}...");

            foreach ($endpoints as $i => $endpointId) {
                if (!$endpointId) {
                    continue;
                }

                $this->fetchPricingWithRetry($key, $table, $endpointId);

                if ($i < $count - 1) {
                    $this->info("  Waiting 2s before next request...");
                    sleep(2);
                }
            }
        }

        $this->info('Pricing synced.');
    }

    private function fetchPricingWithRetry(string $key, string $table, string $endpointId): void
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

                foreach ($prices as $price) {
                    $normalized = app(FalVideoPricingNormalizer::class)->normalize(
                        $endpointId,
                        isset($price['unit']) && is_string($price['unit']) ? $price['unit'] : null,
                        isset($price['unit_price']) ? (float) $price['unit_price'] : null,
                    );

                    DB::table($table)
                        ->where('endpoint_id', $endpointId)
                        ->update([
                            'unit' => $normalized['unit'],
                            'unit_price' => $normalized['unit_price'],
                            'updated_at' => now(),
                        ]);
                }

                return;
            }

            if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                $wait = ($attempt + 1) * 10;
                $this->warn("  Rate limited on {$endpointId}, waiting {$wait}s...");
                sleep($wait);
                continue;
            }

            $this->warn("  Pricing fetch failed for {$endpointId}: " . $response->body());
            return;
        }
    }

    private function syncEnums(string $key): void
    {
        $tables = ['text_to_video_models', 'image_to_video_models'];

        foreach ($tables as $table) {
            $endpoints = DB::table($table)
                ->whereNotNull('endpoint_id')
                ->pluck('endpoint_id');

            $count = $endpoints->count();
            $this->info("Fetching OpenAPI enums for {$count} models in {$table}...");

            foreach ($endpoints as $i => $endpointId) {
                if (!$endpointId) {
                    continue;
                }

                $this->fetchEnumsWithRetry($key, $table, $endpointId);

                if ($i < $count - 1) {
                    $this->info("  Waiting 2s before next request...");
                    sleep(2);
                }
            }
        }

        $this->info('Enums synced.');
    }

    private function fetchEnumsWithRetry(string $key, string $table, string $endpointId): void
    {
        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => 'Key ' . $key,
                'Content-Type' => 'application/json',
            ])->get('https://api.fal.ai/v1/models', [
                'endpoint_id' => $endpointId,
                'expand' => 'openapi-3.0',
            ]);

            if ($response->successful()) {
                $models = $response->json('models', []);

                if (empty($models)) {
                    $this->warn("  No models found for {$endpointId}");
                    return;
                }

                $openapi = $models[0]['openapi'] ?? null;
                if (!$openapi) {
                    $this->warn("  No OpenAPI schema for {$endpointId}");
                    return;
                }

                $schemas = $openapi['components']['schemas'] ?? [];

                foreach ($schemas as $schema) {
                    if (!isset($schema['properties']['duration']['enum'])) {
                        continue;
                    }

                    $durationEnum = $schema['properties']['duration']['enum'];
                    $maxDuration = (int) end($durationEnum);

                    DB::table($table)
                        ->where('endpoint_id', $endpointId)
                        ->update([
                            'max_duration' => $maxDuration,
                            'enums' => json_encode($durationEnum),
                            'updated_at' => now(),
                        ]);

                    $this->info("  Updated {$endpointId}: max_duration={$maxDuration}, enums=" . json_encode($durationEnum));
                    return;
                }

                $this->warn("  No duration enum found in OpenAPI schema for {$endpointId}");
                return;
            }

            if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                $wait = ($attempt + 1) * 10;
                $this->warn("  Rate limited on {$endpointId}, waiting {$wait}s...");
                sleep($wait);
                continue;
            }

            $this->warn("  Enum fetch failed for {$endpointId}: " . $response->body());
            return;
        }
    }
}
