<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FalPricingObservation extends Model
{
    protected $fillable = [
        'sync_run_id',
        'model_table',
        'model_id',
        'endpoint_id',
        'name',
        'raw_unit',
        'raw_price',
        'currency',
        'normalized_unit',
        'normalized_price',
        'fal_status',
        'decision',
        'anomaly',
        'anomaly_reason',
        'payload',
        'raw_payload',
        'raw_unit_price',
        'status',
        'observed_at',
        'checked_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_price' => 'decimal:8',
            'normalized_price' => 'decimal:8',
            'payload' => 'array',
            'raw_payload' => 'array',
            'checked_at' => 'datetime',
            'published_at' => 'datetime',
            'observed_at' => 'datetime',
            'raw_unit_price' => 'decimal:8',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FalPricingSyncRun::class, 'sync_run_id');
    }
}
