<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $modelTables = [
        'text_to_image_models',
        'text_to_video_models',
        'image_to_video_models',
    ];

    /** @var list<string> */
    private array $categoryTables = [
        'text_to_image_categories',
        'text_to_video_categories',
        'image_to_video_categories',
    ];

    /**
     * Lower number = shown first (more popular).
     *
     * @var array<string, array<string, int>>
     */
    private array $categorySort = [
        'text_to_image_categories' => [
            'Nano Banana' => 10,
            'Flux' => 20,
            'GPT Image' => 30,
            'Seedream' => 40,
            'Gemini' => 50,
            'Kling' => 60,
            'Grok' => 70,
            'Wan' => 80,
            'Qwen' => 90,
        ],
        'text_to_video_categories' => [
            'Kling' => 10,
            'Seedance' => 20,
            'Veo' => 30,
            'Sora' => 40,
            'Wan' => 50,
            'Grok' => 60,
        ],
        'image_to_video_categories' => [
            'Kling' => 10,
            'Seedance' => 20,
            'Veo' => 30,
            'Sora' => 40,
            'Wan' => 50,
            'Grok' => 60,
        ],
    ];

    /**
     * Popularity rank by endpoint_id (lower = more popular).
     *
     * @var array<string, array<string, int>>
     */
    private array $modelSort = [
        'text_to_image_models' => [
            'fal-ai/nano-banana-2' => 10,
            'fal-ai/nano-banana-pro' => 20,
            'fal-ai/nano-banana' => 30,
            'fal-ai/flux-2/turbo' => 40,
            'fal-ai/flux-2-pro' => 50,
            'fal-ai/flux-pro/v1.1-ultra' => 60,
            'openai/gpt-image-2' => 70,
            'fal-ai/gpt-image-1.5' => 80,
            'fal-ai/bytedance/seedream/v5/lite/text-to-image' => 90,
            'fal-ai/bytedance/seedream/v4.5/text-to-image' => 100,
            'fal-ai/bytedance/seedream/v4/text-to-image' => 110,
            'fal-ai/gemini-3.1-flash-image-preview' => 120,
            'fal-ai/gemini-3-pro-image-preview' => 130,
            'fal-ai/gemini-25-flash-image' => 140,
            'fal-ai/kling-image/v3/text-to-image' => 150,
            'xai/grok-imagine-image' => 160,
            'fal-ai/wan/v2.7/text-to-image' => 170,
            'fal-ai/qwen-image-2/text-to-image' => 180,
        ],
        'text_to_video_models' => [
            'fal-ai/kling-video/v3/pro/text-to-video' => 10,
            'fal-ai/kling-video/v3/standard/text-to-video' => 20,
            'fal-ai/kling-video/v3/4k/text-to-video' => 30,
            'fal-ai/kling-video/o3/pro/text-to-video' => 40,
            'fal-ai/kling-video/o3/standard/text-to-video' => 50,
            'fal-ai/kling-video/v2.6/pro/text-to-video' => 60,
            'fal-ai/kling-video/v2.5-turbo/pro/text-to-video' => 70,
            'bytedance/seedance-2.0/text-to-video' => 80,
            'bytedance/seedance-2.0/fast/text-to-video' => 90,
            'fal-ai/veo3.1' => 100,
            'fal-ai/veo3.1/fast' => 110,
            'fal-ai/veo3.1/lite' => 120,
            'fal-ai/sora-2/text-to-video' => 130,
            'fal-ai/sora-2/text-to-video/pro' => 140,
            'fal-ai/wan/v2.7/text-to-video' => 150,
            'xai/grok-imagine-video/text-to-video' => 160,
        ],
        'image_to_video_models' => [
            'fal-ai/kling-video/v3/pro/image-to-video' => 10,
            'fal-ai/kling-video/v3/standard/image-to-video' => 20,
            'fal-ai/kling-video/v3/4k/image-to-video' => 30,
            'fal-ai/kling-video/o3/pro/image-to-video' => 40,
            'fal-ai/kling-video/o3/standard/image-to-video' => 50,
            'fal-ai/kling-video/v2.6/pro/image-to-video' => 60,
            'fal-ai/kling-video/v2.5-turbo/pro/image-to-video' => 70,
            'fal-ai/kling-video/v2.5-turbo/standard/image-to-video' => 80,
            'bytedance/seedance-2.0/image-to-video' => 90,
            'bytedance/seedance-2.0/fast/image-to-video' => 100,
            'bytedance/seedance-2.0/reference-to-video' => 110,
            'bytedance/seedance-2.0/fast/reference-to-video' => 120,
            'fal-ai/veo3.1/image-to-video' => 130,
            'fal-ai/veo3.1/fast/image-to-video' => 140,
            'fal-ai/veo3.1/lite/image-to-video' => 150,
            'fal-ai/veo3.1/first-last-frame-to-video' => 160,
            'fal-ai/veo3.1/fast/first-last-frame-to-video' => 170,
            'fal-ai/veo3.1/reference-to-video' => 180,
            'fal-ai/sora-2/image-to-video' => 190,
            'fal-ai/sora-2/image-to-video/pro' => 200,
            'fal-ai/wan/v2.7/image-to-video' => 210,
            'xai/grok-imagine-video/image-to-video' => 220,
        ],
    ];

    public function up(): void
    {
        foreach ([...$this->modelTables, ...$this->categoryTables] as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'sort')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedInteger('sort')->default(999)->after('id');
                $blueprint->index('sort');
            });
        }

        foreach ($this->categorySort as $table => $ranks) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->update(['sort' => 999]);

            foreach ($ranks as $name => $sort) {
                DB::table($table)->where('name', $name)->update(['sort' => $sort]);
            }
        }

        foreach ($this->modelSort as $table => $ranks) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->update(['sort' => 999]);

            foreach ($ranks as $endpointId => $sort) {
                DB::table($table)->where('endpoint_id', $endpointId)->update(['sort' => $sort]);
            }
        }
    }

    public function down(): void
    {
        foreach ([...$this->modelTables, ...$this->categoryTables] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'sort')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['sort']);
                $blueprint->dropColumn('sort');
            });
        }
    }
};
