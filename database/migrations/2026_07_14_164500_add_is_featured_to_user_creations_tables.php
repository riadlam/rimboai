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
                if (! Schema::hasColumn($tableName, 'is_featured')) {
                    $table->boolean('is_featured')->default(false)->after('is_public');
                    $table->index(['is_public', 'is_featured', 'status'], "{$tableName}_featured_trend_idx");
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['user_image_creations', 'user_video_creations', 'user_music_creations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'is_featured')) {
                    $table->dropIndex("{$tableName}_featured_trend_idx");
                    $table->dropColumn('is_featured');
                }
            });
        }
    }
};
