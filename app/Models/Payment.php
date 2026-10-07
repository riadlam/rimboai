<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'telegram_message_id',
        'paid_at',
        'reviewed_at',
        'review_decision',
    ];

    protected function casts(): array
    {
        return [
            'tokens' => 'integer',
            'amount' => 'decimal:2',
            'create_response' => 'array',
            'last_check_response' => 'array',
            'telegram_message_id' => 'integer',
            'paid_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** Bank-verified, waiting for Telegram Accept before tokens are credited. */
    public function isAwaitingReview(): bool
    {
        return $this->status === 'review';
    }

    public function wasDeclined(): bool
    {
        return $this->status === 'failed' && $this->review_decision === 'declined';
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
