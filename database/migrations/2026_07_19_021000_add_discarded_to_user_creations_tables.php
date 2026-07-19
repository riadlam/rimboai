<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'user_image_creations',
            'user_video_creations',
            'user_music_creations',
            'user_voice_creations',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'discarded')) {
                    $blueprint->unsignedTinyInteger('discarded')->nullable()->after('status');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'user_image_creations',
            'user_video_creations',
            'user_music_creations',
            'user_voice_creations',
        ] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'discarded')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('discarded');
            });
        }
    }
};
