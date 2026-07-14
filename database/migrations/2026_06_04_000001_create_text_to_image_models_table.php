<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_to_image_models', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint_id')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_to_image_models');
    }
};
