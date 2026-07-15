<?php

namespace App\Http\Controllers;

use App\Models\Innovation;
use App\Models\InnovationCategory;
use App\Services\FalImageInputBuilder;
use App\Services\ToolsService;
use App\Services\TrendsFeedService;
use App\Services\VideoModelCapabilities;
use App\Services\VoiceUseCaseClassifier;
use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Home', [
            'tools' => ToolsService::all(),
            'brands' => $this->loadBrands('text_to_video_models', 'text_to_video_categories'),
        ]);
    }

    public function history(): Response
    {
        return Inertia::render('History');
    }

    public function trends(TrendsFeedService $trends): Response
    {
        $templates = $trends->feed();

        return Inertia::render('Trends', [
            'templates' => $templates,
        ]);
    }

    public function innovation(): Response
    {
        return Inertia::render('Innovation', [
            'categories' => $this->loadInnovationCategories(),
            'posts' => $this->loadInnovations(),
        ]);
    }

    public function showPost(string $id): Response
    {
        $post = null;
        if (Schema::hasTable('innovations')) {
            $row = Innovation::query()
                ->with('category')
                ->active()
                ->where('slug', $id)
                ->first();
            $post = $row?->toFrontend();
        }

        return Inertia::render('InnovationPost', [
            'id' => $id,
            'post' => $post,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadInnovationCategories(): array
    {
        if (! Schema::hasTable('innovation_categories')) {
            return [];
        }

        return InnovationCategory::query()
            ->active()
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (InnovationCategory $c) => [
                'id' => $c->slug,
                'slug' => $c->slug,
                'name' => $c->name,
                'icon' => $c->icon,
                'gradient' => $c->gradient,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadInnovations(): array
    {
        if (! Schema::hasTable('innovations')) {
            return [];
        }

        return Innovation::query()
            ->with('category')
            ->active()
            ->orderBy('sort')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (Innovation $post) => $post->toFrontend())
            ->values()
            ->all();
    }

    public function settings(): Response
    {
        return Inertia::render('Settings');
    }

    public function tools(): Response
    {
        return Inertia::render('Tools', [
            'tools' => ToolsService::all(),
        ]);
    }

    public function pricing(): Response
    {
        return Inertia::render('Pricing');
    }

    public function showTool(Request $request): Response
    {
        $routeName = $request->route()->getName();
        $tools = collect(ToolsService::all())->keyBy('route');
        $tool = $tools[$routeName] ?? abort(404);

        return Inertia::render('ToolDetail', [
            'tool' => $tool,
        ]);
    }

    public function lab(Request $request): Response
    {
        $type = $request->query('type', 'text-to-video');

        $config = match ($type) {
            'text-to-voice', 'image-to-video' => [
                'title' => 'Voiceover',
                'placeholder' => 'Enter the text you want to convert to speech...',
                'models' => 'text_to_voice_models',
                'categories' => 'text_to_voice_categories',
                'resolved' => 'text-to-voice',
            ],
            'text-to-image' => [
                'title' => 'Text to Image',
                'placeholder' => 'e.g. A cat is sitting on a table. We support all languages.',
                'models' => 'text_to_image_models',
                'categories' => 'text_to_image_categories',
                'resolved' => 'text-to-image',
            ],
            'text-to-music', 'text-to-sound' => [
                'title' => 'Music Studio',
                'placeholder' => 'Type the style of song you want to generate...',
                'models' => 'text_to_music_models',
                'categories' => 'text_to_music_categories',
                'resolved' => 'text-to-music',
            ],
            default => [
                'title' => 'Text to Video',
                'placeholder' => 'Describe the video...',
                'models' => 'text_to_video_models',
                'categories' => 'text_to_video_categories',
                'resolved' => 'text-to-video',
            ],
        };

        return Inertia::render('Lab', [
            'type' => $config['resolved'],
            'title' => $config['title'],
            'backHref' => '/',
            'placeholder' => $config['placeholder'],
            'brands' => $this->loadBrands($config['models'], $config['categories']),
            'creditsConfig' => [
                'markup' => (float) config('credits.markup', 1.25),
                'usd_per_credit' => (float) config('credits.usd_per_credit', 0.01),
            ],
        ]);
    }

    private function loadBrands(string $modelsTable, string $categoriesTable, ?FalImageInputBuilder $imageInputBuilder = null): \Illuminate\Support\Collection
    {
        try {
            $categoryColumns = DB::getSchemaBuilder()->getColumnListing($categoriesTable);
            $categoriesQuery = DB::table($categoriesTable);
            if (in_array('sort', $categoryColumns, true)) {
                $categoriesQuery->orderBy('sort')->orderBy('name');
            } else {
                $categoriesQuery->orderBy('name');
            }
            $categories = $categoriesQuery->get();
        } catch (\Throwable) {
            return collect();
        }

        $columns = DB::getSchemaBuilder()->getColumnListing($modelsTable);
        $selectCols = array_values(array_intersect($columns, [
            'id',
            'name',
            'description',
            'endpoint_id',
            'unit_price',
            'unit',
            'max_duration',
            'enums',
            'image_url',
            'image_cover',
            'tags',
            'status',
            'sort',
            'supports_audio',
            'supports_vocals',
            'supports_lyrics',
            'supports_instrumental',
            'max_lyrics_chars',
            'max_prompt_chars',
            'default_duration_seconds',
        ]));

        if ($selectCols === []) {
            return collect();
        }

        $hasStatus = in_array('status', $columns, true);
        $hasImageUrl = in_array('image_url', $columns, true);
        $hasTags = in_array('tags', $columns, true);
        $hasSort = in_array('sort', $columns, true);
        $hasSupportsAudio = in_array('supports_audio', $columns, true);
        $hasSupportsVocals = in_array('supports_vocals', $columns, true);
        $hasSupportsLyrics = in_array('supports_lyrics', $columns, true);
        $hasSupportsInstrumental = in_array('supports_instrumental', $columns, true);
        $hasMaxLyricsChars = in_array('max_lyrics_chars', $columns, true);
        $hasMaxPromptChars = in_array('max_prompt_chars', $columns, true);
        $hasDefaultDurationSeconds = in_array('default_duration_seconds', $columns, true);
        $isImageCatalog = $modelsTable === 'text_to_image_models';
        $isVideoCatalog = $modelsTable === 'text_to_video_models';
        $isVoiceCatalog = $modelsTable === 'text_to_voice_models';
        $isMusicCatalog = $modelsTable === 'text_to_music_models';
        $imageInputBuilder ??= $isImageCatalog ? app(FalImageInputBuilder::class) : null;
        $videoCaps = $isVideoCatalog ? app(VideoModelCapabilities::class) : null;

        $voicesByModelId = collect();
        $voiceClassifier = null;
        if ($isVoiceCatalog && Schema::hasTable('text_to_voice_voices')) {
            $voicesByModelId = DB::table('text_to_voice_voices')
                ->orderBy('sort')
                ->orderBy('name')
                ->get()
                ->groupBy('text_to_voice_model_id');
            $voiceClassifier = app(VoiceUseCaseClassifier::class);
        }

        $musicExamplesByModelId = collect();
        if ($isMusicCatalog && Schema::hasTable('text_to_music_examples')) {
            $musicExamplesByModelId = DB::table('text_to_music_examples')
                ->orderBy('sort')
                ->orderBy('id')
                ->get()
                ->groupBy('text_to_music_model_id');
        }

        return $categories
            ->map(function ($cat) use (
                $modelsTable,
                $selectCols,
                $hasStatus,
                $hasImageUrl,
                $hasTags,
                $hasSort,
                $hasSupportsAudio,
                $hasSupportsVocals,
                $hasSupportsLyrics,
                $hasSupportsInstrumental,
                $hasMaxLyricsChars,
                $hasMaxPromptChars,
                $hasDefaultDurationSeconds,
                $isImageCatalog,
                $isVideoCatalog,
                $isVoiceCatalog,
                $isMusicCatalog,
                $imageInputBuilder,
                $videoCaps,
                $voicesByModelId,
                $voiceClassifier,
                $musicExamplesByModelId,
            ) {
                $query = DB::table($modelsTable)
                    ->where('category_id', $cat->id)
                    ->select($selectCols);

                if ($hasStatus) {
                    $query->where('status', 'active');
                }

                if ($hasImageUrl) {
                    $query->whereNotNull('image_url')->where('image_url', '!=', '');
                }

                if ($hasSort) {
                    $query->orderBy('sort')->orderBy('name');
                } else {
                    $query->orderBy('name');
                }

                $models = $query
                    ->get()
                    ->map(function ($m) use (
                        $hasImageUrl,
                        $hasTags,
                        $hasSupportsAudio,
                        $hasSupportsVocals,
                        $hasSupportsLyrics,
                        $hasSupportsInstrumental,
                        $hasMaxLyricsChars,
                        $hasMaxPromptChars,
                        $hasDefaultDurationSeconds,
                        $isImageCatalog,
                        $isVideoCatalog,
                        $isVoiceCatalog,
                        $isMusicCatalog,
                        $imageInputBuilder,
                        $videoCaps,
                        $voicesByModelId,
                        $voiceClassifier,
                        $musicExamplesByModelId,
                    ) {
                        $tags = [];
                        if ($hasTags && isset($m->tags) && $m->tags !== null && $m->tags !== '') {
                            $decoded = is_string($m->tags) ? json_decode($m->tags, true) : $m->tags;
                            $tags = is_array($decoded) ? array_values($decoded) : [];
                        }

                        $icon = $hasImageUrl ? ($m->image_url ?? null) : null;
                        $icon = PublicMediaUrl::normalize(is_string($icon) ? $icon : null);

                        $imageCover = PublicMediaUrl::normalize(
                            isset($m->image_cover) && is_string($m->image_cover) ? $m->image_cover : null
                        );

                        $endpointId = $m->endpoint_id ?? '';

                        $payload = [
                            'name' => $m->name ?: ($endpointId ?: 'Untitled'),
                            'icon' => $icon,
                            'description' => $m->description ?? '',
                            'endpoint_id' => $endpointId,
                            'unit_price' => $m->unit_price ?? null,
                            'unit' => $m->unit ?? null,
                            'max_duration' => $m->max_duration ?? null,
                            'enums' => isset($m->enums) ? (is_string($m->enums) ? json_decode($m->enums, true) : $m->enums) : null,
                            'duration' => null,
                            'credits' => null,
                            'tags' => $tags,
                            'image_cover' => $imageCover,
                            'sort' => $m->sort ?? 999,
                        ];

                        if ($hasSupportsAudio) {
                            $payload['supports_audio'] = (bool) ($m->supports_audio ?? false);
                        }

                        if ($hasSupportsVocals) {
                            $payload['supports_vocals'] = (bool) ($m->supports_vocals ?? false);
                        }

                        if ($hasSupportsLyrics) {
                            $payload['supports_lyrics'] = (bool) ($m->supports_lyrics ?? false);
                        }

                        if ($hasSupportsInstrumental) {
                            $payload['supports_instrumental'] = (bool) ($m->supports_instrumental ?? true);
                        }

                        if ($hasMaxLyricsChars) {
                            $payload['max_lyrics_chars'] = isset($m->max_lyrics_chars) ? (int) $m->max_lyrics_chars : null;
                        }

                        if ($hasMaxPromptChars) {
                            $payload['max_prompt_chars'] = isset($m->max_prompt_chars) ? (int) $m->max_prompt_chars : null;
                        }

                        if ($hasDefaultDurationSeconds) {
                            $payload['default_duration_seconds'] = isset($m->default_duration_seconds)
                                ? (int) $m->default_duration_seconds
                                : null;
                        }

                        if ($isImageCatalog && $imageInputBuilder !== null && $endpointId !== '') {
                            $payload['supports_variations'] = $imageInputBuilder->supportsVariations($endpointId);
                        }

                        if ($isVideoCatalog && $videoCaps !== null && $endpointId !== '') {
                            $payload['media_capabilities'] = $videoCaps->for($endpointId);
                        }

                        if ($isVoiceCatalog) {
                            $modelId = (int) ($m->id ?? 0);
                            $voiceRows = $voicesByModelId->get($modelId)
                                ?? $voicesByModelId->get((string) $modelId)
                                ?? collect();
                            $payload['voices'] = collect($voiceRows)
                                ->map(function ($voice) use ($voiceClassifier) {
                                    $sampleUrl = PublicMediaUrl::sample(
                                        is_string($voice->sample_path ?? null) ? $voice->sample_path : null,
                                        is_string($voice->sample_url ?? null) ? $voice->sample_url : null,
                                        is_string($voice->sample_remote_url ?? null) ? $voice->sample_remote_url : null,
                                    );

                                    $voiceTags = [];
                                    if (isset($voice->tags) && $voice->tags !== null && $voice->tags !== '') {
                                        $decoded = is_string($voice->tags) ? json_decode($voice->tags, true) : $voice->tags;
                                        $voiceTags = is_array($decoded) ? array_values($decoded) : [];
                                    }

                                    $category = $voice->category ?? null;
                                    if ((! is_string($category) || $category === '') && $voiceClassifier) {
                                        $category = $voiceClassifier->classify(
                                            (string) $voice->name,
                                            $voice->description,
                                            (string) $voice->voice_key,
                                            $voiceTags,
                                        );
                                    }

                                    return [
                                        'id' => (int) $voice->id,
                                        'voice_key' => (string) $voice->voice_key,
                                        'name' => (string) $voice->name,
                                        'description' => $voice->description,
                                        'language' => $voice->language,
                                        'gender' => $voice->gender,
                                        'category' => $category ?: 'Conversational',
                                        'tags' => $voiceTags,
                                        'sample_url' => $sampleUrl,
                                        'is_default' => (bool) $voice->is_default,
                                        'sort' => (int) ($voice->sort ?? 999),
                                    ];
                                })
                                ->values()
                                ->all();
                        }

                        if ($isMusicCatalog) {
                            $modelId = (int) ($m->id ?? 0);
                            $exampleRows = $musicExamplesByModelId->get($modelId)
                                ?? $musicExamplesByModelId->get((string) $modelId)
                                ?? collect();
                            $payload['examples'] = collect($exampleRows)
                                ->map(function ($example) {
                                    $sampleUrl = PublicMediaUrl::sample(
                                        is_string($example->sample_path ?? null) ? $example->sample_path : null,
                                        is_string($example->sample_url ?? null) ? $example->sample_url : null,
                                        is_string($example->sample_remote_url ?? null) ? $example->sample_remote_url : null,
                                    );

                                    $coverUrl = PublicMediaUrl::normalize(
                                        is_string($example->cover_url ?? null) ? $example->cover_url : null
                                    );

                                    $exampleTags = [];
                                    if (isset($example->tags) && $example->tags !== null && $example->tags !== '') {
                                        $decoded = is_string($example->tags) ? json_decode($example->tags, true) : $example->tags;
                                        $exampleTags = is_array($decoded) ? array_values($decoded) : [];
                                    }

                                    return [
                                        'id' => (int) $example->id,
                                        'example_key' => (string) $example->example_key,
                                        'title' => (string) $example->title,
                                        'style' => (string) ($example->style ?? ''),
                                        'description' => $example->description,
                                        'vocals' => (bool) ($example->vocals ?? false),
                                        'cover_url' => $coverUrl,
                                        'sample_url' => $sampleUrl,
                                        'tags' => $exampleTags,
                                        'sort' => (int) ($example->sort ?? 999),
                                    ];
                                })
                                ->values()
                                ->all();
                        }

                        return $payload;
                    })
                    ->values();

                return [
                    'name' => $cat->name,
                    'icon' => PublicMediaUrl::normalize(is_string($cat->icon_url ?? null) ? $cat->icon_url : null),
                    'sort' => $cat->sort ?? 999,
                    'models' => $models,
                ];
            })
            ->filter(fn ($brand) => count($brand['models']) > 0)
            ->values();
    }
}
