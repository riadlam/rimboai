<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('text_to_voice_categories')) {
            Schema::create('text_to_voice_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('sort')->default(999)->index();
                $table->string('name');
                $table->string('icon_url')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('text_to_voice_models')) {
            Schema::create('text_to_voice_models', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('sort')->default(999)->index();
                $table->string('endpoint_id')->nullable();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->string('image_url')->nullable();
                $table->string('image_cover')->nullable();
                $table->json('tags')->nullable();
                $table->string('status')->nullable();
                $table->string('unit')->nullable();
                $table->decimal('unit_price', 12, 6)->nullable();
                $table->integer('max_duration')->nullable();
                $table->json('enums')->nullable();
                $table->timestamps();

                $table->foreignId('category_id')
                    ->nullable()
                    ->constrained('text_to_voice_categories')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('text_to_voice_models');
        Schema::dropIfExists('text_to_voice_categories');
    }
};
