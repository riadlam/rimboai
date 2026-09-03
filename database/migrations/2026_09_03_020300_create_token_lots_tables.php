<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 24)->index();
            $table->unsignedBigInteger('tokens_total');
            $table->unsignedBigInteger('tokens_remaining');
            $table->decimal('amount_dzd', 14, 2)->default(0);
            $table->decimal('fee_dzd', 14, 2)->default(0);
            $table->decimal('net_dzd', 14, 2)->default(0);
            $table->decimal('usd_dzd_rate', 12, 4)->nullable();
            $table->decimal('net_usd', 14, 6)->nullable();
            $table->decimal('usd_per_token', 16, 10)->nullable();
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'tokens_remaining', 'id']);
        });

        Schema::create('token_lot_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('token_lot_id')->constrained('token_lots')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('creation_type', 32);
            $table->unsignedBigInteger('creation_id');
            $table->string('kind', 16);
            $table->unsignedBigInteger('tokens');
            $table->timestamps();

            $table->index(['creation_type', 'creation_id', 'kind']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_lot_allocations');
        Schema::dropIfExists('token_lots');
    }
};
