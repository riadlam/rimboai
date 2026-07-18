<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'user_image_creations',
        'user_video_creations',
        'user_music_creations',
        'user_voice_creations',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'trend_title')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                // Manual display title for Trends cards / template workspace.
                if (Schema::hasColumn($table, 'trend_cost')) {
                    $blueprint->string('trend_title', 191)->nullable()->after('trend_cost');
                } else {
                    $blueprint->string('trend_title', 191)->nullable()->after('credits_charged');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'trend_title')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('trend_title');
            });
        }
    }
};
