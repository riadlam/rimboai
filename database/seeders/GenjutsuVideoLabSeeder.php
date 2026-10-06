<?php

namespace Database\Seeders;

use App\Services\CatalogCache;
use App\Services\HiggsfieldService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Higgsfield Genjutsu for Video Lab (Motion Transfer + Restyle).
 *
 * Motion Transfer: video_url + image_urls (1–8); resolution 480p|720p|1080p.
 * Restyle: video_url + preset_id; optional image_urls (0–5); same resolutions.
 * Duration/aspect follow the source video (4–30s). Pricing: ceil(input s) × $/s.
 *
 * @see https://open.higgsfield.ai/models/higgsfield/genjutsu/motion-transfer/v1.0/api-reference
 * @see https://open.higgsfield.ai/models/higgsfield/genjutsu/restyle/v1.0/api-reference
 */
class GenjutsuVideoLabSeeder extends Seeder
{
    private const MOTION = HiggsfieldService::GENJUTSU_MOTION_TRANSFER;

    private const RESTYLE = HiggsfieldService::GENJUTSU_RESTYLE;

    private const ICON = '/storage/ai_icons/higgsfield.svg';

    /** @var list<string> */
    private const RESOLUTIONS = ['480p', '720p', '1080p'];

    public function run(): void
    {
        if (! Schema::hasTable('text_to_video_models')) {
            $this->command?->warn('text_to_video_models missing — skip Genjutsu Video Lab.');

            return;
        }

        $categoryId = $this->upsertHiggsfieldCategory('text_to_video_categories');
        $now = now();

        $motion = $this->filterColumns('text_to_video_models', [
            'sort' => 40,
            'endpoint_id' => self::MOTION,
            'name' => 'Genjutsu Motion Transfer',
            'description' => 'Higgsfield Genjutsu — transfer motion from a 4–30s clip onto 1–8 character/product images. Output length and framing follow the source video. Resolutions: 480p / 720p / 1080p.',
            'image_url' => self::ICON,
            'image_cover' => self::ICON,
            'tags' => json_encode([
                'reference-to-video',
                'higgsfield',
                'genjutsu',
                'motion',
                'motion-transfer',
                'new',
            ]),
            'status' => 'active',
            'unit' => 'seconds',
            'unit_price' => 0.681,
            'supports_audio' => false,
            'supports_first_frame' => false,
            'supports_last_frame' => false,
            'max_duration' => 30,
            'enums' => json_encode([]),
            'aspect_ratios' => json_encode(['auto']),
            'resolutions' => json_encode(self::RESOLUTIONS),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('text_to_video_models')->updateOrInsert(
            ['endpoint_id' => self::MOTION],
            $motion,
        );

        $restyle = $this->filterColumns('text_to_video_models', [
            'sort' => 41,
            'endpoint_id' => self::RESTYLE,
            'name' => 'Genjutsu Restyle',
            'description' => 'Higgsfield Genjutsu Restyle — apply a visual style preset to a 4–30s clip while keeping motion and (when present) source audio. Optional 0–5 character reference images.',
            'image_url' => self::ICON,
            'image_cover' => self::ICON,
            'tags' => json_encode([
                'reference-to-video',
                'higgsfield',
                'genjutsu',
                'restyle',
                'new',
            ]),
            'status' => 'active',
            'unit' => 'seconds',
            'unit_price' => 0.681,
            'supports_audio' => false,
            'supports_first_frame' => false,
            'supports_last_frame' => false,
            'max_duration' => 30,
            'enums' => json_encode([]),
            'aspect_ratios' => json_encode(['auto']),
            'resolutions' => json_encode(self::RESOLUTIONS),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('text_to_video_models')->updateOrInsert(
            ['endpoint_id' => self::RESTYLE],
            $restyle,
        );

        CatalogCache::forgetBrands();

        $this->command?->info('Seeded '.self::MOTION.' + '.self::RESTYLE);
    }

    private function upsertHiggsfieldCategory(string $table): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $now = now();
        $values = $this->filterColumns($table, [
            'name' => 'Higgsfield',
            'sort' => 25,
            'icon_url' => self::ICON,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table($table)->updateOrInsert(['name' => 'Higgsfield'], $values);

        return (int) DB::table($table)->where('name', 'Higgsfield')->value('id');
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
}
