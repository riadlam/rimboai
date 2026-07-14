<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUserCreation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserVideoCreation extends Model
{
    use BelongsToUserCreation;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'mode',
        'endpoint_id',
        'model_name',
        'prompt',
        'negative_prompt',
        'input_assets',
        'settings',
        'duration_value',
        'duration_seconds',
        'aspect_ratio',
        'resolution',
        'with_audio',
        'status',
        'fal_request_id',
        'fal_status_url',
        'fal_response_url',
        'queue_position',
        'progress_message',
        'result_assets',
        'result_preview_url',
        'result_video_url',
        'thumbnail_url',
        'error_message',
        'error_type',
        'credits_charged',
        'is_favorite',
        'is_public',
        'is_featured',
        'uses_count',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'input_assets' => 'array',
            'settings' => 'array',
            'result_assets' => 'array',
            'duration_seconds' => 'integer',
            'with_audio' => 'boolean',
            'queue_position' => 'integer',
            'credits_charged' => 'decimal:4',
            'is_favorite' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'uses_count' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
