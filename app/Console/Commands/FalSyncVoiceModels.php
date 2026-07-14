<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FalSyncVoiceModels extends Command
{
    protected $signature = 'fal:sync-voice-models
                            {--limit=80 : Max models to fetch from fal per category}
                            {--pricing : Also fetch unit/unit_price for each model}
                            {--enums : Fetch voice enums from OpenAPI schemas}
                            {--all : Include every active TTS model (not only curated popular)}
                            {--dry-run : Show what would be written without saving}';

    protected $description = 'Fetch popular fal.ai text-to-speech models and sync into text_to_voice_* tables';

    private const MODELS_TABLE = 'text_to_voice_models';

    private const CATEGORIES_TABLE = 'text_to_voice_categories';

    /**
     * Curated catalog (only these are synced by default).
     * Lower sort = shown first.
     *
     * @var array<string, int>
     */
    private const POPULAR_SORT = [
        'fal-ai/elevenlabs/tts/eleven-v3' => 10,
        'fal-ai/elevenlabs/tts/turbo-v2.5' => 20,
        'fal-ai/gemini-3.1-flash-tts' => 30,
        'fal-ai/inworld-tts' => 40,
        'fal-ai/minimax/speech-2.8-hd' => 50,
        'xai/tts/v1' => 60,
    ];

    /**
     * Category display sort.
     *
     * @var array<string, int>
     */
    private const CATEGORY_SORT = [
        'ElevenLabs' => 10,
        'Gemini' => 20,
        'Inworld' => 30,
        'MiniMax' => 40,
        'xAI' => 50,
        'Other' => 999,
    ];

    /**
     * User-facing marketplace copy (name / short pitch / pick-helper tags).
     *
     * @var array<string, array{name: string, description: string, tags: list<string>}>
     */
    private const CURATED_COPY = [
        'fal-ai/elevenlabs/tts/eleven-v3' => [
            'name' => 'ElevenLabs Eleven v3',
            'description' => 'Most expressive ElevenLabs voice — emotions, inline audio tags, 70+ languages',
            'tags' => ['New', 'High Quality', 'Expressive', 'English', 'Arabic', 'French', 'Japanese', 'Chinese', '+60 langs'],
        ],
        'fal-ai/elevenlabs/tts/turbo-v2.5' => [
            'name' => 'ElevenLabs Turbo v2.5',
            'description' => 'Ultra-low latency speech for real-time apps and fast drafts',
            'tags' => ['Ultra-Low Latency', 'Fast', 'English', 'Japanese', 'Chinese', '+26 langs'],
        ],
        'fal-ai/gemini-3.1-flash-tts' => [
            'name' => 'Gemini 3.1 Flash TTS',
            'description' => 'Google’s top speech model — laughs, sighs, whispers, and multi-speaker dialogue',
            'tags' => ['New', 'High Quality', 'Audio Tags', 'Multi-speaker', 'English', 'Japanese', 'Chinese', '+20 langs'],
        ],
        'fal-ai/inworld-tts' => [
            'name' => 'Inworld TTS-1.5 Max',
            'description' => 'Top-tier natural voices at the lowest per-character price',
            'tags' => ['Best Value', 'High Quality', 'Natural', 'English', 'Auto'],
        ],
        'fal-ai/minimax/speech-2.8-hd' => [
            'name' => 'MiniMax Speech 2.8 HD',
            'description' => 'More humanlike HD speech with deep voice control across 30+ languages',
            'tags' => ['New', 'High Quality', 'Humanlike', 'English', 'Japanese', 'Chinese', '+30 langs'],
        ],
        'xai/tts/v1' => [
            'name' => 'xAI Text to Speech',
            'description' => 'Expressive real-time voices with inline speech tags for agents and apps',
            'tags' => ['Real-time', 'Expressive', 'English', 'Speech Tags'],
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
            $this->error('text_to_voice tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $includeAll = (bool) $this->option('all');

        $this->info('Fetching text-to-speech / TTS models from fal…');

        $collected = [];
        foreach (['text-to-speech', 'text-to-audio'] as $category) {
            $batch = $this->fetchModels($key, $category, $limit);
            $this->line("  {$category}: ".count($batch).' models');
            foreach ($batch as $model) {
                $endpointId = (string) ($model['endpoint_id'] ?? '');
                if ($endpointId === '') {
                    continue;
                }
                $collected[$endpointId] = $model;
            }
        }

        // Ensure curated popular endpoints are present even if category listing missed them
        foreach (array_keys(self::POPULAR_SORT) as $endpointId) {
            if (isset($collected[$endpointId])) {
                continue;
            }
            $extra = $this->fetchModelByEndpoint($key, $endpointId);
            if ($extra !== null) {
                $collected[$endpointId] = $extra;
                $this->line("  + fetched curated {$endpointId}");
            }
        }

        $models = array_values($collected);
        $models = array_values(array_filter($models, fn (array $m) => $this->isTextToSpeechModel($m)));

        if (! $includeAll) {
            $models = array_values(array_filter(
                $models,
                fn (array $m) => array_key_exists((string) $m['endpoint_id'], self::POPULAR_SORT)
                    || $this->looksPopular($m),
            ));
        }

        usort($models, function (array $a, array $b) {
            $sa = self::POPULAR_SORT[(string) $a['endpoint_id']] ?? 900;
            $sb = self::POPULAR_SORT[(string) $b['endpoint_id']] ?? 900;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return strcmp((string) ($a['endpoint_id'] ?? ''), (string) ($b['endpoint_id'] ?? ''));
        });

        $this->info('Syncing '.count($models).' voice models'.($dryRun ? ' (dry-run)' : '').'…');

        $synced = 0;
        foreach ($models as $model) {
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

        $this->info("Synced {$synced} models.");

        if (! $dryRun && ! $includeAll) {
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

        if (! $dryRun && $this->option('pricing')) {
            $this->syncPricing($key);
        }

        if (! $dryRun && $this->option('enums')) {
            $this->syncVoiceEnums($key);
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchModels(string $key, string $category, int $limit): array
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Key '.$key,
                'Content-Type' => 'application/json',
            ])
            ->get('https://api.fal.ai/v1/models', [
                'category' => $category,
                'status' => 'active',
                'limit' => $limit,
            ]);

        if ($response->failed()) {
            $this->warn("Failed to fetch {$category}: ".$response->body());

            return [];
        }

        $models = $response->json('models', []);

        return is_array($models) ? $models : [];
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

        if ($response->failed()) {
            return null;
        }

        $models = $response->json('models', []);
        if (! is_array($models) || $models === []) {
            // fallback search
            $search = Http::timeout(45)
                ->withHeaders([
                    'Authorization' => 'Key '.$key,
                ])
                ->get('https://api.fal.ai/v1/models', [
                    'q' => $endpointId,
                    'status' => 'active',
                    'limit' => 10,
                ]);

            foreach ($search->json('models', []) ?? [] as $model) {
                if (($model['endpoint_id'] ?? null) === $endpointId) {
                    return $model;
                }
            }

            return null;
        }

        return $models[0];
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function isTextToSpeechModel(array $model): bool
    {
        $endpointId = strtolower((string) ($model['endpoint_id'] ?? ''));
        $meta = is_array($model['metadata'] ?? null) ? $model['metadata'] : [];
        $category = strtolower((string) ($meta['category'] ?? ''));
        $kind = strtolower((string) ($meta['kind'] ?? 'inference'));

        if ($kind !== '' && $kind !== 'inference') {
            return false;
        }

        // Skip clone / design / stream helpers — not primary TTS generators
        foreach (['/voice-clone', '/voice-design', '/clone-voice', '/stream', '/batch'] as $skip) {
            if (str_contains($endpointId, $skip)) {
                return false;
            }
        }

        if ($category === 'text-to-speech') {
            return true;
        }

        // text-to-audio includes music + dialogue; keep clear TTS endpoints only
        if ($category === 'text-to-audio') {
            return str_contains($endpointId, '/tts')
                || str_contains($endpointId, 'tts/')
                || str_contains($endpointId, 'text-to-speech')
                || str_contains($endpointId, 'kokoro/')
                || str_ends_with($endpointId, '-tts')
                || preg_match('/(?:^|\/)(?:gemini-tts|orpheus-tts|dia-tts|maya|zonos2|vibevoice)(?:\/|$)/', $endpointId) === 1;
        }

        return array_key_exists($endpointId, array_change_key_case(self::POPULAR_SORT, CASE_LOWER));
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function looksPopular(array $model): bool
    {
        $meta = is_array($model['metadata'] ?? null) ? $model['metadata'] : [];

        return (bool) ($meta['highlighted'] ?? false) || (bool) ($meta['pinned'] ?? false);
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
                ?: trim((string) ($meta['group']['label'] ?? ''))
                ?: Str::of($endpointId)->afterLast('/')->replace('-', ' ')->title()->toString());

        $description = $curated['description']
            ?? (is_string($meta['description'] ?? null) ? $meta['description'] : null);

        $tags = $curated['tags'] ?? null;
        if ($tags === null) {
            $tags = $meta['tags'] ?? [];
            if (! is_array($tags)) {
                $tags = [];
            }
            $tags = array_values(array_filter(array_map(
                static fn ($t) => is_string($t) ? trim($t) : '',
                $tags,
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

        return [
            '_brand' => $brand,
            'endpoint_id' => $endpointId,
            'name' => $displayName,
            'description' => $description,
            'image_url' => $thumbnail,
            'image_cover' => $thumbnail,
            'tags' => json_encode(array_values($tags)),
            'status' => $status === 'active' ? 'active' : $status,
            'sort' => self::POPULAR_SORT[$endpointId] ?? 900,
        ];
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
            str_contains($id, 'gemini') => 'Gemini',
            str_contains($id, 'inworld') => 'Inworld',
            str_starts_with($id, 'xai/') || str_contains($id, '/xai/') => 'xAI',
            str_contains($id, 'maya') || str_contains($groupKey, 'maya') => 'Maya',
            str_contains($id, 'chatterboxhd') || str_contains($groupKey, 'chatterboxhd') => 'ChatterboxHD',
            str_contains($id, 'chatterbox') || str_contains($groupKey, 'chatterbox') => 'Chatterbox',
            str_contains($id, 'qwen') || str_contains($groupKey, 'qwen') => 'Qwen',
            str_contains($id, 'kling') || str_contains($groupKey, 'kling') => 'Kling',
            str_contains($id, 'index-tts') => 'Index',
            str_contains($id, 'orpheus') => 'Orpheus',
            str_contains($id, 'dia-tts') || str_contains($groupKey, 'dia') => 'Dia',
            str_contains($id, 'kokoro') => 'Kokoro',
            str_contains($id, 'vibevoice') || str_contains($groupKey, 'vibevoice') => 'VibeVoice',
            str_contains($id, 'seed-speech') || str_contains($id, 'bytedance') => 'SeedSpeech',
            str_contains($id, 'zonos') => 'Zonos',
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
        $this->info("Fetching pricing for {$count} voice models…");

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

    private function syncVoiceEnums(string $key): void
    {
        $endpoints = DB::table(self::MODELS_TABLE)->whereNotNull('endpoint_id')->pluck('endpoint_id');
        $count = $endpoints->count();
        $this->info("Fetching OpenAPI voice enums for {$count} models…");

        foreach ($endpoints as $i => $endpointId) {
            if (! is_string($endpointId) || $endpointId === '') {
                continue;
            }

            $this->fetchVoiceEnumsWithRetry($key, $endpointId);

            if ($i < $count - 1) {
                usleep(750_000);
            }
        }

        $this->info('Voice enums synced.');
    }

    private function fetchVoiceEnumsWithRetry(string $key, string $endpointId): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Key '.$key,
                    'Content-Type' => 'application/json',
                ])
                ->get('https://api.fal.ai/v1/models', [
                    'endpoint_id' => $endpointId,
                    'expand' => 'openapi-3.0',
                ]);

            if ($response->successful()) {
                $models = $response->json('models', []);
                $openapi = is_array($models[0]['openapi'] ?? null) ? $models[0]['openapi'] : null;
                if ($openapi === null) {
                    $this->warn("  No OpenAPI for {$endpointId}");

                    return;
                }

                $voiceEnum = $this->extractVoiceEnum($openapi);
                if ($voiceEnum === []) {
                    $this->warn("  No voice enum for {$endpointId}");

                    return;
                }

                DB::table(self::MODELS_TABLE)
                    ->where('endpoint_id', $endpointId)
                    ->update([
                        'enums' => json_encode($voiceEnum),
                        'updated_at' => now(),
                    ]);

                $this->line('  ♪ '.$endpointId.': '.count($voiceEnum).' voices');

                return;
            }

            if ($response->status() === 429 && $attempt < 4) {
                $wait = ($attempt + 1) * 8;
                $this->warn("  Rate limited on {$endpointId}, waiting {$wait}s…");
                sleep($wait);
                continue;
            }

            $this->warn("  Enum fetch failed for {$endpointId}: HTTP ".$response->status());

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $openapi
     * @return list<string|int>
     */
    private function extractVoiceEnum(array $openapi): array
    {
        $schemas = $openapi['components']['schemas'] ?? [];
        if (! is_array($schemas)) {
            return [];
        }

        $preferredKeys = ['voice', 'voice_id', 'voice_setting', 'speaker', 'speaker_id'];

        foreach ($schemas as $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $properties = $schema['properties'] ?? null;
            if (! is_array($properties)) {
                continue;
            }

            foreach ($preferredKeys as $key) {
                $prop = $properties[$key] ?? null;
                if (! is_array($prop)) {
                    continue;
                }

                if (isset($prop['enum']) && is_array($prop['enum']) && $prop['enum'] !== []) {
                    return array_values($prop['enum']);
                }

                // nested enum under anyOf / oneOf
                foreach (['anyOf', 'oneOf', 'allOf'] as $combo) {
                    if (! isset($prop[$combo]) || ! is_array($prop[$combo])) {
                        continue;
                    }
                    foreach ($prop[$combo] as $branch) {
                        if (is_array($branch) && isset($branch['enum']) && is_array($branch['enum']) && $branch['enum'] !== []) {
                            return array_values($branch['enum']);
                        }
                    }
                }
            }
        }

        return [];
    }
}
