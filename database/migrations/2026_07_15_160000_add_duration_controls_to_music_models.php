<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('text_to_music_models', function (Blueprint $table) {
            $table->boolean('supports_duration_control')->default(false)->after('default_duration_seconds');
            $table->unsignedInteger('min_duration_seconds')->nullable()->after('supports_duration_control');
            $table->unsignedInteger('duration_step_seconds')->nullable()->after('min_duration_seconds');
        });

        $capabilities = [
            'fal-ai/elevenlabs/music' => [3, 1, 120],
            'cassetteai/music-generator' => [1, 1, 90],
            'fal-ai/stable-audio-25/text-to-audio' => [1, 1, 90],
        ];

        foreach ($capabilities as $endpoint => [$min, $step, $default]) {
            DB::table('text_to_music_models')
                ->where('endpoint_id', $endpoint)
                ->update([
                    'supports_duration_control' => true,
                    'min_duration_seconds' => $min,
                    'duration_step_seconds' => $step,
                    'default_duration_seconds' => $default,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('text_to_music_models', function (Blueprint $table) {
            $table->dropColumn([
                'supports_duration_control',
                'min_duration_seconds',
                'duration_step_seconds',
            ]);
        });
    }
};
