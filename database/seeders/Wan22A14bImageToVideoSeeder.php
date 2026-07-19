<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fal Wan v2.2 A14B image-to-video for Video Lab.
 *
 * @see https://fal.ai/models/fal-ai/wan/v2.2-a14b/image-to-video
 */
class Wan22A14bImageToVideoSeeder extends Seeder
{
    private const ENDPOINT = 'fal-ai/wan/v2.2-a14b/image-to-video';

    public function run(): void
    {
        if (! Schema::hasTable('text_to_video_models')) {
            $this->command?->warn('text_to_video_models missing — skip Wan 2.2 A14B I2V.');

            return;
        }

        $categoryId = $this->upsertWanCategory();
        $now = now();

        $payload = [
            'sort' => 148,
            'endpoint_id' => self::ENDPOINT,
            'name' => 'Wan 2.2 A14B Image to Video',
            'description' => 'Wan 2.2 A14B image-to-video — animate a still with strong motion control, negative prompt, and 480p/580p/720p output.',
            'image_url' => '/storage/ai_icons/alibaba-color.svg',
            'image_cover' => '/storage/ai_icons/alibaba-color.svg',
            'tags' => json_encode(['image-to-video', 'wan', 'a14b', 'negative-prompt']),
            'status' => 'active',
            'unit' => 'seconds',
            // Fal: $0.08 / video-second at 720p (billed at 16 fps).
            'unit_price' => 0.08,
            'supports_audio' => false,
            'max_duration' => 10,
            // UI seconds → num_frames at 16 fps (17–161 frames ≈ 1–10s).
            'enums' => json_encode([2, 3, 4, 5, 6, 7, 8, 9, 10]),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('text_to_video_models', 'supports_first_frame')) {
            $payload['supports_first_frame'] = true;
        }
        if (Schema::hasColumn('text_to_video_models', 'supports_last_frame')) {
            $payload['supports_last_frame'] = false;
        }

        $values = array_filter(
            $payload,
            static fn (string $column): bool => Schema::hasColumn('text_to_video_models', $column),
            ARRAY_FILTER_USE_KEY,
        );

        DB::table('text_to_video_models')->updateOrInsert(
            ['endpoint_id' => self::ENDPOINT],
            $values,
        );

        // Keep billing / OpenAPI sync tables in sync when present.
        if (Schema::hasTable('image_to_video_models')) {
            $i2vValues = $values;
            if (Schema::hasColumn('image_to_video_models', 'category_id') && $categoryId) {
                $i2vCat = $this->upsertWanCategory('image_to_video_categories');
                $i2vValues['category_id'] = $i2vCat;
            }
            $i2vValues = array_filter(
                $i2vValues,
                static fn (string $column): bool => Schema::hasColumn('image_to_video_models', $column),
                ARRAY_FILTER_USE_KEY,
            );
            DB::table('image_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::ENDPOINT],
                $i2vValues,
            );
        }

        $this->command?->info('Seeded '.self::ENDPOINT);
    }

    private function upsertWanCategory(string $table = 'text_to_video_categories'): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $now = now();
        $values = array_filter(
            [
                'name' => 'Wan',
                'sort' => 50,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );

        DB::table($table)->updateOrInsert(['name' => 'Wan'], $values);

        return (int) DB::table($table)->where('name', 'Wan')->value('id');
    }
}
