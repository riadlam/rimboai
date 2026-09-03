<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODEL_TABLES = [
        'text_to_image_models',
        'text_to_video_models',
        'image_to_video_models',
        'text_to_voice_models',
        'text_to_music_models',
        'video_tools_models',
    ];

    public function up(): void
    {
        Schema::create('fal_pricing_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 24)->index();
            $table->unsignedInteger('requested')->default(0);
            $table->unsignedInteger('priced')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('changes')->default(0);
            $table->boolean('dry_run')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('fal_pricing_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_run_id')
                ->nullable()
                ->constrained('fal_pricing_sync_runs')
                ->nullOnDelete();
            $table->string('endpoint_id')->index();
            $table->string('currency', 3)->nullable();
            $table->string('raw_unit')->nullable();
            $table->decimal('raw_unit_price', 18, 10)->nullable();
            $table->string('status', 24)->default('valid')->index();
            $table->string('anomaly_reason')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('observed_at')->index();
            $table->timestamps();

            $table->index(['endpoint_id', 'observed_at']);
        });

        foreach (self::MODEL_TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'pricing_checked_at')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamp('pricing_checked_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::MODEL_TABLES as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'pricing_checked_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('pricing_checked_at');
                });
            }
        }

        Schema::dropIfExists('fal_pricing_observations');
        Schema::dropIfExists('fal_pricing_sync_runs');
    }
};
