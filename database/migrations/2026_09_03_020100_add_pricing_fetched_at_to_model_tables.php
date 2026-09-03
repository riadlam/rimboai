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
        'text_to_voice_models',
        'text_to_music_models',
        'video_tools_models',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'pricing_fetched_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('pricing_fetched_at')->nullable()->after('unit_price');
                $table->unsignedTinyInteger('status_missing_streak')->default(0)->after('status');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'pricing_fetched_at')) {
                    $table->dropColumn('pricing_fetched_at');
                }
                if (Schema::hasColumn($tableName, 'status_missing_streak')) {
                    $table->dropColumn('status_missing_streak');
                }
            });
        }
    }
};
