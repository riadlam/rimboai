<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('tokens')->default(100)->after('password');
        });

        Schema::create('token_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('creation_type', 32);
            $table->unsignedBigInteger('creation_id');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['creation_type', 'creation_id', 'kind'],
                'token_transactions_creation_kind_unique'
            );
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_transactions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tokens');
        });
    }
};
