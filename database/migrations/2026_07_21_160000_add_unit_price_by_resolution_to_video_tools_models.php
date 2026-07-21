<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-resolution Fal USD tiers for tools (Topaz / ByteDance / PixVerse / Wan…).
 * Billing prefers this column, then falls back to hardcoded safety-net maps.
 * fal:sync-pricing proportionally rescales these when the base unit_price changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('video_tools_models')) {
            return;
        }

        Schema::table('video_tools_models', function (Blueprint $table) {
            if (! Schema::hasColumn('video_tools_models', 'unit_price_by_resolution')) {
                $table->json('unit_price_by_resolution')->nullable()->after('unit_price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('video_tools_models')) {
            return;
        }

        Schema::table('video_tools_models', function (Blueprint $table) {
            if (Schema::hasColumn('video_tools_models', 'unit_price_by_resolution')) {
                $table->dropColumn('unit_price_by_resolution');
            }
        });
    }
};
