<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trend_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('trend_templates', 'category')) {
                $table->string('category', 32)->default('trends')->index()->after('slug');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trend_templates', function (Blueprint $table) {
            if (Schema::hasColumn('trend_templates', 'category')) {
                $table->dropColumn('category');
            }
        });
    }
};
