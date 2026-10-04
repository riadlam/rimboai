<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_video_creations')) {
            return;
        }

        Schema::table('user_video_creations', function (Blueprint $table): void {
            if (! Schema::hasColumn('user_video_creations', 'provider')) {
                $table->string('provider', 32)->nullable()->default('fal')->after('endpoint_id');
                $table->index(['provider', 'fal_request_id'], 'user_video_creations_provider_request_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_video_creations')) {
            return;
        }

        Schema::table('user_video_creations', function (Blueprint $table): void {
            if (Schema::hasColumn('user_video_creations', 'provider')) {
                $table->dropIndex('user_video_creations_provider_request_idx');
                $table->dropColumn('provider');
            }
        });
    }
};
