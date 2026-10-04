<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trend_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('cover_url', 2048)->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->boolean('is_featured')->default(false)->index();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->unsignedInteger('uses_count')->default(0);
            $table->string('endpoint_id')->default('bytedance/seedance-2.5/reference-to-video');
            $table->string('model_name')->nullable();
            $table->longText('prompt');
            $table->string('aspect_ratio', 16)->default('16:9');
            $table->string('resolution', 16)->default('720p');
            $table->string('duration', 16)->default('15');
            $table->boolean('generate_audio')->default(false);
            $table->decimal('fal_estimate_usd', 12, 6)->nullable();
            $table->unsignedInteger('trend_cost')->default(0);
            $table->json('locked_assets')->nullable();
            $table->json('slots')->nullable();
            $table->string('sheet_endpoint_id')->default('fal-ai/nano-banana-pro/edit');
            $table->longText('sheet_prompt')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trend_templates');
    }
};
