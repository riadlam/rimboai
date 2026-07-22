<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google Gemini Omni Flash for Video Lab (T2V + I2V + R2V + Edit).
 *
 * Fal requirements (verified against OpenAPI):
 * - T2V:  google/gemini-omni-flash                 — prompt, aspect_ratio (16:9|9:16), duration int 3–10
 * - I2V:  google/gemini-omni-flash/image-to-video  — + image_url
 * - R2V:  google/gemini-omni-flash/reference-to-video — prompt + image_urls (1–10); optional <IMAGE_REF_N>
 * - Edit: google/gemini-omni-flash/edit            — prompt + video_url only (no duration/aspect)
 * - Audio is always baked into the output (no generate_audio field)
 * - Pricing ≈ $0.125/s (T2V) / $0.13/s (I2V, R2V, Edit) @ 720p
 *
 * @see https://fal.ai/models/google/gemini-omni-flash
 * @see https://fal.ai/models/google/gemini-omni-flash/reference-to-video
 * @see https://fal.ai/models/google/gemini-omni-flash/edit
 */
class GeminiOmniFlashSeeder extends Seeder
{
    private const T2V = 'google/gemini-omni-flash';

    private const I2V = 'google/gemini-omni-flash/image-to-video';

    private const R2V = 'google/gemini-omni-flash/reference-to-video';

    private const EDIT = 'google/gemini-omni-flash/edit';

    private const ICON = '/storage/ai_icons/gemini-color.svg';

    /** @var list<int> */
    private const DURATIONS = [3, 4, 5, 6, 7, 8, 9, 10];

    /** @var list<string> */
    private const ASPECTS = ['16:9', '9:16'];

    public function run(): void
    {
        if (! Schema::hasTable('text_to_video_models')) {
            $this->command?->warn('text_to_video_models missing — skip Gemini Omni Flash.');

            return;
        }

        $categoryId = $this->upsertGoogleCategory('text_to_video_categories');
        $now = now();

        $t2v = $this->filterColumns('text_to_video_models', [
            'sort' => 85,
            'endpoint_id' => self::T2V,
            'name' => 'Gemini Omni Flash',
            'description' => 'Google Gemini Omni Flash — text-to-video with synced audio. Upload images for reference-to-video, or one video to edit. 3–10s at 16:9 or 9:16.',
            'image_url' => self::ICON,
            'image_cover' => self::ICON,
            'tags' => json_encode(['text-to-video', 'reference-to-video', 'edit', 'google', 'gemini', 'omni-flash', 'audio']),
            'status' => 'active',
            'unit' => 'seconds',
            // Fal T2V ≈ $0.125 / s @ 720p (token billing normalized to per-second).
            'unit_price' => 0.125,
            'supports_audio' => false, // always-on audio; no generate_audio toggle
            'supports_first_frame' => true,
            'supports_last_frame' => false,
            'max_duration' => 10,
            'enums' => json_encode(self::DURATIONS),
            'aspect_ratios' => json_encode(self::ASPECTS),
            'resolutions' => json_encode(['720p']),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('text_to_video_models')->updateOrInsert(
            ['endpoint_id' => self::T2V],
            $t2v,
        );

        // Visible Edit catalog entry (like Kling O3 Edit) — video + prompt.
        $edit = $this->filterColumns('text_to_video_models', [
            'sort' => 86,
            'endpoint_id' => self::EDIT,
            'name' => 'Gemini Omni Flash Edit',
            'description' => 'Edit an existing clip with a text instruction (e.g. “Make this anime. Keep everything else the same.”). Upload 1 video. Note: EEA/UK/CH upload edit may be restricted by Fal/Google.',
            'image_url' => self::ICON,
            'image_cover' => self::ICON,
            'tags' => json_encode(['video-to-video', 'edit', 'google', 'gemini', 'omni-flash', 'audio']),
            'status' => 'active',
            'unit' => 'seconds',
            'unit_price' => 0.13,
            'supports_audio' => false,
            'supports_first_frame' => false,
            'supports_last_frame' => false,
            'max_duration' => 10,
            'enums' => json_encode(self::DURATIONS),
            'aspect_ratios' => json_encode(self::ASPECTS),
            'resolutions' => json_encode(['720p']),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('text_to_video_models')->updateOrInsert(
            ['endpoint_id' => self::EDIT],
            $edit,
        );

        // I2V + R2V billing/catalog siblings — FalPricingService resolves submit endpoints here.
        if (Schema::hasTable('image_to_video_models')) {
            $i2vCategoryId = $this->upsertGoogleCategory('image_to_video_categories');

            $i2v = $this->filterColumns('image_to_video_models', [
                'sort' => 85,
                'endpoint_id' => self::I2V,
                'name' => 'Gemini Omni Flash Image to Video',
                'description' => 'Animate a still with Gemini Omni Flash (image_url). Synced audio, 3–10s, 16:9 / 9:16.',
                'image_url' => self::ICON,
                'image_cover' => self::ICON,
                'tags' => json_encode(['image-to-video', 'google', 'gemini', 'omni-flash', 'audio']),
                'status' => 'active',
                'unit' => 'seconds',
                'unit_price' => 0.13,
                'supports_audio' => false,
                'supports_first_frame' => true,
                'supports_last_frame' => false,
                'max_duration' => 10,
                'enums' => json_encode(self::DURATIONS),
                'aspect_ratios' => json_encode(self::ASPECTS),
                'resolutions' => json_encode(['720p']),
                'category_id' => $i2vCategoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('image_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::I2V],
                $i2v,
            );

            $r2v = $this->filterColumns('image_to_video_models', [
                'sort' => 86,
                'endpoint_id' => self::R2V,
                'name' => 'Gemini Omni Flash Reference to Video',
                'description' => 'Multi-image reference-to-video (1–10 images). Bind with <IMAGE_REF_0> in the prompt. Synced audio, 3–10s.',
                'image_url' => self::ICON,
                'image_cover' => self::ICON,
                'tags' => json_encode(['reference-to-video', 'multi-reference', 'google', 'gemini', 'omni-flash', 'audio']),
                'status' => 'active',
                'unit' => 'seconds',
                'unit_price' => 0.13,
                'supports_audio' => false,
                'supports_first_frame' => false,
                'supports_last_frame' => false,
                'max_duration' => 10,
                'enums' => json_encode(self::DURATIONS),
                'aspect_ratios' => json_encode(self::ASPECTS),
                'resolutions' => json_encode(['720p']),
                'category_id' => $i2vCategoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('image_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::R2V],
                $r2v,
            );

            // Also keep R2V/Edit prices discoverable from text_to_video if pricing looks there first after I2V.
            $r2vT2v = $this->filterColumns('text_to_video_models', [
                'sort' => 87,
                'endpoint_id' => self::R2V,
                'name' => 'Gemini Omni Flash Reference to Video',
                'description' => 'Multi-image reference-to-video sibling (pricing / status sync).',
                'image_url' => self::ICON,
                'image_cover' => self::ICON,
                'tags' => json_encode(['reference-to-video', 'multi-reference', 'google', 'gemini', 'omni-flash', 'audio']),
                'status' => 'active',
                'unit' => 'seconds',
                'unit_price' => 0.13,
                'supports_audio' => false,
                'supports_first_frame' => false,
                'supports_last_frame' => false,
                'max_duration' => 10,
                'enums' => json_encode(self::DURATIONS),
                'aspect_ratios' => json_encode(self::ASPECTS),
                'resolutions' => json_encode(['720p']),
                'category_id' => $categoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('text_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::R2V],
                $r2vT2v,
            );
        }

        $this->bustCatalogCaches();

        $this->command?->info('Seeded '.self::T2V.' (+ I2V '.self::I2V.', R2V '.self::R2V.', Edit '.self::EDIT.')');
    }

    private function upsertGoogleCategory(string $table): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $now = now();
        $values = $this->filterColumns($table, [
            'name' => 'Google',
            'sort' => 25,
            'icon_url' => '/storage/ai_icons/google-color.svg',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table($table)->updateOrInsert(['name' => 'Google'], $values);

        return (int) DB::table($table)->where('name', 'Google')->value('id');
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
