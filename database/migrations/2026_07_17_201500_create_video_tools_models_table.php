<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_tools_models', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sort')->default(0);
            /** Matches ToolsService route without "tools." prefix, e.g. video-upscaler */
            $table->string('tool_slug', 64)->index();
            $table->string('tool_name')->nullable();
            $table->string('endpoint_id')->index();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_cover')->nullable();
            $table->json('tags')->nullable();
            $table->string('status')->default('active')->index();
            /** Fal billing unit: seconds | minutes | megapixels | video | frames_30 | … */
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 12, 6)->nullable();
            /**
             * Static marketing credit cost (Rimbo tokens).
             * Derived once from fal ref cost via: ceil((fal_usd * markup) / usd_per_credit)
             * with markup=1.25 and usd_per_credit=0.01 — editable manually later.
             */
            $table->unsignedInteger('token_cost')->default(0);
            /** Reference fal USD used when computing token_cost (marketing baseline). */
            $table->decimal('ref_cost_usd', 12, 6)->nullable();
            $table->unsignedSmallInteger('ref_duration_seconds')->nullable();
            $table->integer('max_duration')->nullable();
            $table->json('enums')->nullable();
            /** Preferred model for the tool (sort 0 / is_primary true). */
            $table->boolean('is_primary')->default(false);
            /**
             * Optional Fal default knobs (e.g. Topaz model=Nyx, Bytedance preset).
             * Not wallet before/after — those live on creation rows at runtime.
             */
            $table->json('defaults')->nullable();
            $table->timestamps();

            $table->unique(['tool_slug', 'endpoint_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_tools_models');
    }
};
