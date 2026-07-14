<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TextToMusicExample extends Model
{
    protected $table = 'text_to_music_examples';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'vocals' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(TextToMusicModel::class, 'text_to_music_model_id');
    }

    /** Preferred playable sample URL (local first). Empty until you paste one. */
    public function playableSampleUrl(): ?string
    {
        return $this->sample_url ?: $this->sample_remote_url;
    }
}
