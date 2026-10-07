<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SofizPay bank-verified payments wait in status=review until Telegram Accept.
 * Customers only ever see "pending"; reviewed_at/review_decision are admin audit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('reviewed_at')->nullable()->after('paid_at');
            $table->string('review_decision', 16)->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['reviewed_at', 'review_decision']);
        });
    }
};
