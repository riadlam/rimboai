<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('text_to_voice_models') || Schema::hasColumn('text_to_voice_models', 'supports_audio')) {
            return;
        }

        Schema::table('text_to_voice_models', function (Blueprint $table) {
            $table->boolean('supports_audio')->default(false)->after('unit_price');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('text_to_voice_models') || ! Schema::hasColumn('text_to_voice_models', 'supports_audio')) {
            return;
        }

        Schema::table('text_to_voice_models', function (Blueprint $table) {
            $table->dropColumn('supports_audio');
        });
    }
};
