<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_to_video_categories', function (Blueprint $table) {
            $table->string('icon_url')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('image_to_video_categories', function (Blueprint $table) {
            $table->dropColumn('icon_url');
        });
    }
};
