<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TokenLot extends Model
{
    public const SOURCE_PURCHASE = 'purchase';

    public const SOURCE_STARTER = 'starter';

    public const SOURCE_LEGACY = 'legacy';

    protected $fillable = [
        'user_id',
        'source',
        'tokens_total',
        'tokens_remaining',
        'amount_dzd',
        'fee_dzd',
        'net_dzd',
        'usd_dzd_rate',
        'net_usd',
        'usd_per_token',
        'payment_id',
    ];

    protected function casts(): array
    {
        return [
            'tokens_total' => 'integer',
            'tokens_remaining' => 'integer',
            'amount_dzd' => 'decimal:2',
            'fee_dzd' => 'decimal:2',
            'net_dzd' => 'decimal:2',
            'usd_dzd_rate' => 'decimal:4',
            'net_usd' => 'decimal:6',
            'usd_per_token' => 'decimal:10',
            'payment_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(TokenLotAllocation::class);
    }

    public function hasCashBasis(): bool
    {
        return $this->source === self::SOURCE_PURCHASE
            && $this->usd_per_token !== null
            && (float) $this->usd_per_token > 0;
    }
}
