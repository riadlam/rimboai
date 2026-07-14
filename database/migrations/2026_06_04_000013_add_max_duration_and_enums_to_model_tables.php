<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('text_to_video_models', function (Blueprint $table) {
            $table->integer('max_duration')->nullable()->after('unit_price');
            $table->json('enums')->nullable()->after('max_duration');
        });

        Schema::table('image_to_video_models', function (Blueprint $table) {
            $table->integer('max_duration')->nullable()->after('unit_price');
            $table->json('enums')->nullable()->after('max_duration');
        });
    }

    public function down(): void
    {
        Schema::table('text_to_video_models', function (Blueprint $table) {
            $table->dropColumn('max_duration');
            $table->dropColumn('enums');
        });

        Schema::table('image_to_video_models', function (Blueprint $table) {
            $table->dropColumn('max_duration');
            $table->dropColumn('enums');
        });
    }
};
