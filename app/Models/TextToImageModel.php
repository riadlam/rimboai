<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TextToImageModel extends Model
{
    protected $table = 'text_to_image_models';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'aspect_ratios' => 'array',
            'resolutions' => 'array',
            'unit_price' => 'float',
            'sort' => 'integer',
        ];
    }
}
