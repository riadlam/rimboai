<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical, server-owned token pack catalogue. Prices and token amounts live
 * here (not on the client) so a purchase can never be tampered with from the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_packages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->unsignedBigInteger('tokens');
            $table->decimal('price_dzd', 12, 2);
            $table->unsignedInteger('sort')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // Seed the four packs shown on the Pricing page (DZD is canonical).
        $now = now();
        DB::table('token_packages')->insert([
            ['slug' => 'starter',  'name' => 'Starter',  'tokens' => 5000,   'price_dzd' => 3750,  'sort' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'creator',  'name' => 'Creator',  'tokens' => 15000,  'price_dzd' => 10000, 'sort' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'pro',      'name' => 'Pro',      'tokens' => 30000,  'price_dzd' => 18750, 'sort' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'business', 'name' => 'Business', 'tokens' => 100000, 'price_dzd' => 55000, 'sort' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('token_packages');
    }
};
