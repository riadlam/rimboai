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
            if (! Schema::hasColumn('text_to_music_models', 'supports_audio')) {
                $table->boolean('supports_audio')->default(false)->after('supports_instrumental');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('text_to_music_models')) {
            return;
        }

        Schema::table('text_to_music_models', function (Blueprint $table) {
            if (Schema::hasColumn('text_to_music_models', 'supports_audio')) {
                $table->dropColumn('supports_audio');
            }
        });
    }
};
