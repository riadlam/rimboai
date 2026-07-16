<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'user_image_creations',
        'user_video_creations',
        'user_music_creations',
        'user_voice_creations',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->decimal('fal_wallet_balance_before', 14, 6)->nullable()->after('credits_charged');
                $table->decimal('fal_wallet_balance_after', 14, 6)->nullable()->after('fal_wallet_balance_before');
                $table->decimal('deducted_amount_from_main_wallet', 14, 6)->nullable()->after('fal_wallet_balance_after');
                $table->decimal('cost_usd', 14, 8)->nullable()->after('deducted_amount_from_main_wallet');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn([
                    'fal_wallet_balance_before',
                    'fal_wallet_balance_after',
                    'deducted_amount_from_main_wallet',
                    'cost_usd',
                ]);
            });
        }
    }
};
