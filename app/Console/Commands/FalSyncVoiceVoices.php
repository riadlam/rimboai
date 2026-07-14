<?php

namespace App\Console\Commands;

use App\Models\TextToVoiceModel;
use App\Models\TextToVoiceVoice;
use App\Services\FalService;
use App\Services\VoiceUseCaseClassifier;
use App\Support\PublicMediaUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FalSyncVoiceVoices extends Command
{
    protected $signature = 'fal:sync-voice-voices
                            {--samples : Generate short MP3 previews via fal (costs credits)}
                            {--per-model=8 : Max sample generations per model}
                            {--model= : Limit to one endpoint_id}
                            {--dry-run : Preview without writing}';

    protected $description = 'Sync per-model voices from fal OpenAPI; optionally generate sample MP3s (fal does not ship voice previews)';

    private const SAMPLE_TEXT = 'Hi, this is a short preview of my voice.';

    public function handle(FalService $fal): int
    {
        $key = config('services.fal.key');
        if (! is_string($key) || $key === '') {
            $this->error('FAL_KEY is not set in .env');

            return self::FAILURE;
        }

        if (! $fal->configured()) {
            $this->error('Fal service is not configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $withSamples = (bool) $this->option('samples');
        $perModel = max(1, (int) $this->option('per-model'));
        $onlyEndpoint = $this->option('model');

        $query = TextToVoiceModel::query()->where('status', 'active')->orderBy('sort');
        if (is_string($onlyEndpoint) && $onlyEndpoint !== '') {
            $query->where('endpoint_id', $onlyEndpoint);
        }

        $models = $query->get();
        if ($models->isEmpty()) {
            $this->warn('No text_to_voice_models found. Run fal:sync-voice-models first.');

            return self::FAILURE;
        }

        $this->info('Fal does not provide ready-made voice sample MP3s — syncing voice lists from OpenAPI'
            .($withSamples ? ', then generating short previews.' : '.'));

        foreach ($models as $model) {
            $endpointId = (string) $model->endpoint_id;
            $this->newLine();
            $this->info("→ {$endpointId}");

            $voices = $this->extractVoices($key, $endpointId);
            if ($voices === []) {
                $this->warn('  No voices found in OpenAPI.');
                continue;
            }

            $this->line('  Found '.count($voices).' voices');

            if ($dryRun) {
                foreach (array_slice($voices, 0, 5) as $v) {
                    $this->line("  - {$v['voice_key']} ({$v['name']})");
                }
                if (count($voices) > 5) {
                    $this->line('  …');
                }
                continue;
            }

            $keptKeys = [];
            $classifier = app(VoiceUseCaseClassifier::class);
            foreach ($voices as $index => $voice) {
                $keptKeys[] = $voice['voice_key'];
                $category = $classifier->classify(
                    $voice['name'],
                    $voice['description'],
                    $voice['voice_key'],
                    $voice['tags'] ?? [],
                );
                TextToVoiceVoice::query()->updateOrCreate(
                    [
                        'text_to_voice_model_id' => $model->id,
                        'voice_key' => $voice['voice_key'],
                    ],
                    [
                        'name' => $voice['name'],
                        'description' => $voice['description'],
                        'language' => $voice['language'],
                        'gender' => $voice['gender'],
                        'tags' => $voice['tags'],
                        'category' => $category,
                        'is_default' => $voice['is_default'],
                        'sort' => ($index + 1) * 10,
                    ],
                );
            }

            // Remove voices that disappeared from the catalog
            TextToVoiceVoice::query()
                ->where('text_to_voice_model_id', $model->id)
                ->whereNotIn('voice_key', $keptKeys)
                ->delete();

            // Keep enums column in sync for convenience
            $model->forceFill([
                'enums' => array_column($voices, 'voice_key'),
            ])->save();

            if ($withSamples) {
                $this->generateSamples($fal, $model, $perModel);
            }
        }

        $this->newLine();
        $this->info('Done. Voices: '.TextToVoiceVoice::query()->count());

        return self::SUCCESS;
    }

    /**
     * @return list<array{voice_key: string, name: string, description: ?string, language: ?string, gender: ?string, tags: list<string>, is_default: bool}>
     */
    private function extractVoices(string $key, string $endpointId): array
    {
        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Key '.$key,
                'Content-Type' => 'application/json',
            ])
            ->get('https://api.fal.ai/v1/models', [
                'endpoint_id' => $endpointId,
                'expand' => 'openapi-3.0',
            ]);

        if ($response->failed()) {
            $this->warn('  OpenAPI fetch failed: HTTP '.$response->status());

            return [];
        }

        $openapi = $response->json('models.0.openapi');
        if (! is_array($openapi)) {
            return [];
        }

        $schemas = $openapi['components']['schemas'] ?? [];
        if (! is_array($schemas)) {
            return [];
        }

        $paramName = null;
        $values = [];
        $default = null;
        $descBlob = null;

        foreach ($schemas as $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $properties = $schema['properties'] ?? null;
            if (! is_array($properties)) {
                continue;
            }

            foreach (['voice', 'voice_id'] as $keyName) {
                $prop = $properties[$keyName] ?? null;
                if (! is_array($prop)) {
                    continue;
                }

                $candidates = [];
                if (isset($prop['enum']) && is_array($prop['enum'])) {
                    $candidates = $prop['enum'];
                } elseif (isset($prop['examples']) && is_array($prop['examples'])) {
                    $candidates = $prop['examples'];
                }

                foreach (['anyOf', 'oneOf'] as $combo) {
                    if (! isset($prop[$combo]) || ! is_array($prop[$combo])) {
                        continue;
                    }
                    foreach ($prop[$combo] as $branch) {
                        if (is_array($branch) && isset($branch['enum']) && is_array($branch['enum'])) {
                            $candidates = $branch['enum'];
                            break 2;
                        }
                    }
                }

                if ($candidates === []) {
                    continue;
                }

                $paramName = $keyName;
                $values = $candidates;
                $default = $prop['default'] ?? ($candidates[0] ?? null);
                $descBlob = is_string($prop['description'] ?? null) ? $prop['description'] : null;
                break 2;
            }
        }

        if ($values === []) {
            return [];
        }

        $descMap = $this->parseInlineVoiceDescriptions($descBlob);
        $out = [];
        $seen = [];

        foreach ($values as $i => $raw) {
            if (! is_string($raw) && ! is_numeric($raw)) {
                continue;
            }
            $voiceKey = (string) $raw;
            if ($voiceKey === '' || isset($seen[$voiceKey])) {
                continue;
            }
            $seen[$voiceKey] = true;

            $parsed = $this->parseVoiceLabel($voiceKey);
            $description = $descMap[strtolower($parsed['name'])]
                ?? $descMap[strtolower($voiceKey)]
                ?? null;

            $out[] = [
                'voice_key' => $voiceKey,
                'name' => $parsed['name'],
                'description' => $description,
                'language' => $parsed['language'],
                'gender' => null,
                'tags' => array_values(array_filter([
                    $parsed['language'] ? strtoupper($parsed['language']) : null,
                    $paramName === 'voice_id' ? 'Preset' : null,
                ])),
                'is_default' => $default !== null && (string) $default === $voiceKey,
            ];
        }

        // Ensure default is first
        usort($out, function (array $a, array $b) {
            if ($a['is_default'] === $b['is_default']) {
                return 0;
            }

            return $a['is_default'] ? -1 : 1;
        });

        return $out;
    }

    /**
     * @return array{name: string, language: ?string}
     */
    private function parseVoiceLabel(string $voiceKey): array
    {
        // e.g. "Loretta (en)" / "Wise_Woman"
        $language = null;
        $name = $voiceKey;

        if (preg_match('/^(.*?)\s*\(([a-z]{2}(?:-[A-Z]{2})?)\)\s*$/u', $voiceKey, $m)) {
            $name = trim($m[1]);
            $language = strtolower($m[2]);
        }

        $name = str_replace('_', ' ', $name);
        $name = Str::title($name);

        return [
            'name' => $name,
            'language' => $language,
        ];
    }

    /**
     * Parse "eve: energetic, upbeat. ara: warm, friendly." style blobs.
     *
     * @return array<string, string>
     */
    private function parseInlineVoiceDescriptions(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }

        $map = [];
        if (preg_match_all('/\b([a-zA-Z][\w-]*)\s*:\s*([^.]+)\./', $blob, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $map[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $map;
    }

    private function generateSamples(FalService $fal, TextToVoiceModel $model, int $limit): void
    {
        $voices = TextToVoiceVoice::query()
            ->where('text_to_voice_model_id', $model->id)
            ->orderByDesc('is_default')
            ->orderBy('sort')
            ->limit($limit)
            ->get();

        $endpointId = (string) $model->endpoint_id;
        $this->line("  Generating up to {$voices->count()} sample MP3s…");

        foreach ($voices as $voice) {
            if ($voice->sample_path && Storage::disk('public')->exists($voice->sample_path)) {
                $this->line("  · {$voice->voice_key} (cached)");
                continue;
            }

            try {
                $input = $this->buildTtsInput($endpointId, $voice->voice_key);
                $remoteUrl = $this->runTts($fal, $endpointId, $input);
                if ($remoteUrl === null) {
                    $this->warn("  × {$voice->voice_key}: no audio url");
                    continue;
                }

                $safeKey = Str::slug($voice->voice_key) ?: 'voice';
                $path = "lab/voice-samples/{$model->id}/{$safeKey}.mp3";
                $bytes = Http::timeout(90)->get($remoteUrl)->body();
                if ($bytes === '' || $bytes === false) {
                    $this->warn("  × {$voice->voice_key}: empty download");
                    continue;
                }

                Storage::disk('public')->put($path, $bytes);

                $voice->forceFill([
                    'sample_remote_url' => $remoteUrl,
                    'sample_path' => $path,
                    'sample_url' => PublicMediaUrl::storagePath($path),
                ])->save();

                $this->line("  ✓ {$voice->voice_key}");
                usleep(600_000);
            } catch (\Throwable $e) {
                report($e);
                $this->warn("  × {$voice->voice_key}: ".$e->getMessage());
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTtsInput(string $endpointId, string $voiceKey): array
    {
        $id = strtolower($endpointId);
        $text = self::SAMPLE_TEXT;

        // MiniMax uses voice_setting.voice_id
        if (str_contains($id, 'minimax')) {
            return [
                'text' => $text,
                'voice_setting' => [
                    'voice_id' => $voiceKey,
                ],
            ];
        }

        // Gemini TTS endpoints use `prompt` instead of `text`
        if (str_contains($id, 'gemini')) {
            return [
                'prompt' => $text,
                'voice' => $voiceKey,
            ];
        }

        // Default: top-level voice + text
        return [
            'text' => $text,
            'voice' => $voiceKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function runTts(FalService $fal, string $endpointId, array $input): ?string
    {
        $submit = $fal->submit($endpointId, $input);
        $statusUrl = $submit['status_url'] ?? null;
        $responseUrl = $submit['response_url'] ?? null;

        if (! is_string($statusUrl) || $statusUrl === '') {
            throw new \RuntimeException('Missing status_url from fal submit.');
        }

        $deadline = microtime(true) + 90;
        while (microtime(true) < $deadline) {
            $status = $fal->statusByUrl($statusUrl);
            $state = strtoupper((string) ($status['status'] ?? ''));

            if (in_array($state, ['COMPLETED', 'OK'], true)) {
                $responseUrl = is_string($status['response_url'] ?? null)
                    ? $status['response_url']
                    : $responseUrl;
                break;
            }

            if (in_array($state, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
                throw new \RuntimeException('fal generation failed: '.$state);
            }

            usleep(900_000);
        }

        if (! is_string($responseUrl) || $responseUrl === '') {
            throw new \RuntimeException('Missing response_url.');
        }

        $result = $fal->resultByUrl($responseUrl);
        $audio = $result['audio'] ?? null;
        if (is_array($audio) && is_string($audio['url'] ?? null) && $audio['url'] !== '') {
            return $audio['url'];
        }
        if (is_string($result['audio_url'] ?? null) && $result['audio_url'] !== '') {
            return $result['audio_url'];
        }

        return null;
    }
}
