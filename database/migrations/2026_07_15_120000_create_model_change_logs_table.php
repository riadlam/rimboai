<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every status/unit/unit_price change detected by fal:sync-pricing.
 * Lets us keep a history of what changed, when, and per which model table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_change_logs', function (Blueprint $table) {
            $table->id();
            $table->string('model_table')->index();
            $table->string('endpoint_id')->index();
            $table->string('name')->nullable();
            // status | unit | unit_price
            $table->string('field');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['model_table', 'endpoint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_change_logs');
    }
};
