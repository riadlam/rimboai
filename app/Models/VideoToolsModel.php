<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoToolsModel extends Model
{
    protected $table = 'video_tools_models';

    protected $fillable = [
        'sort',
        'tool_slug',
        'tool_name',
        'endpoint_id',
        'name',
        'description',
        'image_url',
        'image_cover',
        'tags',
        'status',
        'unit',
        'unit_price',
        'token_cost',
        'ref_cost_usd',
        'ref_duration_seconds',
        'max_duration',
        'enums',
        'is_primary',
        'defaults',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'enums' => 'array',
            'defaults' => 'array',
            'unit_price' => 'float',
            'ref_cost_usd' => 'float',
            'token_cost' => 'integer',
            'ref_duration_seconds' => 'integer',
            'max_duration' => 'integer',
            'sort' => 'integer',
            'is_primary' => 'boolean',
        ];
    }
}
