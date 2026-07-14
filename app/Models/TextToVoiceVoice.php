<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TextToVoiceVoice extends Model
{
    protected $table = 'text_to_voice_voices';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_default' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(TextToVoiceModel::class, 'text_to_voice_model_id');
    }

    /** Preferred playable sample URL (local first). */
    public function playableSampleUrl(): ?string
    {
        return $this->sample_url ?: $this->sample_remote_url;
    }
}
