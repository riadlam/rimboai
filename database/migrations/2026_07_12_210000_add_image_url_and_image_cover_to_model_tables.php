<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'text_to_image_models',
        'text_to_video_models',
        'image_to_video_models',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'image_url')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->string('image_url')->nullable()->after('description');
                });
            }

            if (! Schema::hasColumn($table, 'image_cover')) {
                Schema::table($table, function (Blueprint $blueprint) use ($table) {
                    $after = Schema::hasColumn($table, 'image_url') ? 'image_url' : 'description';
                    $blueprint->string('image_cover')->nullable()->after($after);
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $drop = [];

                // Keep image_url on text_to_image_models (added in earlier migration).
                if ($table !== 'text_to_image_models' && Schema::hasColumn($table, 'image_url')) {
                    $drop[] = 'image_url';
                }

                if (Schema::hasColumn($table, 'image_cover')) {
                    $drop[] = 'image_cover';
                }

                if ($drop !== []) {
                    $blueprint->dropColumn($drop);
                }
            });
        }
    }
};
