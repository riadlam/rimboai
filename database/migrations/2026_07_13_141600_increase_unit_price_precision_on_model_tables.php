<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
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
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'unit_price')) {
                continue;
            }

            // Seedance token rates need more than 2 decimals ($0.014 / $0.0112 / $0.008).
            DB::statement("ALTER TABLE `{$table}` MODIFY `unit_price` DECIMAL(12,6) NULL");
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'unit_price')) {
                continue;
            }

            DB::statement("ALTER TABLE `{$table}` MODIFY `unit_price` DECIMAL(10,2) NULL");
        }
    }
};
