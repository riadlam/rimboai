<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENDPOINT_ID = 'fal-ai/nano-banana/edit';

    public function up(): void
    {
        if (! Schema::hasTable('text_to_image_models')) {
            return;
        }

        $baseModel = DB::table('text_to_image_models')
            ->where('endpoint_id', 'fal-ai/nano-banana')
            ->first();

        DB::table('text_to_image_models')->updateOrInsert(
            ['endpoint_id' => self::ENDPOINT_ID],
            [
                'sort' => 999,
                'name' => 'nano-banana edit',
                'description' => "Google's original Nano Banana image generation and editing model.",
                // Billing-only variant: a null icon keeps it out of the model picker.
                'image_url' => null,
                'image_cover' => null,
                'tags' => json_encode(['image-editing']),
                'status' => 'active',
                'unit' => 'images',
                'unit_price' => 0.039,
                'category_id' => $baseModel?->category_id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('text_to_image_models')) {
            return;
        }

        DB::table('text_to_image_models')
            ->where('endpoint_id', self::ENDPOINT_ID)
            ->delete();
    }
};
