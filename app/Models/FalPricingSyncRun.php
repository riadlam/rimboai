<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FalPricingSyncRun extends Model
{
    protected $fillable = [
        'status',
        'requested',
        'observed',
        'priced',
        'published',
        'quarantined',
        'kept_last_good',
        'deactivated',
        'reactivated',
        'coverage',
        'dry_run',
        'tables',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'tables' => 'array',
            'dry_run' => 'boolean',
            'coverage' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function observations(): HasMany
    {
        return $this->hasMany(FalPricingObservation::class, 'sync_run_id');
    }
}
