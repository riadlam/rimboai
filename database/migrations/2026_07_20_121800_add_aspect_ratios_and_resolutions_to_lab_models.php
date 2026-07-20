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
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'aspect_ratios')) {
                    $blueprint->json('aspect_ratios')->nullable()->after('tags');
                }
                if (! Schema::hasColumn($table, 'resolutions')) {
                    $blueprint->json('resolutions')->nullable()->after('aspect_ratios');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'resolutions')) {
                    $blueprint->dropColumn('resolutions');
                }
                if (Schema::hasColumn($table, 'aspect_ratios')) {
                    $blueprint->dropColumn('aspect_ratios');
                }
            });
        }
    }
};
