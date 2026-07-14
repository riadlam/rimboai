<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('innovation_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('gradient', 191)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::create('innovations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('innovation_category_id')
                ->constrained('innovation_categories')
                ->cascadeOnDelete();
            $table->string('slug', 120)->unique();
            $table->string('title');
            $table->text('prompt');
            /** image | video | music */
            $table->string('media_type', 16);
            $table->string('image_url', 2048);
            $table->string('video_url', 2048)->nullable();
            $table->string('audio_url', 2048)->nullable();
            $table->string('model_name')->nullable();
            $table->string('endpoint_id', 191)->nullable();
            /**
             * Target lab when "Use in Lab" is clicked:
             * text-to-image | text-to-video | text-to-music
             */
            $table->string('lab_type', 32);
            $table->json('settings')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->string('status', 32)->default('active');
            $table->boolean('is_featured')->default(false);
            $table->timestamps();

            $table->index(['media_type', 'status', 'sort']);
            $table->index(['innovation_category_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('innovations');
        Schema::dropIfExists('innovation_categories');
    }
};
