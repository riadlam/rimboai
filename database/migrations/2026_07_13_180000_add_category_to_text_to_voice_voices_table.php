<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\VoiceUseCaseClassifier;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('text_to_voice_voices')) {
            return;
        }

        if (! Schema::hasColumn('text_to_voice_voices', 'category')) {
            Schema::table('text_to_voice_voices', function (Blueprint $table) {
                $table->string('category', 64)->nullable()->after('gender')->index();
            });
        }

        $classifier = new VoiceUseCaseClassifier;

        DB::table('text_to_voice_voices')->orderBy('id')->chunkById(100, function ($rows) use ($classifier) {
            foreach ($rows as $row) {
                $tags = [];
                if (isset($row->tags) && $row->tags !== null && $row->tags !== '') {
                    $decoded = is_string($row->tags) ? json_decode($row->tags, true) : $row->tags;
                    $tags = is_array($decoded) ? array_values($decoded) : [];
                }

                $category = $classifier->classify(
                    (string) $row->name,
                    $row->description,
                    (string) $row->voice_key,
                    $tags,
                );

                DB::table('text_to_voice_voices')
                    ->where('id', $row->id)
                    ->update(['category' => $category]);
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('text_to_voice_voices') && Schema::hasColumn('text_to_voice_voices', 'category')) {
            Schema::table('text_to_voice_voices', function (Blueprint $table) {
                $table->dropColumn('category');
            });
        }
    }
};
