<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per token-pack purchase attempt. Modelled on DiasZone's
 * sofizpay_cib_transactions, but self-contained (no separate orders table).
 * Payment authenticity is verified server-to-server on the return URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique()->comment('Our order number, sent to SofizPay as memo');
            $table->string('provider', 32)->default('sofizpay');
            $table->string('package_slug', 64)->nullable();
            $table->unsignedBigInteger('tokens')->comment('Tokens to credit on success');
            $table->decimal('amount', 12, 2)->comment('Canonical expected amount');
            $table->string('currency', 8)->default('DZD');
            $table->string('status', 32)->default('pending')->index()->comment('pending|paid|failed|canceled');
            $table->string('transaction_id')->nullable()->index()->comment('SofizPay UUID from create response');
            $table->string('cib_order_number', 64)->nullable()->index()->comment('Value used with cib-transaction-check');
            $table->string('cib_order_id', 128)->nullable()->comment('SATIM mdOrder / orderId from CIB');
            $table->json('create_response')->nullable();
            $table->json('last_check_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
