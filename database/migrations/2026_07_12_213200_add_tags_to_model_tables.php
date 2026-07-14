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
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tags')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $after = Schema::hasColumn($table, 'image_cover')
                    ? 'image_cover'
                    : (Schema::hasColumn($table, 'image_url') ? 'image_url' : 'description');

                $blueprint->json('tags')->nullable()->after($after);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tags')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('tags');
            });
        }
    }
};
