<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('innovations', function (Blueprint $table) {
            $table->string('aspect_ratio', 16)->nullable()->after('lab_type');
            $table->string('resolution', 32)->nullable()->after('aspect_ratio');
            /** Seconds as string, or "auto" for video models that support it */
            $table->string('duration', 16)->nullable()->after('resolution');
            $table->unsignedTinyInteger('quantity')->default(1)->after('duration');
            /** Video soundtrack toggle (generate_audio) */
            $table->boolean('generate_audio')->nullable()->after('quantity');
            /** Image lab: create | variations */
            $table->string('image_mode', 32)->nullable()->after('generate_audio');
            /** Music: style tags when prompt is mainly lyrics */
            $table->string('style_prompt', 1000)->nullable()->after('image_mode');
        });
    }

    public function down(): void
    {
        Schema::table('innovations', function (Blueprint $table) {
            $table->dropColumn([
                'aspect_ratio',
                'resolution',
                'duration',
                'quantity',
                'generate_audio',
                'image_mode',
                'style_prompt',
            ]);
        });
    }
};
