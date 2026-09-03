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
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'cost_usd_source')) {
                    $table->string('cost_usd_source', 32)->nullable()->after('cost_usd');
                }
                if (! Schema::hasColumn($tableName, 'cost_usd_is_final')) {
                    $table->boolean('cost_usd_is_final')->default(false)->after('cost_usd_source');
                }
                if (! Schema::hasColumn($tableName, 'settled_at')) {
                    $table->timestamp('settled_at')->nullable()->after('cost_usd_is_final');
                }
                if (! Schema::hasColumn($tableName, 'cost_settled_notified_at')) {
                    $table->timestamp('cost_settled_notified_at')->nullable()->after('settled_at');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $drop = [];
                foreach (['cost_usd_source', 'cost_usd_is_final', 'settled_at', 'cost_settled_notified_at'] as $col) {
                    if (Schema::hasColumn($tableName, $col)) {
                        $drop[] = $col;
                    }
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
