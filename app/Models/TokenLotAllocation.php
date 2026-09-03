<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenLotAllocation extends Model
{
    protected $fillable = [
        'token_lot_id',
        'user_id',
        'creation_type',
        'creation_id',
        'kind',
        'tokens',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(TokenLot::class, 'token_lot_id');
    }
}
