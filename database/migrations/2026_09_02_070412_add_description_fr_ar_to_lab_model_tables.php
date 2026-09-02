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
        'text_to_voice_models',
        'text_to_music_models',
        'video_tools_models',
        'text_to_voice_voices',
        'text_to_music_examples',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (! Schema::hasColumn($table, 'description_fr')) {
                    $blueprint->text('description_fr')->nullable()->after('description');
                }
                if (! Schema::hasColumn($table, 'description_ar')) {
                    $blueprint->text('description_ar')->nullable()->after('description_fr');
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

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if (Schema::hasColumn($table, 'description_ar')) {
                    $blueprint->dropColumn('description_ar');
                }
                if (Schema::hasColumn($table, 'description_fr')) {
                    $blueprint->dropColumn('description_fr');
                }
            });
        }
    }
};
