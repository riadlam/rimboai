<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['text_to_video_models', 'image_to_video_models'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (Schema::hasColumn($table, 'image_url')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `image_url` VARCHAR(255) NULL AFTER `description`");
            }

            if (Schema::hasColumn($table, 'image_cover')) {
                DB::statement("ALTER TABLE `{$table}` MODIFY `image_cover` VARCHAR(255) NULL AFTER `image_url`");
            }
        }
    }

    public function down(): void
    {
        // No-op: column order only.
    }
};
