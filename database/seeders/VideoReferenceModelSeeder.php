<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VideoReferenceModelSeeder extends Seeder
{
    /** @var array<string, array{name: string, sort: int, icon_url?: string}> */
    private const CATEGORIES = [
        'Kling' => ['name' => 'Kling', 'sort' => 10],
        'Wan' => ['name' => 'Wan', 'sort' => 50],
        'PixVerse' => ['name' => 'PixVerse', 'sort' => 70],
    ];

    /** @var list<array<string, mixed>> */
    private const TEXT_MODELS = [
        [
            'endpoint_id' => 'fal-ai/kling-video/o1/reference-to-video',
            'name' => 'Kling O1 Reference to Video',
            'description' => 'Kling O1 reference video generation with multi-element identity and style references.',
            'category' => 'Kling',
            'sort' => 35,
            'unit_price' => 0.112,
            'supports_audio' => false,
            'max_duration' => 10,
            'enums' => ['3', '4', '5', '6', '7', '8', '9', '10'],
            'tags' => ['reference-to-video', 'multi-reference', 'elements'],
        ],
        [
            'endpoint_id' => 'fal-ai/kling-video/o3/pro/text-to-video',
            'name' => 'Kling O3 Pro',
            'description' => 'Kling O3 Pro cinematic video generation with a reference-to-video sibling for multi-image elements.',
            'category' => 'Kling',
            'sort' => 40,
            'unit_price' => 0.112,
            'supports_audio' => true,
            'max_duration' => 15,
            'enums' => ['3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15'],
            'tags' => ['text-to-video', 'reference-to-video', 'multi-reference', 'audio'],
        ],
        [
            'endpoint_id' => 'fal-ai/kling-video/o3/standard/text-to-video',
            'name' => 'Kling O3 Standard',
            'description' => 'Kling O3 Standard video generation with multi-image element references.',
            'category' => 'Kling',
            'sort' => 50,
            'unit_price' => 0.084,
            'supports_audio' => true,
            'max_duration' => 15,
            'enums' => ['3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15'],
            'tags' => ['text-to-video', 'reference-to-video', 'multi-reference', 'audio'],
        ],
        [
            'endpoint_id' => 'fal-ai/kling-video/o3/4k/reference-to-video',
            'name' => 'Kling O3 4K Reference to Video',
            'description' => 'Kling O3 native 4K reference video generation with up to 7 combined element and image references.',
            'category' => 'Kling',
            'sort' => 55,
            'unit_price' => 0.42,
            'supports_audio' => true,
            'max_duration' => 15,
            'enums' => ['3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15'],
            'tags' => ['reference-to-video', 'multi-reference', 'elements', '4k', 'audio'],
        ],
        [
            'endpoint_id' => 'fal-ai/wan/v2.7/text-to-video',
            'name' => 'Wan 2.7',
            'description' => 'Wan 2.7 video generation with a reference-to-video route for multi-subject image references.',
            'category' => 'Wan',
            'sort' => 150,
            'unit_price' => 0.10,
            'supports_audio' => false,
            'max_duration' => 10,
            'enums' => [2, 3, 4, 5, 6, 7, 8, 9, 10],
            'tags' => ['text-to-video', 'reference-to-video', 'multi-reference'],
        ],
        [
            'endpoint_id' => 'fal-ai/pixverse/c1/reference-to-video',
            'name' => 'PixVerse C1 Reference to Video',
            'description' => 'PixVerse C1 character-consistent video generation with named subject and background references.',
            'category' => 'PixVerse',
            'sort' => 170,
            'unit_price' => 0.05,
            'supports_audio' => true,
            'max_duration' => 15,
            'enums' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            'tags' => ['reference-to-video', 'multi-reference', 'character-consistency', 'audio'],
        ],
    ];

    /** @var list<array<string, mixed>> */
    private const IMAGE_REFERENCE_ENDPOINTS = [
        [
            'endpoint_id' => 'fal-ai/kling-video/o1/reference-to-video',
            'name' => 'Kling O1 Reference to Video',
            'description' => 'Kling O1 reference video generation with up to 7 combined elements and image references.',
            'category' => 'Kling',
            'sort' => 85,
            'unit_price' => 0.112,
            'supports_audio' => false,
            'max_duration' => 10,
            'enums' => ['3', '4', '5', '6', '7', '8', '9', '10'],
            'tags' => ['reference-to-video', 'multi-reference', 'elements'],
        ],
        [
            'endpoint_id' => 'fal-ai/kling-video/o3/pro/reference-to-video',
            'name' => 'Kling O3 Pro Reference to Video',
            'description' => 'Kling O3 Pro reference video generation with multi-image elements.',
            'category' => 'Kling',
            'sort' => 86,
            'unit_price' => 0.112,
            'supports_audio' => true,
            'max_duration' => 15,
            'enums' => ['3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15'],
            'tags' => ['reference-to-video', 'multi-reference', 'elements', 'audio'],
        ],
        [
            'endpoint_id' => 'fal-ai/kling-video/o3/standard/reference-to-video',
            'name' => 'Kling O3 Standard Reference to Video',
            'description' => 'Kling O3 Standard reference video generation with multi-image elements.',
            'category' => 'Kling',
            'sort' => 87,
            'unit_price' => 0.084,
            'supports_audio' => true,
            'max_duration' => 15,
            'enums' => ['3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15'],
            'tags' => ['reference-to-video', 'multi-reference', 'elements', 'audio'],
        ],
        [
            'endpoint_id' => 'fal-ai/wan/v2.7/reference-to-video',
            'name' => 'Wan 2.7 Reference to Video',
            'description' => 'Wan 2.7 reference video generation with multiple image references.',
            'category' => 'Wan',
            'sort' => 215,
            'unit_price' => 0.10,
            'supports_audio' => false,
            'max_duration' => 10,
            'enums' => [2, 3, 4, 5, 6, 7, 8, 9, 10],
            'tags' => ['reference-to-video', 'multi-reference'],
        ],
        [
            'endpoint_id' => 'fal-ai/kling-video/o3/4k/reference-to-video',
            'name' => 'Kling O3 4K Reference to Video',
            'description' => 'Kling O3 native 4K reference video generation with up to 7 combined element and image references.',
            'category' => 'Kling',
            'sort' => 88,
            'unit_price' => 0.42,
            'supports_audio' => true,
            'max_duration' => 15,
            'enums' => ['3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15'],
            'tags' => ['reference-to-video', 'multi-reference', 'elements', '4k', 'audio'],
        ],
        [
            'endpoint_id' => 'fal-ai/pixverse/c1/reference-to-video',
            'name' => 'PixVerse C1 Reference to Video',
            'description' => 'PixVerse C1 reference video generation with named image references.',
            'category' => 'PixVerse',
            'sort' => 230,
            'unit_price' => 0.05,
            'supports_audio' => true,
            'max_duration' => 15,
            'enums' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15],
            'tags' => ['reference-to-video', 'multi-reference', 'audio'],
        ],
    ];

    public function run(): void
    {
        $this->seedCatalog('text_to_video_categories', 'text_to_video_models', self::TEXT_MODELS);
        $this->seedCatalog('image_to_video_categories', 'image_to_video_models', self::IMAGE_REFERENCE_ENDPOINTS, hidden: true);
    }

    /**
     * @param  list<array<string, mixed>>  $models
     */
    private function seedCatalog(string $categoryTable, string $modelTable, array $models, bool $hidden = false): void
    {
        if (! Schema::hasTable($categoryTable) || ! Schema::hasTable($modelTable)) {
            return;
        }

        $categoryIds = [];
        foreach (self::CATEGORIES as $name => $meta) {
            $categoryIds[$name] = $this->upsertCategory($categoryTable, $meta);
        }

        foreach ($models as $model) {
            $this->upsertModel($modelTable, $model, $categoryIds[$model['category']] ?? null, $hidden);
        }
    }

    /**
     * @param  array{name: string, sort: int, icon_url?: string}  $meta
     */
    private function upsertCategory(string $table, array $meta): int
    {
        $now = now();
        $values = $this->filterColumns($table, [
            'name' => $meta['name'],
            'icon_url' => $meta['icon_url'] ?? null,
            'sort' => $meta['sort'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table($table)->updateOrInsert(['name' => $meta['name']], $values);

        return (int) DB::table($table)->where('name', $meta['name'])->value('id');
    }

    /**
     * @param  array<string, mixed>  $model
     */
    private function upsertModel(string $table, array $model, ?int $categoryId, bool $hidden): void
    {
        $now = now();
        $imageUrl = $hidden ? null : '/storage/ai_icons/logo_icon_only.png';

        $values = $this->filterColumns($table, [
            'sort' => $model['sort'],
            'endpoint_id' => $model['endpoint_id'],
            'name' => $model['name'],
            'description' => $model['description'],
            'image_url' => $imageUrl,
            'image_cover' => $imageUrl,
            'tags' => json_encode($model['tags']),
            'status' => 'active',
            'unit' => 'seconds',
            'unit_price' => $model['unit_price'],
            'supports_audio' => $model['supports_audio'],
            'max_duration' => $model['max_duration'],
            'enums' => json_encode($model['enums']),
            'category_id' => $categoryId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table($table)->updateOrInsert(['endpoint_id' => $model['endpoint_id']], $values);
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
