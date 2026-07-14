<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'text_to_video_models',
        'image_to_video_models',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'supports_audio')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->boolean('supports_audio')->default(false)->after('unit_price');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'supports_audio')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('supports_audio');
            });
        }
    }
};
