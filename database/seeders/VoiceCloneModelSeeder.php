<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inserts/updates the new clone + cover catalog models.
 *
 * On the server (after deploy), run BOTH:
 *
 *   php artisan migrate --force
 *   php artisan db:seed --class=VoiceCloneModelSeeder --force
 *
 * Seeds:
 *   - fal-ai/minimax/voice-clone
 *   - fal-ai/chatterbox/text-to-speech (English / long ASCII text)
 *   - fal-ai/chatterbox/text-to-speech/multilingual (Arabic + 20+ languages)
 *   - fal-ai/minimax-music/cover
 *
 * Safe to re-run (updateOrInsert by endpoint_id).
 */
class VoiceCloneModelSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedVoiceModels();
        $this->seedMusicCover();
        $this->command?->info('VoiceCloneModelSeeder: MiniMax Voice Clone, Chatterbox EN + multilingual, MiniMax Music Cover are active.');
    }

    private function seedVoiceModels(): void
    {
        if (! Schema::hasTable('text_to_voice_categories') || ! Schema::hasTable('text_to_voice_models')) {
            return;
        }

        $now = now();
        $categories = [
            'MiniMax' => ['sort' => 20],
            'Chatterbox' => ['sort' => 55],
        ];

        $categoryIds = [];
        foreach ($categories as $name => $meta) {
            $values = $this->filterColumns('text_to_voice_categories', [
                'name' => $name,
                'sort' => $meta['sort'],
                'icon_url' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('text_to_voice_categories')->updateOrInsert(['name' => $name], $values);
            $categoryIds[$name] = (int) DB::table('text_to_voice_categories')->where('name', $name)->value('id');
        }

        $models = [
            [
                'endpoint_id' => 'fal-ai/minimax/voice-clone',
                'name' => 'MiniMax Voice Clone',
                'description' => 'Clone a voice from a 10s+ MP3/WAV sample, then speak your prompt in that identity.',
                'category' => 'MiniMax',
                'sort' => 5,
                // fal: $1.50 / clone + $0.30 / 1k preview characters (estimator adds preview)
                'unit' => 'generations',
                'unit_price' => 1.5,
                'tags' => ['voice-clone', 'sample-audio', 'popular', 'multilingual'],
            ],
            [
                // Shown in the model picker as the Chatterbox entry; language dropdown switches endpoint.
                'endpoint_id' => 'fal-ai/chatterbox/text-to-speech/multilingual',
                'name' => 'Chatterbox Voice Clone',
                'description' => 'Zero-shot voice cloning. Pick a language — English uses the long-text endpoint; other languages use multilingual (max 300 chars).',
                'category' => 'Chatterbox',
                'sort' => 10,
                'unit' => '1000 characters',
                'unit_price' => 0.025,
                'tags' => ['voice-clone', 'sample-audio', 'expressive', 'popular', 'multilingual', 'arabic'],
            ],
            [
                // Sibling endpoint selected via language = English (hidden from picker when multilingual exists).
                'endpoint_id' => 'fal-ai/chatterbox/text-to-speech',
                'name' => 'Chatterbox Voice Clone English',
                'description' => 'English/ASCII Chatterbox clone — up to 5000 characters. Selected automatically when language is English.',
                'category' => 'Chatterbox',
                'sort' => 11,
                'unit' => '1000 characters',
                'unit_price' => 0.025,
                'tags' => ['voice-clone', 'sample-audio', 'expressive', 'english-only'],
            ],
        ];

        foreach ($models as $model) {
            $values = $this->filterColumns('text_to_voice_models', [
                'sort' => $model['sort'],
                'endpoint_id' => $model['endpoint_id'],
                'name' => $model['name'],
                'description' => $model['description'],
                'image_url' => '/storage/ai_icons/logo_icon_only.png',
                'image_cover' => '/storage/ai_icons/logo_icon_only.png',
                'tags' => json_encode($model['tags']),
                'status' => 'active',
                'unit' => $model['unit'],
                'unit_price' => $model['unit_price'],
                'supports_audio' => true,
                'max_duration' => null,
                'enums' => null,
                'category_id' => $categoryIds[$model['category']] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('text_to_voice_models')->updateOrInsert(
                ['endpoint_id' => $model['endpoint_id']],
                $values,
            );
        }
    }

    private function seedMusicCover(): void
    {
        if (! Schema::hasTable('text_to_music_categories') || ! Schema::hasTable('text_to_music_models')) {
            return;
        }

        $now = now();
        $catValues = $this->filterColumns('text_to_music_categories', [
            'name' => 'MiniMax',
            'sort' => 10,
            'icon_url' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('text_to_music_categories')->updateOrInsert(['name' => 'MiniMax'], $catValues);
        $categoryId = (int) DB::table('text_to_music_categories')->where('name', 'MiniMax')->value('id');

        $values = $this->filterColumns('text_to_music_models', [
            'sort' => 15,
            'endpoint_id' => 'fal-ai/minimax-music/cover',
            'name' => 'MiniMax Music Cover',
            'description' => 'Upload a reference song (6s–6m) and generate a new cover matching its vocal/musical identity.',
            'image_url' => '/storage/ai_icons/logo_icon_only.png',
            'image_cover' => '/storage/ai_icons/logo_icon_only.png',
            'tags' => json_encode(['cover', 'reference-audio', 'vocals', 'popular']),
            'status' => 'active',
            'unit' => 'generations',
            'unit_price' => 0.03,
            'supports_vocals' => true,
            'supports_lyrics' => true,
            'supports_instrumental' => false,
            'supports_audio' => true,
            'max_lyrics_chars' => 1000,
            'max_prompt_chars' => 300,
            'default_duration_seconds' => 120,
            'supports_duration_control' => false,
            'max_duration' => 360,
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('text_to_music_models')->updateOrInsert(
            ['endpoint_id' => 'fal-ai/minimax-music/cover'],
            $values,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function filterColumns(string $table, array $values): array
    {
        return array_filter(
            $values,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
