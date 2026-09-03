<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fal_pricing_sync_runs')) {
            Schema::table('fal_pricing_sync_runs', function (Blueprint $table) {
                if (! Schema::hasColumn('fal_pricing_sync_runs', 'observed')) {
                    $table->unsignedInteger('observed')->default(0)->after('requested');
                }
                if (! Schema::hasColumn('fal_pricing_sync_runs', 'published')) {
                    $table->unsignedInteger('published')->default(0);
                }
                if (! Schema::hasColumn('fal_pricing_sync_runs', 'quarantined')) {
                    $table->unsignedInteger('quarantined')->default(0);
                }
                if (! Schema::hasColumn('fal_pricing_sync_runs', 'kept_last_good')) {
                    $table->unsignedInteger('kept_last_good')->default(0);
                }
                if (! Schema::hasColumn('fal_pricing_sync_runs', 'deactivated')) {
                    $table->unsignedInteger('deactivated')->default(0);
                }
                if (! Schema::hasColumn('fal_pricing_sync_runs', 'reactivated')) {
                    $table->unsignedInteger('reactivated')->default(0);
                }
                if (! Schema::hasColumn('fal_pricing_sync_runs', 'coverage')) {
                    $table->decimal('coverage', 8, 4)->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_sync_runs', 'tables')) {
                    $table->json('tables')->nullable();
                }
            });
        } else {
            Schema::create('fal_pricing_sync_runs', function (Blueprint $table) {
                $table->id();
                $table->string('status', 24)->default('running')->index();
                $table->unsignedInteger('requested')->default(0);
                $table->unsignedInteger('observed')->default(0);
                $table->unsignedInteger('priced')->default(0);
                $table->unsignedInteger('published')->default(0);
                $table->unsignedInteger('quarantined')->default(0);
                $table->unsignedInteger('kept_last_good')->default(0);
                $table->unsignedInteger('deactivated')->default(0);
                $table->unsignedInteger('reactivated')->default(0);
                $table->decimal('coverage', 8, 4)->nullable();
                $table->boolean('dry_run')->default(false);
                $table->json('tables')->nullable();
                $table->text('error')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('fal_pricing_observations')) {
            Schema::table('fal_pricing_observations', function (Blueprint $table) {
                if (! Schema::hasColumn('fal_pricing_observations', 'model_table')) {
                    $table->string('model_table', 64)->nullable()->index();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'model_id')) {
                    $table->unsignedBigInteger('model_id')->nullable()->index();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'name')) {
                    $table->string('name')->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'raw_price')) {
                    $table->decimal('raw_price', 16, 8)->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'normalized_unit')) {
                    $table->string('normalized_unit', 64)->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'normalized_price')) {
                    $table->decimal('normalized_price', 16, 8)->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'fal_status')) {
                    $table->string('fal_status', 24)->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'decision')) {
                    $table->string('decision', 32)->nullable()->index();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'anomaly')) {
                    $table->string('anomaly', 64)->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'payload')) {
                    $table->json('payload')->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'checked_at')) {
                    $table->timestamp('checked_at')->nullable();
                }
                if (! Schema::hasColumn('fal_pricing_observations', 'published_at')) {
                    $table->timestamp('published_at')->nullable();
                }
            });
        } else {
            Schema::create('fal_pricing_observations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sync_run_id')->constrained('fal_pricing_sync_runs')->cascadeOnDelete();
                $table->string('model_table', 64)->index();
                $table->unsignedBigInteger('model_id')->nullable()->index();
                $table->string('endpoint_id', 255)->index();
                $table->string('name')->nullable();
                $table->string('raw_unit', 64)->nullable();
                $table->decimal('raw_price', 16, 8)->nullable();
                $table->string('currency', 8)->nullable();
                $table->string('normalized_unit', 64)->nullable();
                $table->decimal('normalized_price', 16, 8)->nullable();
                $table->string('fal_status', 24)->nullable();
                $table->string('decision', 32)->index();
                $table->string('anomaly', 64)->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->index(['endpoint_id', 'model_table', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        // Additive columns only — keep the original 010000 tables intact.
    }
};
