<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FalSyncMusicModels extends Command
{
    protected $signature = 'fal:sync-music-models
                            {--pricing : Also fetch unit/unit_price for each model}
                            {--dry-run : Show what would be written without saving}';

    protected $description = 'Sync curated fal.ai text-to-music + audio-to-audio models into text_to_music_* tables';

    private const MODELS_TABLE = 'text_to_music_models';

    private const CATEGORIES_TABLE = 'text_to_music_categories';

    /**
     * Curated catalog — these endpoints are kept.
     *
     * @var array<string, int>
     */
    private const POPULAR_SORT = [
        'fal-ai/minimax-music/v2.6' => 10,
        'fal-ai/ace-step/audio-to-audio' => 15,
        'fal-ai/lyria3/pro' => 20,
        'fal-ai/elevenlabs/music' => 30,
        'cassetteai/music-generator' => 40,
        'fal-ai/stable-audio-25/text-to-audio' => 50,
    ];

    /**
     * @var array<string, int>
     */
    private const CATEGORY_SORT = [
        'MiniMax' => 10,
        'ACE-Step' => 15,
        'Lyria' => 20,
        'ElevenLabs' => 30,
        'CassetteAI' => 40,
        'Stable Audio' => 50,
        'Other' => 999,
    ];

    /**
     * @var array<string, array{
     *     name: string,
     *     description: string,
     *     tags: list<string>,
     *     supports_vocals: bool,
     *     supports_lyrics: bool,
     *     supports_instrumental: bool,
     *     supports_audio: bool,
     *     max_lyrics_chars: int|null,
     *     max_prompt_chars: int|null,
     *     max_duration: int|null,
     *     default_duration_seconds: int|null,
     *     supports_duration_control: bool,
     *     min_duration_seconds: int|null,
     *     duration_step_seconds: int|null
     * }>
     */
    private const CURATED_COPY = [
        'fal-ai/minimax-music/v2.6' => [
            'name' => 'MiniMax Music 2.6',
            'description' => 'Complete songs with singing, backing tracks, and arrangements from lyrics + style',
            'tags' => ['Popular', 'Vocals', 'Lyrics', 'Instrumental'],
            'supports_vocals' => true,
            'supports_lyrics' => true,
            'supports_instrumental' => true,
            'supports_audio' => false,
            'max_lyrics_chars' => 3500,
            'max_prompt_chars' => 2000,
            'max_duration' => null,
            'default_duration_seconds' => null,
            'supports_duration_control' => false,
            'min_duration_seconds' => null,
            'duration_step_seconds' => null,
        ],
        'fal-ai/ace-step/audio-to-audio' => [
            'name' => 'ACE-Step Audio Edit',
            'description' => 'Upload a track to remix or rewrite lyrics — change language, style, or vocal character from a source song',
            'tags' => ['Audio Input', 'Remix', 'Lyrics Edit', 'Popular'],
            'supports_vocals' => true,
            'supports_lyrics' => true,
            'supports_instrumental' => true,
            'supports_audio' => true,
            'max_lyrics_chars' => 3000,
            'max_prompt_chars' => 2000,
            'max_duration' => 651,
            'default_duration_seconds' => 90,
            'supports_duration_control' => false,
            'min_duration_seconds' => null,
            'duration_step_seconds' => null,
        ],
        'fal-ai/lyria3/pro' => [
            'name' => 'Lyria 3 Pro',
            'description' => 'Google’s top music model — full-length songs with vocals and timed lyrics',
            'tags' => ['New', 'High Quality', 'Vocals', 'Full Length'],
            'supports_vocals' => true,
            'supports_lyrics' => true,
            'supports_instrumental' => true,
            'supports_audio' => false,
            'max_lyrics_chars' => 2500,
            'max_prompt_chars' => 5000,
            'max_duration' => 180,
            'default_duration_seconds' => null,
            'supports_duration_control' => false,
            'min_duration_seconds' => null,
            'duration_step_seconds' => null,
        ],
        'fal-ai/elevenlabs/music' => [
            'name' => 'ElevenLabs Music',
            'description' => 'Polished composition-plan music with section-by-section control',
            'tags' => ['High Quality', 'Composition', 'Studio'],
            'supports_vocals' => true,
            'supports_lyrics' => true,
            'supports_instrumental' => true,
            'supports_audio' => false,
            'max_lyrics_chars' => 3000,
            'max_prompt_chars' => 4100,
            'max_duration' => 600,
            'default_duration_seconds' => 120,
            'supports_duration_control' => true,
            'min_duration_seconds' => 3,
            'duration_step_seconds' => 1,
        ],
        'cassetteai/music-generator' => [
            'name' => 'CassetteAI Music',
            'description' => 'Fast, affordable tracks — great for drafts and beats with duration control',
            'tags' => ['Fast', 'Best Value', 'Duration Control'],
            'supports_vocals' => false,
            'supports_lyrics' => false,
            'supports_instrumental' => true,
            'supports_audio' => false,
            'max_lyrics_chars' => null,
            'max_prompt_chars' => 2000,
            'max_duration' => 180,
            'default_duration_seconds' => 90,
            'supports_duration_control' => true,
            'min_duration_seconds' => 1,
            'duration_step_seconds' => 1,
        ],
        'fal-ai/stable-audio-25/text-to-audio' => [
            'name' => 'Stable Audio 2.5',
            'description' => 'Long instrumental pieces and rich sound design from text prompts',
            'tags' => ['Instrumental', 'Sound Design', 'Long Form'],
            'supports_vocals' => false,
            'supports_lyrics' => false,
            'supports_instrumental' => true,
            'supports_audio' => false,
            'max_lyrics_chars' => null,
            'max_prompt_chars' => 2000,
            'max_duration' => 190,
            'default_duration_seconds' => 90,
            'supports_duration_control' => true,
            'min_duration_seconds' => 1,
            'duration_step_seconds' => 1,
        ],
    ];

    public function handle(): int
    {
        $key = config('services.fal.key');
        if (! is_string($key) || $key === '') {
            $this->error('FAL_KEY is not set in .env');

            return self::FAILURE;
        }

        if (! Schema::hasTable(self::MODELS_TABLE) || ! Schema::hasTable(self::CATEGORIES_TABLE)) {
            $this->error('text_to_music tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info('Syncing '.count(self::POPULAR_SORT).' popular music models…');

        $synced = 0;
        foreach (self::POPULAR_SORT as $endpointId => $sort) {
            $model = $this->fetchModelByEndpoint($key, $endpointId);
            if ($model === null) {
                $this->warn("  × could not fetch {$endpointId}");
                continue;
            }

            $payload = $this->buildRow($model);
            if ($payload === null) {
                continue;
            }

            $brand = $payload['_brand'];
            unset($payload['_brand']);

            if ($dryRun) {
                $this->line(sprintf(
                    '  [%s] %s → %s (sort=%s)',
                    $brand,
                    $payload['endpoint_id'],
                    $payload['name'],
                    $payload['sort'],
                ));
                $synced++;
                continue;
            }

            $categoryId = $this->ensureCategory($brand);
            $payload['category_id'] = $categoryId;
            $payload['updated_at'] = now();

            $existing = DB::table(self::MODELS_TABLE)->where('endpoint_id', $payload['endpoint_id'])->first();
            if ($existing) {
                unset($payload['created_at']);
                DB::table(self::MODELS_TABLE)
                    ->where('endpoint_id', $payload['endpoint_id'])
                    ->update($payload);
            } else {
                $payload['created_at'] = now();
                DB::table(self::MODELS_TABLE)->insert($payload);
            }

            $synced++;
            $this->line("  ✓ {$payload['endpoint_id']}");
        }

        if (! $dryRun) {
            $kept = array_keys(self::POPULAR_SORT);
            $removed = DB::table(self::MODELS_TABLE)->whereNotIn('endpoint_id', $kept)->delete();
            if ($removed > 0) {
                $this->line("Removed {$removed} non-curated models.");
            }

            $usedCategoryIds = DB::table(self::MODELS_TABLE)->pluck('category_id')->filter()->unique()->all();
            if ($usedCategoryIds !== []) {
                DB::table(self::CATEGORIES_TABLE)->whereNotIn('id', $usedCategoryIds)->delete();
            } else {
                DB::table(self::CATEGORIES_TABLE)->delete();
            }
        }

        $this->info("Synced {$synced} models.");

        if (! $dryRun && $this->option('pricing')) {
            $this->syncPricing($key);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchModelByEndpoint(string $key, string $endpointId): ?array
    {
        $response = Http::timeout(45)
            ->withHeaders([
                'Authorization' => 'Key '.$key,
                'Content-Type' => 'application/json',
            ])
            ->get('https://api.fal.ai/v1/models', [
                'endpoint_id' => $endpointId,
            ]);

        if ($response->successful()) {
            $models = $response->json('models', []);
            if (is_array($models) && $models !== []) {
                return $models[0];
            }
        }

        $search = Http::timeout(45)
            ->withHeaders([
                'Authorization' => 'Key '.$key,
            ])
            ->get('https://api.fal.ai/v1/models', [
                'q' => $endpointId,
                'status' => 'active',
                'limit' => 15,
            ]);

        foreach ($search->json('models', []) ?? [] as $model) {
            if (($model['endpoint_id'] ?? null) === $endpointId) {
                return $model;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>|null
     */
    private function buildRow(array $model): ?array
    {
        $endpointId = (string) ($model['endpoint_id'] ?? '');
        if ($endpointId === '') {
            return null;
        }

        $meta = is_array($model['metadata'] ?? null) ? $model['metadata'] : [];
        $brand = $this->resolveBrand($endpointId, $meta);
        $curated = self::CURATED_COPY[$endpointId] ?? null;

        $displayName = $curated['name']
            ?? (trim((string) ($meta['display_name'] ?? ''))
                ?: Str::of($endpointId)->afterLast('/')->replace('-', ' ')->title()->toString());

        $description = $curated['description']
            ?? (is_string($meta['description'] ?? null) ? $meta['description'] : null);

        $tags = $curated['tags'] ?? [];
        if ($tags === [] && is_array($meta['tags'] ?? null)) {
            $tags = array_values(array_filter(array_map(
                static fn ($t) => is_string($t) ? trim($t) : '',
                $meta['tags'],
            )));
        }

        $thumbnail = $meta['thumbnail_url'] ?? null;
        if (! is_string($thumbnail) || $thumbnail === '') {
            $thumbnail = null;
        }

        $status = $meta['status'] ?? 'active';
        if (! is_string($status) || $status === '') {
            $status = 'active';
        }

        $row = [
            '_brand' => $brand,
            'endpoint_id' => $endpointId,
            'name' => $displayName,
            'description' => $description,
            'image_url' => $thumbnail,
            'image_cover' => $thumbnail,
            'tags' => json_encode(array_values($tags)),
            'status' => $status === 'active' ? 'active' : $status,
            'sort' => self::POPULAR_SORT[$endpointId] ?? 900,
            'supports_vocals' => (bool) ($curated['supports_vocals'] ?? false),
            'supports_lyrics' => (bool) ($curated['supports_lyrics'] ?? false),
            'supports_instrumental' => (bool) ($curated['supports_instrumental'] ?? true),
            'max_lyrics_chars' => $curated['max_lyrics_chars'] ?? null,
            'max_prompt_chars' => $curated['max_prompt_chars'] ?? null,
            'max_duration' => $curated['max_duration'] ?? null,
            'default_duration_seconds' => $curated['default_duration_seconds'] ?? null,
        ];

        if (Schema::hasColumn(self::MODELS_TABLE, 'supports_audio')) {
            $row['supports_audio'] = (bool) ($curated['supports_audio'] ?? false);
        }
        if (Schema::hasColumn(self::MODELS_TABLE, 'supports_duration_control')) {
            $row['supports_duration_control'] = (bool) ($curated['supports_duration_control'] ?? false);
            $row['min_duration_seconds'] = $curated['min_duration_seconds'] ?? null;
            $row['duration_step_seconds'] = $curated['duration_step_seconds'] ?? null;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveBrand(string $endpointId, array $meta): string
    {
        $id = strtolower($endpointId);
        $groupKey = strtolower((string) ($meta['group']['key'] ?? ''));

        return match (true) {
            str_contains($id, 'elevenlabs') || str_contains($groupKey, 'elevenlabs') => 'ElevenLabs',
            str_contains($id, 'minimax') || str_contains($groupKey, 'minimax') => 'MiniMax',
            str_contains($id, 'ace-step') || str_contains($groupKey, 'ace') => 'ACE-Step',
            str_contains($id, 'lyria') || str_contains($groupKey, 'lyria') => 'Lyria',
            str_contains($id, 'cassette') || str_contains($groupKey, 'cassette') => 'CassetteAI',
            str_contains($id, 'stable-audio') || str_contains($groupKey, 'stable') => 'Stable Audio',
            default => 'Other',
        };
    }

    private function ensureCategory(string $brand): int
    {
        $existing = DB::table(self::CATEGORIES_TABLE)->where('name', $brand)->first();
        if ($existing) {
            DB::table(self::CATEGORIES_TABLE)
                ->where('id', $existing->id)
                ->update([
                    'sort' => self::CATEGORY_SORT[$brand] ?? 999,
                    'updated_at' => now(),
                ]);

            return (int) $existing->id;
        }

        return (int) DB::table(self::CATEGORIES_TABLE)->insertGetId([
            'name' => $brand,
            'sort' => self::CATEGORY_SORT[$brand] ?? 999,
            'icon_url' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function syncPricing(string $key): void
    {
        $endpoints = DB::table(self::MODELS_TABLE)->whereNotNull('endpoint_id')->pluck('endpoint_id');
        $count = $endpoints->count();
        $this->info("Fetching pricing for {$count} music models…");

        foreach ($endpoints as $i => $endpointId) {
            if (! is_string($endpointId) || $endpointId === '') {
                continue;
            }

            $this->fetchPricingWithRetry($key, $endpointId);

            if ($i < $count - 1) {
                usleep(750_000);
            }
        }

        $this->info('Pricing synced.');
    }

    private function fetchPricingWithRetry(string $key, string $endpointId): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = Http::timeout(45)
                ->withHeaders([
                    'Authorization' => 'Key '.$key,
                    'Content-Type' => 'application/json',
                ])
                ->get('https://api.fal.ai/v1/models/pricing', [
                    'endpoint_id' => $endpointId,
                ]);

            if ($response->successful()) {
                $prices = $response->json('prices', []);
                $price = is_array($prices) && $prices !== [] ? $prices[0] : null;
                if (! is_array($price)) {
                    $this->warn("  No pricing for {$endpointId}");

                    return;
                }

                DB::table(self::MODELS_TABLE)
                    ->where('endpoint_id', $endpointId)
                    ->update([
                        'unit' => isset($price['unit']) && is_string($price['unit']) ? $price['unit'] : null,
                        'unit_price' => isset($price['unit_price']) ? (float) $price['unit_price'] : null,
                        'updated_at' => now(),
                    ]);

                $this->line("  $ {$endpointId}: {$price['unit_price']} / {$price['unit']}");

                return;
            }

            if ($response->status() === 429 && $attempt < 4) {
                $wait = ($attempt + 1) * 8;
                $this->warn("  Rate limited on {$endpointId}, waiting {$wait}s…");
                sleep($wait);
                continue;
            }

            $this->warn("  Pricing failed for {$endpointId}: HTTP ".$response->status());

            return;
        }
    }
}
