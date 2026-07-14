<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds 5 music example rows per model.
 * Does NOT call fal.ai — sample_url / sample_remote_url stay empty for manual paste.
 */
class SeedMusicExamples extends Command
{
    protected $signature = 'music:seed-examples
                            {--fresh : Delete existing examples before seeding}';

    protected $description = 'Seed 5 music examples per model with empty sample URLs (no fal generation)';

    /**
     * Shared library — covers only (no audio). Audio URLs intentionally null.
     *
     * @var list<array{key: string, title: string, style: string, vocals: bool, cover: string}>
     */
    private const LIBRARY = [
        [
            'key' => 'romantic-rai',
            'title' => 'Romantic Raï',
            'style' => 'Modern romantic raï, passionate vocals, accordion and darbuka, warm night club vibe',
            'vocals' => true,
            'cover' => 'https://musicfile.kie.ai/MzBjNDAzY2MtNzc4Yy00MGZhLWFkYjMtMDRkZjhmNTg4NDI3.jpeg',
        ],
        [
            'key' => 'phonk-aggressive',
            'title' => 'Aggressive Phonk',
            'style' => 'Aggressive phonk, heavy 808s, cowbell, dark Memphis energy, instrumental',
            'vocals' => false,
            'cover' => 'https://musicfile.kie.ai/NmI1N2YxMTMtNjhjYS00Nzg0LWEzNzQtOTE2ZDJmYmI1MjBj.jpeg',
        ],
        [
            'key' => 'melodic-rock',
            'title' => 'Melodic Rock',
            'style' => 'Melodic rock anthem, soaring vocals, electric guitars, stadium drums',
            'vocals' => true,
            'cover' => 'https://musicfile.kie.ai/YWIyMWFhN2ItNGRlYi00N2QwLWFkZWMtOWFiMzA0OGQ2OGU5.jpeg',
        ],
        [
            'key' => 'kabyle-acoustique',
            'title' => 'Acoustic Kabyle',
            'style' => 'Acoustic Kabyle folk, intimate vocals, nylon guitar, hand percussion',
            'vocals' => true,
            'cover' => 'https://musicfile.kie.ai/MjI1N2E4NzUtMTQ0Mi00MTJlLTgzOGUtMzUyY2M1N2Q4NzE3.jpeg',
        ],
        [
            'key' => 'trap-algerien',
            'title' => 'Algerian Trap',
            'style' => 'Algerian trap, hard-hitting 808s, street vocals, urban North African vibe',
            'vocals' => true,
            'cover' => 'https://musicfile.kie.ai/YjUyOGVlNzctODc2My00NTg0LWI0YzgtNTQxNjhkMDViZmU3.jpeg',
        ],
        [
            'key' => 'deep-house-sahara',
            'title' => 'Sahara Deep House',
            'style' => 'Sahara deep house, sandy textures, hypnotic groove, instrumental',
            'vocals' => false,
            'cover' => 'https://musicfile.kie.ai/ZDExNmY3YWItM2M4Zi00MGM2LWJmYjgtMjk0NzUzZDZkNGI2.jpeg',
        ],
        [
            'key' => 'tiktok-viral-pop',
            'title' => 'TikTok Viral Pop',
            'style' => 'Catchy TikTok viral pop, hooky chorus, bright synths, danceable',
            'vocals' => true,
            'cover' => 'https://musicfile.kie.ai/NGM1YzE0MWQtYzg4My00MWMyLTllYTgtZmFjNDliY2ZkMTM0.jpeg',
        ],
        [
            'key' => 'chaabi-electrique',
            'title' => 'Electric Chaabi',
            'style' => 'Electric chaabi, festive Algerian energy, synths and traditional rhythms',
            'vocals' => true,
            'cover' => 'https://musicfile.kie.ai/MTk0YzgzMTMtY2VlNS00NjM1LWI5YmUtMmNjMmMxYWE0NzFm.jpeg',
        ],
        [
            'key' => 'lofi-medina',
            'title' => 'Lofi Médina',
            'style' => 'Lofi médina beats, dusty vinyl, soft oud loops, chill study vibe',
            'vocals' => false,
            'cover' => 'https://musicfile.kie.ai/Zjg3YzlkMzQtMzZjMS00Zjk1LTgwMmQtYjVhMGQ3OTAwNzFk.jpeg',
        ],
        [
            'key' => 'cinematic-orchestral',
            'title' => 'Cinematic Orchestral',
            'style' => 'Cinematic orchestral score, sweeping strings, epic brass, no vocals',
            'vocals' => false,
            'cover' => 'https://musicfile.kie.ai/MTU2MDc4ZjktYTIxMi00OTIyLTllZTMtYjFiOGNkZGY4ZjFh.jpeg',
        ],
        [
            'key' => 'studio-ballad',
            'title' => 'Studio Ballad',
            'style' => 'Intimate studio ballad, soft piano, warm female vocals, sparse arrangement',
            'vocals' => true,
            'cover' => 'https://musicfile.kie.ai/YWIyMWFhN2ItNGRlYi00N2QwLWFkZWMtOWFiMzA0OGQ2OGU5.jpeg',
        ],
        [
            'key' => 'ambient-pads',
            'title' => 'Ambient Pads',
            'style' => 'Wide ambient pads, evolving textures, cinematic atmosphere, instrumental',
            'vocals' => false,
            'cover' => 'https://musicfile.kie.ai/MTU2MDc4ZjktYTIxMi00OTIyLTllZTMtYjFiOGNkZGY4ZjFh.jpeg',
        ],
    ];

    /**
     * Exactly 5 example keys per endpoint.
     *
     * @var array<string, list<string>>
     */
    private const PER_MODEL = [
        'fal-ai/minimax-music/v2.6' => [
            'romantic-rai',
            'melodic-rock',
            'trap-algerien',
            'tiktok-viral-pop',
            'phonk-aggressive',
        ],
        'fal-ai/lyria3/pro' => [
            'melodic-rock',
            'romantic-rai',
            'studio-ballad',
            'cinematic-orchestral',
            'deep-house-sahara',
        ],
        'fal-ai/elevenlabs/music' => [
            'studio-ballad',
            'tiktok-viral-pop',
            'melodic-rock',
            'deep-house-sahara',
            'lofi-medina',
        ],
        'cassetteai/music-generator' => [
            'phonk-aggressive',
            'deep-house-sahara',
            'lofi-medina',
            'cinematic-orchestral',
            'ambient-pads',
        ],
        'fal-ai/stable-audio-25/text-to-audio' => [
            'cinematic-orchestral',
            'ambient-pads',
            'lofi-medina',
            'deep-house-sahara',
            'phonk-aggressive',
        ],
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('text_to_music_examples') || ! Schema::hasTable('text_to_music_models')) {
            $this->error('Music tables missing. Run migrations first.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            DB::table('text_to_music_examples')->delete();
            $this->warn('Cleared existing music examples.');
        }

        $byKey = collect(self::LIBRARY)->keyBy('key');
        $models = DB::table('text_to_music_models')->orderBy('sort')->get(['id', 'endpoint_id', 'name', 'supports_vocals']);
        $seeded = 0;

        foreach ($models as $model) {
            $endpointId = (string) ($model->endpoint_id ?? '');
            $keys = self::PER_MODEL[$endpointId] ?? null;

            if ($keys === null) {
                // Fallback: 5 instrumental or mixed from library
                $supportsVocals = (bool) ($model->supports_vocals ?? false);
                $pool = collect(self::LIBRARY)
                    ->when(! $supportsVocals, fn ($c) => $c->where('vocals', false))
                    ->take(5)
                    ->pluck('key')
                    ->all();
                $keys = $pool;
            }

            $keys = array_slice(array_values($keys), 0, 5);

            foreach ($keys as $i => $key) {
                $item = $byKey->get($key);
                if (! is_array($item)) {
                    continue;
                }

                $payload = [
                    'title' => $item['title'],
                    'style' => $item['style'],
                    'description' => null,
                    'vocals' => (bool) $item['vocals'],
                    'cover_url' => $item['cover'],
                    // Intentionally empty — paste audio URLs later; never call fal here
                    'sample_remote_url' => null,
                    'sample_url' => null,
                    'sample_path' => null,
                    'tags' => json_encode($item['vocals'] ? ['Vocals'] : ['Instrumental']),
                    'sort' => ($i + 1) * 10,
                    'updated_at' => now(),
                ];

                $existing = DB::table('text_to_music_examples')
                    ->where('text_to_music_model_id', $model->id)
                    ->where('example_key', $key)
                    ->first();

                if ($existing) {
                    // Preserve any URLs the user already pasted
                    unset($payload['sample_remote_url'], $payload['sample_url'], $payload['sample_path']);
                    DB::table('text_to_music_examples')->where('id', $existing->id)->update($payload);
                } else {
                    $payload['text_to_music_model_id'] = $model->id;
                    $payload['example_key'] = $key;
                    $payload['created_at'] = now();
                    DB::table('text_to_music_examples')->insert($payload);
                }

                $seeded++;
            }

            $this->line(sprintf('  ✓ %s — %d examples (sample_url empty)', $model->name ?: $endpointId, count($keys)));
        }

        $this->info("Seeded/updated {$seeded} example rows. No fal.ai calls were made.");

        return self::SUCCESS;
    }
}
