<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('text_to_video_models', function (Blueprint $table) {
            if (! Schema::hasColumn('text_to_video_models', 'supports_first_frame')) {
                $after = Schema::hasColumn('text_to_video_models', 'supports_audio')
                    ? 'supports_audio'
                    : 'status';
                $table->boolean('supports_first_frame')->default(false)->after($after);
            }
            if (! Schema::hasColumn('text_to_video_models', 'supports_last_frame')) {
                $table->boolean('supports_last_frame')->default(false)->after('supports_first_frame');
            }
            if (! Schema::hasColumn('text_to_video_models', 'first_last_frame_endpoint_id')) {
                $table->string('first_last_frame_endpoint_id', 255)->nullable()->after('supports_last_frame');
            }
        });

        // Catalog models with a dedicated first+last-frame sibling on Fal.
        $map = [
            'fal-ai/veo3.1' => 'fal-ai/veo3.1/first-last-frame-to-video',
            'fal-ai/veo3.1/fast' => 'fal-ai/veo3.1/fast/first-last-frame-to-video',
            'fal-ai/veo3.1/lite' => 'fal-ai/veo3.1/lite/first-last-frame-to-video',
            'fal-ai/kling-video/o1/reference-to-video' => 'fal-ai/kling-video/o1/image-to-video',
        ];

        foreach ($map as $catalogEndpoint => $flfEndpoint) {
            DB::table('text_to_video_models')
                ->where('endpoint_id', $catalogEndpoint)
                ->update([
                    'supports_first_frame' => true,
                    'supports_last_frame' => true,
                    'first_last_frame_endpoint_id' => $flfEndpoint,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('text_to_video_models', function (Blueprint $table) {
            foreach (['first_last_frame_endpoint_id', 'supports_last_frame', 'supports_first_frame'] as $column) {
                if (Schema::hasColumn('text_to_video_models', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
