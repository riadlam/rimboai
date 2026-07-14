<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('text_to_music_models')) {
            return;
        }

        Schema::table('text_to_music_models', function (Blueprint $table) {
            if (! Schema::hasColumn('text_to_music_models', 'default_duration_seconds')) {
                $table->unsignedInteger('default_duration_seconds')->nullable()->after('max_duration');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('text_to_music_models')) {
            return;
        }

        Schema::table('text_to_music_models', function (Blueprint $table) {
            if (Schema::hasColumn('text_to_music_models', 'default_duration_seconds')) {
                $table->dropColumn('default_duration_seconds');
            }
        });
    }
};
