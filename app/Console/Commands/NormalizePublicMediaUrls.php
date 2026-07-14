<?php

namespace App\Console\Commands;

use App\Support\PublicMediaUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrite localhost / absolute APP_URL media URLs in the DB to host-agnostic paths.
 */
class NormalizePublicMediaUrls extends Command
{
    protected $signature = 'media:normalize-public-urls {--dry-run : Show changes without writing}';

    protected $description = 'Convert sample_url / image_url values that contain localhost or APP_URL into relative /storage/... paths';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $updated = 0;

        if (Schema::hasTable('text_to_voice_voices')) {
            $updated += $this->normalizeVoiceSamples($dry);
        }

        if (Schema::hasTable('text_to_music_examples')) {
            $updated += $this->normalizeMusicSamples($dry);
        }

        foreach ([
            'text_to_voice_models',
            'text_to_image_models',
            'text_to_video_models',
            'text_to_music_models',
            'image_to_video_models',
            'video_to_video_models',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $updated += $this->normalizeModelMedia($table, $dry);
        }

        $this->info(($dry ? '[dry-run] ' : '')."Normalized {$updated} row(s).");

        return self::SUCCESS;
    }

    private function normalizeVoiceSamples(bool $dry): int
    {
        $count = 0;
        DB::table('text_to_voice_voices')->orderBy('id')->chunkById(100, function ($rows) use ($dry, &$count) {
            foreach ($rows as $row) {
                $next = PublicMediaUrl::sample($row->sample_path ?? null, $row->sample_url ?? null, $row->sample_remote_url ?? null);
                $current = $row->sample_url ?? null;
                if ($next === null || $next === $current) {
                    continue;
                }
                $count++;
                if ($dry) {
                    $this->line("voice #{$row->id}: {$current} → {$next}");
                    continue;
                }
                DB::table('text_to_voice_voices')->where('id', $row->id)->update([
                    'sample_url' => $next,
                    'updated_at' => now(),
                ]);
            }
        });

        return $count;
    }

    private function normalizeMusicSamples(bool $dry): int
    {
        $count = 0;
        DB::table('text_to_music_examples')->orderBy('id')->chunkById(100, function ($rows) use ($dry, &$count) {
            foreach ($rows as $row) {
                $next = PublicMediaUrl::sample($row->sample_path ?? null, $row->sample_url ?? null, $row->sample_remote_url ?? null);
                $current = $row->sample_url ?? null;
                if ($next === null || $next === $current) {
                    continue;
                }
                $count++;
                if ($dry) {
                    $this->line("music example #{$row->id}: {$current} → {$next}");
                    continue;
                }
                DB::table('text_to_music_examples')->where('id', $row->id)->update([
                    'sample_url' => $next,
                    'updated_at' => now(),
                ]);
            }
        });

        return $count;
    }

    private function normalizeModelMedia(string $table, bool $dry): int
    {
        $hasImageUrl = Schema::hasColumn($table, 'image_url');
        $hasCover = Schema::hasColumn($table, 'image_cover');
        if (! $hasImageUrl && ! $hasCover) {
            return 0;
        }

        $count = 0;
        DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $dry, $hasImageUrl, $hasCover, &$count) {
            foreach ($rows as $row) {
                $payload = [];
                if ($hasImageUrl) {
                    $next = PublicMediaUrl::normalize($row->image_url ?? null);
                    if ($next !== null && $next !== ($row->image_url ?? null)) {
                        $payload['image_url'] = $next;
                    }
                }
                if ($hasCover) {
                    $next = PublicMediaUrl::normalize($row->image_cover ?? null);
                    if ($next !== null && $next !== ($row->image_cover ?? null)) {
                        $payload['image_cover'] = $next;
                    }
                }
                if ($payload === []) {
                    continue;
                }
                $count++;
                if ($dry) {
                    $this->line("{$table} #{$row->id}: ".json_encode($payload));
                    continue;
                }
                if (Schema::hasColumn($table, 'updated_at')) {
                    $payload['updated_at'] = now();
                }
                DB::table($table)->where('id', $row->id)->update($payload);
            }
        });

        return $count;
    }
}
