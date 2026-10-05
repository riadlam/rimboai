<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * xAI Grok Imagine Video 1.5 for Video Lab (T2V + I2V + R2V).
 *
 * Fal (verified):
 * - T2V: xai/grok-imagine-video/v1.5/text-to-video
 *        duration 1–15, resolution 480p|720p|1080p, aspect 16:9|4:3|3:2|1:1|2:3|3:4|9:16
 * - I2V: xai/grok-imagine-video/v1.5/image-to-video
 *        + image_url; no aspect_ratio (follows source); 480p|720p|1080p; 1–15s
 * - R2V: xai/grok-imagine-video/v1.5/reference-to-video
 *        reference_image_urls (1–7) tagged <IMAGE_0>…; 480p|720p; 1–15s
 * - Native audio always on (no generate_audio field)
 * - Pricing: 480p $0.08/s, 720p $0.14/s, 1080p $0.25/s
 *
 * @see https://fal.ai/models/xai/grok-imagine-video/v1.5/text-to-video
 * @see https://fal.ai/models/xai/grok-imagine-video/v1.5/image-to-video
 * @see https://fal.ai/models/xai/grok-imagine-video/v1.5/reference-to-video
 */
class GrokImagineVideo15Seeder extends Seeder
{
    private const T2V = 'xai/grok-imagine-video/v1.5/text-to-video';

    private const I2V = 'xai/grok-imagine-video/v1.5/image-to-video';

    private const R2V = 'xai/grok-imagine-video/v1.5/reference-to-video';

    private const ICON = '/storage/ai_icons/grok.svg';

    /** @var list<int> */
    private const DURATIONS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15];

    /** @var list<string> */
    private const ASPECTS = ['16:9', '4:3', '3:2', '1:1', '2:3', '3:4', '9:16'];

    /** @var list<string> */
    private const RESOLUTIONS_T2V_I2V = ['480p', '720p', '1080p'];

    /** @var list<string> */
    private const RESOLUTIONS_R2V = ['480p', '720p'];

    public function run(): void
    {
        if (! Schema::hasTable('text_to_video_models')) {
            $this->command?->warn('text_to_video_models missing — skip Grok Imagine Video 1.5.');

            return;
        }

        $categoryId = $this->upsertXaiCategory('text_to_video_categories');
        $now = now();

        $t2v = $this->filterColumns('text_to_video_models', [
            'sort' => 55,
            'endpoint_id' => self::T2V,
            'name' => 'Grok Imagine Video 1.5',
            'description' => 'xAI Grok Imagine Video 1.5 — cinematic clips with native audio, up to 15s / 1080p. Upload one image to animate, or up to 7 refs for character-consistent R2V.',
            'image_url' => self::ICON,
            'image_cover' => self::ICON,
            'tags' => json_encode(['text-to-video', 'image-to-video', 'reference-to-video', 'xai', 'grok', 'grok-1.5', 'audio', 'new']),
            'status' => 'active',
            'unit' => 'seconds',
            // Default catalog hint @ 720p; FalEndpointPricingPolicy overrides by resolution.
            'unit_price' => 0.14,
            'supports_audio' => false, // always-on native audio; no generate_audio toggle
            'supports_first_frame' => true,
            'supports_last_frame' => false,
            'max_duration' => 15,
            'enums' => json_encode(self::DURATIONS),
            'aspect_ratios' => json_encode(self::ASPECTS),
            'resolutions' => json_encode(self::RESOLUTIONS_T2V_I2V),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('text_to_video_models')->updateOrInsert(
            ['endpoint_id' => self::T2V],
            $t2v,
        );

        if (Schema::hasTable('image_to_video_models')) {
            $i2vCategoryId = $this->upsertXaiCategory('image_to_video_categories');

            $i2v = $this->filterColumns('image_to_video_models', [
                'sort' => 55,
                'endpoint_id' => self::I2V,
                'name' => 'Grok Imagine Video 1.5 Image to Video',
                'description' => 'Animate a still with Grok Imagine Video 1.5 (image_url). Native audio, 1–15s, up to 1080p. Aspect follows the source image.',
                'image_url' => self::ICON,
                'image_cover' => self::ICON,
                'tags' => json_encode(['image-to-video', 'xai', 'grok', 'grok-1.5', 'audio', 'new']),
                'status' => 'active',
                'unit' => 'seconds',
                'unit_price' => 0.14,
                'supports_audio' => false,
                'supports_first_frame' => true,
                'supports_last_frame' => false,
                'max_duration' => 15,
                'enums' => json_encode(self::DURATIONS),
                'aspect_ratios' => json_encode(['auto', ...self::ASPECTS]),
                'resolutions' => json_encode(self::RESOLUTIONS_T2V_I2V),
                'category_id' => $i2vCategoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('image_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::I2V],
                $i2v,
            );

            $r2v = $this->filterColumns('image_to_video_models', [
                'sort' => 56,
                'endpoint_id' => self::R2V,
                'name' => 'Grok Imagine Video 1.5 Reference to Video',
                'description' => 'Multi-image reference-to-video (1–7 images). Tag <IMAGE_0>, <IMAGE_1>… in the prompt. Native audio, 1–15s, 480p/720p.',
                'image_url' => self::ICON,
                'image_cover' => self::ICON,
                'tags' => json_encode(['reference-to-video', 'multi-reference', 'xai', 'grok', 'grok-1.5', 'audio', 'new']),
                'status' => 'active',
                'unit' => 'seconds',
                'unit_price' => 0.14,
                'supports_audio' => false,
                'supports_first_frame' => false,
                'supports_last_frame' => false,
                'max_duration' => 15,
                'enums' => json_encode(self::DURATIONS),
                'aspect_ratios' => json_encode(self::ASPECTS),
                'resolutions' => json_encode(self::RESOLUTIONS_R2V),
                'category_id' => $i2vCategoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('image_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::R2V],
                $r2v,
            );

            // Pricing / route discovery sibling on text_to_video table.
            $r2vT2v = $this->filterColumns('text_to_video_models', [
                'sort' => 56,
                'endpoint_id' => self::R2V,
                'name' => 'Grok Imagine Video 1.5 Reference to Video',
                'description' => 'Multi-image R2V sibling (pricing / status sync).',
                'image_url' => self::ICON,
                'image_cover' => self::ICON,
                'tags' => json_encode(['reference-to-video', 'multi-reference', 'xai', 'grok', 'grok-1.5', 'audio']),
                'status' => 'active',
                'unit' => 'seconds',
                'unit_price' => 0.14,
                'supports_audio' => false,
                'supports_first_frame' => false,
                'supports_last_frame' => false,
                'max_duration' => 15,
                'enums' => json_encode(self::DURATIONS),
                'aspect_ratios' => json_encode(self::ASPECTS),
                'resolutions' => json_encode(self::RESOLUTIONS_R2V),
                'category_id' => $categoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('text_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::R2V],
                $r2vT2v,
            );
        }

        // Soft-relabel legacy unversioned Grok so Lab users pick 1.5 first.
        foreach ([
            'text_to_video_models' => 'xai/grok-imagine-video/text-to-video',
            'image_to_video_models' => 'xai/grok-imagine-video/image-to-video',
        ] as $table => $endpoint) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! DB::table($table)->where('endpoint_id', $endpoint)->exists()) {
                continue;
            }
            $payload = ['updated_at' => $now];
            if (Schema::hasColumn($table, 'name')) {
                $payload['name'] = $table === 'text_to_video_models'
                    ? 'Grok Imagine Video (legacy)'
                    : 'Grok Imagine Video Image to Video (legacy)';
            }
            if (Schema::hasColumn($table, 'sort')) {
                $payload['sort'] = 160;
            }
            DB::table($table)->where('endpoint_id', $endpoint)->update($payload);
        }

        $this->bustCatalogCaches();

        $this->command?->info('Seeded '.self::T2V.' (+ I2V '.self::I2V.', R2V '.self::R2V.')');
    }

    private function upsertXaiCategory(string $table): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $now = now();
        $values = $this->filterColumns($table, [
            'name' => 'xAI',
            'sort' => 30,
            'icon_url' => self::ICON,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table($table)->updateOrInsert(['name' => 'xAI'], $values);

        // Also match common alternate brand names already in DB.
        foreach (['Grok', 'xai'] as $alt) {
            if (DB::table($table)->where('name', $alt)->exists()) {
                return (int) DB::table($table)->where('name', $alt)->value('id');
            }
        }

        return (int) DB::table($table)->where('name', 'xAI')->value('id');
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
        foreach ([
            ['text_to_video_models', 'text_to_video_categories'],
            ['image_to_video_models', 'image_to_video_categories'],
        ] as [$models, $categories]) {
            Cache::forget("catalog.brands.v4.{$models}.{$categories}");
            Cache::forget("catalog.brands.v3.{$models}.{$categories}");
        }
    }
}
