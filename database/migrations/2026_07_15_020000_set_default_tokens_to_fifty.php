<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'tokens')) {
            return;
        }

        // New accounts receive 50 starter tokens.
        DB::statement('ALTER TABLE `users` ALTER COLUMN `tokens` SET DEFAULT 50');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'tokens')) {
            return;
        }

        DB::statement('ALTER TABLE `users` ALTER COLUMN `tokens` SET DEFAULT 100');
    }
};
