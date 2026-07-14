<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class FalSyncVideoAudioSupport extends Command
{
    protected $signature = 'fal:sync-video-audio
                            {--table= : Limit to text_to_video_models or image_to_video_models}
                            {--dry-run : Show results without writing}';

    protected $description = 'Detect generate_audio in fal OpenAPI schemas and store supports_audio on video model tables';

    /** @var list<string> */
    private array $tables = [
        'text_to_video_models',
        'image_to_video_models',
    ];

    public function handle(): int
    {
        $key = config('services.fal.key');
        if (! $key) {
            $this->error('FAL_KEY is not set in .env');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $onlyTable = $this->option('table');
        $tables = $this->tables;

        if (is_string($onlyTable) && $onlyTable !== '') {
            if (! in_array($onlyTable, $this->tables, true)) {
                $this->error('Invalid --table.');

                return self::FAILURE;
            }
            $tables = [$onlyTable];
        }

        $rows = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'supports_audio')) {
                $this->warn("Skip {$table}: missing table or supports_audio column (run migrations).");
                continue;
            }

            $models = DB::table($table)
                ->whereNotNull('endpoint_id')
                ->where('endpoint_id', '!=', '')
                ->orderBy('sort')
                ->orderBy('name')
                ->get(['id', 'endpoint_id', 'name', 'supports_audio']);

            $this->info("Scanning {$models->count()} endpoints in {$table}...");

            foreach ($models as $i => $model) {
                $endpointId = (string) $model->endpoint_id;
                $detected = $this->detectGenerateAudio($key, $endpointId);
                $supports = $detected['supports'];
                $old = (bool) $model->supports_audio;
                $changed = $old !== $supports;

                $rows[] = [
                    'table' => $table,
                    'endpoint' => $endpointId,
                    'old' => $old ? 'yes' : 'no',
                    'supports_audio' => $supports ? 'yes' : 'no',
                    'default' => $detected['default'] === null ? '—' : json_encode($detected['default']),
                    'changed' => $changed ? 'yes' : 'no',
                ];

                if (! $dryRun && $changed) {
                    DB::table($table)->where('id', $model->id)->update([
                        'supports_audio' => $supports,
                        'updated_at' => now(),
                    ]);
                }

                if ($i < $models->count() - 1) {
                    usleep(350_000);
                }
            }
        }

        $this->newLine();
        $this->table(
            ['table', 'endpoint', 'old', 'supports_audio', 'openapi_default', 'changed'],
            array_map(fn ($r) => [
                $r['table'],
                $r['endpoint'],
                $r['old'],
                $r['supports_audio'],
                $r['default'],
                $r['changed'],
            ], $rows),
        );

        if ($dryRun) {
            $this->warn('Dry run — no DB writes.');
        } else {
            $this->info('Done.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{supports: bool, default: mixed}
     */
    private function detectGenerateAudio(string $key, string $endpointId): array
    {
        $maxRetries = 5;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $response = Http::withHeaders([
                'Authorization' => 'Key '.$key,
                'Accept' => 'application/json',
            ])->get('https://api.fal.ai/v1/models', [
                'endpoint_id' => $endpointId,
                'expand' => 'openapi-3.0',
            ]);

            if ($response->successful()) {
                $schemas = $response->json('models.0.openapi.components.schemas') ?? [];
                if (! is_array($schemas)) {
                    return ['supports' => false, 'default' => null];
                }

                foreach ($schemas as $schema) {
                    if (! is_array($schema)) {
                        continue;
                    }
                    $prop = $schema['properties']['generate_audio'] ?? null;
                    if (is_array($prop)) {
                        return [
                            'supports' => true,
                            'default' => $prop['default'] ?? null,
                        ];
                    }
                }

                return ['supports' => false, 'default' => null];
            }

            if ($response->status() === 429 && $attempt < $maxRetries - 1) {
                $wait = ($attempt + 1) * 8;
                $this->warn("  Rate limited on {$endpointId}, waiting {$wait}s...");
                sleep($wait);
                continue;
            }

            $this->warn("  OpenAPI fetch failed for {$endpointId}: HTTP ".$response->status());

            return ['supports' => false, 'default' => null];
        }

        return ['supports' => false, 'default' => null];
    }
}
