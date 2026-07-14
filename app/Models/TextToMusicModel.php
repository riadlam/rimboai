<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TextToMusicModel extends Model
{
    protected $table = 'text_to_music_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'enums' => 'array',
            'unit_price' => 'float',
            'max_duration' => 'integer',
            'default_duration_seconds' => 'integer',
            'max_lyrics_chars' => 'integer',
            'max_prompt_chars' => 'integer',
            'supports_vocals' => 'boolean',
            'supports_lyrics' => 'boolean',
            'supports_instrumental' => 'boolean',
            'supports_audio' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TextToMusicCategory::class, 'category_id');
    }

    public function examples(): HasMany
    {
        return $this->hasMany(TextToMusicExample::class, 'text_to_music_model_id')
            ->orderBy('sort')
            ->orderBy('id');
    }
}
