<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Creation/job tables for Lab generations.
     * FAL_KEY never stored here — only fal request ids + public result URLs.
     * Client never talks to fal directly; Laravel owns submit/status/result.
     */
    public function up(): void
    {
        Schema::create('user_image_creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // text-to-image | image-to-image | upscale | edit
            $table->string('mode', 64)->default('text-to-image')->index();
            $table->string('endpoint_id')->nullable()->index();
            $table->string('model_name')->nullable();

            $table->text('prompt')->nullable();
            $table->text('negative_prompt')->nullable();

            // [{ "url": "...", "path": "...", "type": "image", "role": "source|mask|reference" }]
            $table->json('input_assets')->nullable();
            // aspect_ratio, resolution, seed, quantity, guidance, etc.
            $table->json('settings')->nullable();

            $table->string('status', 32)->default('pending')->index();
            $table->string('fal_request_id')->nullable()->index();
            $table->string('fal_status_url')->nullable();
            $table->string('fal_response_url')->nullable();
            $table->unsignedInteger('queue_position')->nullable();
            $table->string('progress_message')->nullable();

            // [{ "url": "...", "content_type": "image/png", "width": 1024, "height": 1024 }]
            $table->json('result_assets')->nullable();
            $table->string('result_preview_url', 2048)->nullable();

            $table->text('error_message')->nullable();
            $table->string('error_type')->nullable();

            $table->decimal('credits_charged', 12, 4)->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_public')->default(false);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('user_video_creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // text-to-video | image-to-video | video-to-video | reference-to-video
            $table->string('mode', 64)->default('text-to-video')->index();
            $table->string('endpoint_id')->nullable()->index();
            $table->string('model_name')->nullable();

            $table->text('prompt')->nullable();
            $table->text('negative_prompt')->nullable();

            // start frame, end frame, reference video/image, audio, etc.
            $table->json('input_assets')->nullable();
            // aspect_ratio, resolution, duration, duration_mode(auto), audio, seed, speed
            $table->json('settings')->nullable();

            $table->string('duration_value', 32)->nullable(); // "5", "auto", "8s"
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->string('aspect_ratio', 16)->nullable();
            $table->string('resolution', 32)->nullable();
            $table->boolean('with_audio')->default(false);

            $table->string('status', 32)->default('pending')->index();
            $table->string('fal_request_id')->nullable()->index();
            $table->string('fal_status_url')->nullable();
            $table->string('fal_response_url')->nullable();
            $table->unsignedInteger('queue_position')->nullable();
            $table->string('progress_message')->nullable();

            $table->json('result_assets')->nullable();
            $table->string('result_preview_url', 2048)->nullable();
            $table->string('result_video_url', 2048)->nullable();
            $table->string('thumbnail_url', 2048)->nullable();

            $table->text('error_message')->nullable();
            $table->string('error_type')->nullable();

            $table->decimal('credits_charged', 12, 4)->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_public')->default(false);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('user_music_creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // text-to-music | text-to-sound | lyrics-to-song
            $table->string('mode', 64)->default('text-to-music')->index();
            $table->string('endpoint_id')->nullable()->index();
            $table->string('model_name')->nullable();

            $table->string('title')->nullable();
            $table->text('prompt')->nullable(); // style / description
            $table->longText('lyrics')->nullable();
            $table->boolean('instrumental')->default(false);

            $table->json('input_assets')->nullable(); // reference audio, cover upload, etc.
            $table->json('settings')->nullable();

            $table->unsignedSmallInteger('duration_seconds')->nullable();

            $table->string('status', 32)->default('pending')->index();
            $table->string('fal_request_id')->nullable()->index();
            $table->string('fal_status_url')->nullable();
            $table->string('fal_response_url')->nullable();
            $table->unsignedInteger('queue_position')->nullable();
            $table->string('progress_message')->nullable();

            $table->json('result_assets')->nullable();
            $table->string('result_preview_url', 2048)->nullable();
            $table->string('result_audio_url', 2048)->nullable();
            $table->string('cover_url', 2048)->nullable();

            $table->text('error_message')->nullable();
            $table->string('error_type')->nullable();

            $table->decimal('credits_charged', 12, 4)->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_public')->default(false);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('user_voice_creations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // text-to-speech | voice-clone | speech-to-speech
            $table->string('mode', 64)->default('text-to-speech')->index();
            $table->string('endpoint_id')->nullable()->index();
            $table->string('model_name')->nullable();

            $table->text('prompt')->nullable(); // script / spoken text
            $table->string('voice_id')->nullable()->index();
            $table->string('voice_name')->nullable();
            $table->boolean('use_custom_voice')->default(false);

            // custom voice sample uploads, reference audio
            $table->json('input_assets')->nullable();
            // stability, clarity, speed, language, emotion, etc.
            $table->json('settings')->nullable();

            $table->string('status', 32)->default('pending')->index();
            $table->string('fal_request_id')->nullable()->index();
            $table->string('fal_status_url')->nullable();
            $table->string('fal_response_url')->nullable();
            $table->unsignedInteger('queue_position')->nullable();
            $table->string('progress_message')->nullable();

            $table->json('result_assets')->nullable();
            $table->string('result_preview_url', 2048)->nullable();
            $table->string('result_audio_url', 2048)->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();

            $table->text('error_message')->nullable();
            $table->string('error_type')->nullable();

            $table->decimal('credits_charged', 12, 4)->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_public')->default(false);

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_voice_creations');
        Schema::dropIfExists('user_music_creations');
        Schema::dropIfExists('user_video_creations');
        Schema::dropIfExists('user_image_creations');
    }
};
