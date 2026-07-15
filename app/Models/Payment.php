<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'payments';

    protected $fillable = [
        'user_id',
        'reference',
        'provider',
        'package_slug',
        'tokens',
        'amount',
        'currency',
        'status',
        'transaction_id',
        'cib_order_number',
        'cib_order_id',
        'create_response',
        'last_check_response',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'amount' => 'decimal:2',
            'create_response' => 'array',
            'last_check_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
