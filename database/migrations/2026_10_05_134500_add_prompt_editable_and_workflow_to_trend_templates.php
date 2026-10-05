<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trend_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('trend_templates', 'prompt_editable')) {
                $table->boolean('prompt_editable')->default(false)->after('prompt');
            }
            if (! Schema::hasColumn('trend_templates', 'workflow')) {
                $table->string('workflow', 32)->nullable()->after('endpoint_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('trend_templates', function (Blueprint $table) {
            if (Schema::hasColumn('trend_templates', 'prompt_editable')) {
                $table->dropColumn('prompt_editable');
            }
            if (Schema::hasColumn('trend_templates', 'workflow')) {
                $table->dropColumn('workflow');
            }
        });
    }
};
