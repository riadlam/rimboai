<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TextToVoiceModel extends Model
{
    protected $table = 'text_to_voice_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'enums' => 'array',
            'unit_price' => 'float',
            'max_duration' => 'integer',
            'sort' => 'integer',
        ];
    }

    public function voices(): HasMany
    {
        return $this->hasMany(TextToVoiceVoice::class, 'text_to_voice_model_id')
            ->orderBy('sort')
            ->orderBy('name');
    }
}
