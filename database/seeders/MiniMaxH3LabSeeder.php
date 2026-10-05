<?php

namespace Database\Seeders;

use App\Services\CatalogCache;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MiniMax H3 for Video Lab (T2V + I2V + R2V).
 *
 * Fal (verified):
 * - T2V: minimax/h3/text-to-video — 5–15s, 480P|768P|2K|4K, aspects 21:9…9:16
 * - I2V: minimax/h3/image-to-video — image_url + optional end_image_url (FLF); aspect follows source
 * - R2V: minimax/h3/reference-to-video — up to 9 images, 3 videos (2–15s each, combined ≤15s),
 *        3 audios (2–15s each, combined ≤15s); max 12 files total; cite Image N / Video N / Audio N
 * - Native audio on outputs; pricing $0.05/$0.06/$0.13/$0.16 per s (+$0.08/image after first 5 on R2V)
 *
 * @see https://fal.ai/models/minimax/h3/text-to-video
 * @see https://fal.ai/models/minimax/h3/image-to-video
 * @see https://fal.ai/models/minimax/h3/reference-to-video
 */
class MiniMaxH3LabSeeder extends Seeder
{
    private const T2V = 'minimax/h3/text-to-video';

    private const I2V = 'minimax/h3/image-to-video';

    private const R2V = 'minimax/h3/reference-to-video';

    private const ICON = '/storage/ai_icons/minimax-color.svg';

    /** @var list<int> */
    private const DURATIONS = [5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];

    /** @var list<string> */
    private const ASPECTS_T2V_R2V = ['adaptive', '21:9', '16:9', '4:3', '1:1', '3:4', '9:16'];

    /** @var list<string> */
    private const RESOLUTIONS = ['480p', '768p', '2K', '4K'];

    public function run(): void
    {
        if (! Schema::hasTable('text_to_video_models')) {
            $this->command?->warn('text_to_video_models missing — skip MiniMax H3.');

            return;
        }

        $categoryId = $this->upsertMiniMaxCategory('text_to_video_categories');
        $now = now();

        $t2v = $this->filterColumns('text_to_video_models', [
            'sort' => 48,
            'endpoint_id' => self::T2V,
            'name' => 'MiniMax H3',
            'description' => 'MiniMax H3 — 5–15s at up to 2K/4K with native audio. Upload product images + a motion reference clip (2–15s each, max 15s combined) for Kapwing-style remakes. Prompt with Image 1 / Video 1.',
            'image_url' => self::ICON,
            'image_cover' => self::ICON,
            'tags' => json_encode(['text-to-video', 'image-to-video', 'reference-to-video', 'minimax', 'h3', 'hailuo', 'multimodal', 'audio', 'new']),
            'status' => 'active',
            'unit' => 'seconds',
            // Default catalog hint @ 768P; FalEndpointPricingPolicy overrides by resolution.
            'unit_price' => 0.06,
            'supports_audio' => false, // native audio always on; no generate_audio toggle
            'supports_first_frame' => true,
            'supports_last_frame' => true,
            'max_duration' => 15,
            'enums' => json_encode(self::DURATIONS),
            'aspect_ratios' => json_encode(self::ASPECTS_T2V_R2V),
            'resolutions' => json_encode(self::RESOLUTIONS),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('text_to_video_models')->updateOrInsert(
            ['endpoint_id' => self::T2V],
            $t2v,
        );

        $r2vT2v = $this->filterColumns('text_to_video_models', [
            'sort' => 49,
            'endpoint_id' => self::R2V,
            'name' => 'MiniMax H3 Reference to Video',
            'description' => 'Best for prompt-library remakes: up to 9 images + 3 motion videos (2–15s each, combined ≤15s) + 3 audios. Cite Image N / Video N / Audio N. Output 5–15s.',
            'image_url' => self::ICON,
            'image_cover' => self::ICON,
            'tags' => json_encode(['reference-to-video', 'multi-reference', 'multimodal', 'minimax', 'h3', 'hailuo', 'trends', 'audio', 'new']),
            'status' => 'active',
            'unit' => 'seconds',
            'unit_price' => 0.06,
            'supports_audio' => false,
            'supports_first_frame' => false,
            'supports_last_frame' => false,
            'max_duration' => 15,
            'enums' => json_encode(self::DURATIONS),
            'aspect_ratios' => json_encode(self::ASPECTS_T2V_R2V),
            'resolutions' => json_encode(self::RESOLUTIONS),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('text_to_video_models')->updateOrInsert(
            ['endpoint_id' => self::R2V],
            $r2vT2v,
        );

        if (Schema::hasTable('image_to_video_models')) {
            $i2vCategoryId = $this->upsertMiniMaxCategory('image_to_video_categories');

            $i2v = $this->filterColumns('image_to_video_models', [
                'sort' => 48,
                'endpoint_id' => self::I2V,
                'name' => 'MiniMax H3 Image to Video',
                'description' => 'Animate a still (or first→last frame) with MiniMax H3. Native audio, 5–15s, up to 4K. Aspect follows the source image.',
                'image_url' => self::ICON,
                'image_cover' => self::ICON,
                'tags' => json_encode(['image-to-video', 'first-last-frame', 'minimax', 'h3', 'hailuo', 'audio', 'new']),
                'status' => 'active',
                'unit' => 'seconds',
                'unit_price' => 0.06,
                'supports_audio' => false,
                'supports_first_frame' => true,
                'supports_last_frame' => true,
                'max_duration' => 15,
                'enums' => json_encode(self::DURATIONS),
                'aspect_ratios' => json_encode(['auto', ...array_values(array_filter(
                    self::ASPECTS_T2V_R2V,
                    static fn (string $a): bool => $a !== 'adaptive',
                ))]),
                'resolutions' => json_encode(self::RESOLUTIONS),
                'category_id' => $i2vCategoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('image_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::I2V],
                $i2v,
            );

            $r2vI2v = $this->filterColumns('image_to_video_models', [
                'sort' => 49,
                'endpoint_id' => self::R2V,
                'name' => 'MiniMax H3 Reference to Video',
                'description' => 'R2V sibling (pricing / status sync): 9 images · 3 videos (2–15s, ≤15s total) · 3 audios · max 12 files.',
                'image_url' => self::ICON,
                'image_cover' => self::ICON,
                'tags' => json_encode(['reference-to-video', 'multi-reference', 'multimodal', 'minimax', 'h3', 'hailuo', 'trends', 'audio']),
                'status' => 'active',
                'unit' => 'seconds',
                'unit_price' => 0.06,
                'supports_audio' => false,
                'supports_first_frame' => false,
                'supports_last_frame' => false,
                'max_duration' => 15,
                'enums' => json_encode(self::DURATIONS),
                'aspect_ratios' => json_encode(self::ASPECTS_T2V_R2V),
                'resolutions' => json_encode(self::RESOLUTIONS),
                'category_id' => $i2vCategoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('image_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::R2V],
                $r2vI2v,
            );
        }

        $this->bustCatalogCaches();

        $this->command?->info('Seeded '.self::T2V.' (+ I2V '.self::I2V.', R2V '.self::R2V.')');
    }

    private function upsertMiniMaxCategory(string $table): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $now = now();
        $values = $this->filterColumns($table, [
            'name' => 'MiniMax',
            'sort' => 30,
            'icon_url' => self::ICON,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table($table)->updateOrInsert(['name' => 'MiniMax'], $values);

        foreach (['Hailuo', 'MiniMax Hailuo'] as $alt) {
            if (DB::table($table)->where('name', $alt)->exists()) {
                return (int) DB::table($table)->where('name', $alt)->value('id');
            }
        }

        return (int) DB::table($table)->where('name', 'MiniMax')->value('id');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterColumns(string $table, array $payload): array
    {
        return array_filter(
            $payload,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function bustCatalogCaches(): void
    {
        CatalogCache::forgetBrands();
    }
}
