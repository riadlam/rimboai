<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('text_to_music_examples')) {
            return;
        }

        Schema::create('text_to_music_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('text_to_music_model_id')
                ->constrained('text_to_music_models')
                ->cascadeOnDelete();
            $table->string('example_key');
            $table->string('title');
            $table->text('style')->nullable();
            $table->text('description')->nullable();
            $table->boolean('vocals')->default(false);
            $table->string('cover_url', 1024)->nullable();
            /** Remote CDN URL (optional; paste later) */
            $table->string('sample_remote_url', 1024)->nullable();
            /** Stable local/public URL path, e.g. /storage/lab/music-samples/... — leave empty until pasted */
            $table->string('sample_url', 1024)->nullable();
            $table->string('sample_path')->nullable();
            $table->json('tags')->nullable();
            $table->unsignedInteger('sort')->default(999)->index();
            $table->timestamps();

            $table->unique(['text_to_music_model_id', 'example_key'], 'music_model_example_key_unique');
            $table->index(['text_to_music_model_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_to_music_examples');
    }
};
