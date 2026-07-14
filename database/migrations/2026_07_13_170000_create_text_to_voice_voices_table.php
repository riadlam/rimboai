<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('text_to_voice_voices')) {
            return;
        }

        Schema::create('text_to_voice_voices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('text_to_voice_model_id')
                ->constrained('text_to_voice_models')
                ->cascadeOnDelete();
            $table->string('voice_key');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('language')->nullable();
            $table->string('gender')->nullable();
            $table->json('tags')->nullable();
            /** Remote fal CDN URL (may expire) */
            $table->string('sample_remote_url', 1024)->nullable();
            /** Stable local/public URL path, e.g. /storage/lab/voice-samples/... */
            $table->string('sample_url', 1024)->nullable();
            $table->string('sample_path')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort')->default(999)->index();
            $table->timestamps();

            $table->unique(['text_to_voice_model_id', 'voice_key'], 'voice_model_key_unique');
            $table->index(['text_to_voice_model_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_to_voice_voices');
    }
};
