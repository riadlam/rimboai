<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, array{name: string, description: string, unit_price: float, tags: list<string>, base_endpoint: string}> */
    private const IMAGE_ENDPOINTS = [
        'fal-ai/nano-banana-2/edit' => [
            'name' => 'Nano Banana 2 Edit',
            'description' => "Nano Banana 2 is Google's state-of-the-art image generation and editing model.",
            'unit_price' => 0.08,
            'tags' => ['image-editing'],
            'base_endpoint' => 'fal-ai/nano-banana-2',
        ],
        'fal-ai/nano-banana-pro/edit' => [
            'name' => 'Nano Banana Pro Edit',
            'description' => "Nano Banana Pro is Google's state-of-the-art image generation and editing model.",
            'unit_price' => 0.15,
            'tags' => ['image-editing', 'realism', 'typography'],
            'base_endpoint' => 'fal-ai/nano-banana-pro',
        ],
        'fal-ai/gemini-3.1-flash-image-preview/edit' => [
            'name' => 'Gemini 3.1 Flash Image Edit',
            'description' => "Gemini 3.1 Flash Image is Google's fast image generation and editing model.",
            'unit_price' => 0.08,
            'tags' => ['image-editing'],
            'base_endpoint' => 'fal-ai/gemini-3.1-flash-image-preview',
        ],
        'fal-ai/gemini-3-pro-image-preview/edit' => [
            'name' => 'Gemini 3 Pro Image Edit',
            'description' => "Gemini 3 Pro Image is Google's high-fidelity image generation and editing model.",
            'unit_price' => 0.15,
            'tags' => ['image-editing', 'realism', 'typography'],
            'base_endpoint' => 'fal-ai/gemini-3-pro-image-preview',
        ],
        'fal-ai/gemini-25-flash-image/edit' => [
            'name' => 'Gemini 2.5 Flash Image Edit',
            'description' => "Google's original image generation and editing model, also known as Nano Banana.",
            'unit_price' => 0.039,
            'tags' => ['image-editing'],
            'base_endpoint' => 'fal-ai/gemini-25-flash-image',
        ],
    ];

    private const VIDEO_ENDPOINT = 'fal-ai/veo3.1/fast/reference-to-video';

    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('text_to_image_models')) {
            foreach (self::IMAGE_ENDPOINTS as $endpointId => $metadata) {
                $categoryId = DB::table('text_to_image_models')
                    ->where('endpoint_id', $metadata['base_endpoint'])
                    ->value('category_id');

                DB::table('text_to_image_models')->updateOrInsert(
                    ['endpoint_id' => $endpointId],
                    [
                        'sort' => 999,
                        'name' => $metadata['name'],
                        'description' => $metadata['description'],
                        // Billing-only variants remain hidden from the model picker.
                        'image_url' => null,
                        'image_cover' => null,
                        'tags' => json_encode($metadata['tags']),
                        'status' => 'active',
                        'unit' => 'images',
                        'unit_price' => $metadata['unit_price'],
                        'category_id' => $categoryId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }

        if (Schema::hasTable('image_to_video_models')) {
            $categoryId = DB::table('image_to_video_models')
                ->where('endpoint_id', 'fal-ai/veo3.1/fast/image-to-video')
                ->value('category_id');

            DB::table('image_to_video_models')->updateOrInsert(
                ['endpoint_id' => self::VIDEO_ENDPOINT],
                [
                    'sort' => 999,
                    'name' => 'Veo 3.1 Fast Reference to Video',
                    'description' => "Generate videos from reference images using Google's Veo 3.1 Fast.",
                    'image_url' => null,
                    'image_cover' => null,
                    'tags' => json_encode(['reference-to-video']),
                    'status' => 'active',
                    'unit' => 'seconds',
                    // fal charges $0.15/second with audio, which is the supported default.
                    'unit_price' => 0.15,
                    'supports_audio' => true,
                    'max_duration' => 8,
                    'enums' => json_encode(['4s', '6s', '8s']),
                    'category_id' => $categoryId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('text_to_image_models')) {
            DB::table('text_to_image_models')
                ->whereIn('endpoint_id', array_keys(self::IMAGE_ENDPOINTS))
                ->delete();
        }

        if (Schema::hasTable('image_to_video_models')) {
            DB::table('image_to_video_models')
                ->where('endpoint_id', self::VIDEO_ENDPOINT)
                ->delete();
        }
    }
};
