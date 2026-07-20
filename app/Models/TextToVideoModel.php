<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TextToVideoModel extends Model
{
    protected $table = 'text_to_video_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'enums' => 'array',
            'aspect_ratios' => 'array',
            'resolutions' => 'array',
            'unit_price' => 'float',
            'max_duration' => 'integer',
            'supports_audio' => 'boolean',
            'supports_first_frame' => 'boolean',
            'supports_last_frame' => 'boolean',
            'sort' => 'integer',
        ];
    }
}
