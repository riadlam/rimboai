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
            if (! Schema::hasColumn('text_to_music_models', 'supports_vocals')) {
                $table->boolean('supports_vocals')->default(false)->after('max_duration');
            }
            if (! Schema::hasColumn('text_to_music_models', 'supports_lyrics')) {
                $table->boolean('supports_lyrics')->default(false)->after('supports_vocals');
            }
            if (! Schema::hasColumn('text_to_music_models', 'supports_instrumental')) {
                $table->boolean('supports_instrumental')->default(true)->after('supports_lyrics');
            }
            if (! Schema::hasColumn('text_to_music_models', 'max_lyrics_chars')) {
                $table->unsignedInteger('max_lyrics_chars')->nullable()->after('supports_instrumental');
            }
            if (! Schema::hasColumn('text_to_music_models', 'max_prompt_chars')) {
                $table->unsignedInteger('max_prompt_chars')->nullable()->after('max_lyrics_chars');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('text_to_music_models')) {
            return;
        }

        Schema::table('text_to_music_models', function (Blueprint $table) {
            foreach (['supports_vocals', 'supports_lyrics', 'supports_instrumental', 'max_lyrics_chars', 'max_prompt_chars'] as $col) {
                if (Schema::hasColumn('text_to_music_models', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
