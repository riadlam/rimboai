<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUserCreation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserVoiceCreation extends Model
{
    use BelongsToUserCreation;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'mode',
        'endpoint_id',
        'model_name',
        'prompt',
        'voice_id',
        'voice_name',
        'use_custom_voice',
        'input_assets',
        'settings',
        'status',
        'fal_request_id',
        'fal_status_url',
        'fal_response_url',
        'queue_position',
        'progress_message',
        'result_assets',
        'result_preview_url',
        'result_audio_url',
        'duration_seconds',
        'error_message',
        'error_type',
        'credits_charged',
        'trend_cost',
        'fal_wallet_balance_before',
        'fal_wallet_balance_after',
        'deducted_amount_from_main_wallet',
        'cost_usd',
        'is_favorite',
        'is_public',
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
            'use_custom_voice' => 'boolean',
            'duration_seconds' => 'integer',
            'queue_position' => 'integer',
            'credits_charged' => 'decimal:4',
            'trend_cost' => 'decimal:4',
            'fal_wallet_balance_before' => 'decimal:6',
            'fal_wallet_balance_after' => 'decimal:6',
            'deducted_amount_from_main_wallet' => 'decimal:6',
            'cost_usd' => 'decimal:8',
            'is_favorite' => 'boolean',
            'is_public' => 'boolean',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
