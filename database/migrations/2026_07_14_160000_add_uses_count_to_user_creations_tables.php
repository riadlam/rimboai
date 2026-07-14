<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['user_image_creations', 'user_video_creations', 'user_music_creations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->unsignedInteger('uses_count')->default(0)->after('is_public');
                $table->index(['is_public', 'status', 'uses_count'], "{$tableName}_public_trend_idx");
            });
        }
    }

    public function down(): void
    {
        foreach (['user_image_creations', 'user_video_creations', 'user_music_creations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex("{$tableName}_public_trend_idx");
                $table->dropColumn('uses_count');
            });
        }
    }
};
