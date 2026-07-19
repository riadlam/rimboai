<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToUserCreation
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwnedBy(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Cast both sides — MySQL PDO often returns user_id as string; === would 403.
        return (int) $this->user_id === (int) $user->getAuthIdentifier();
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Soft-hide from Lab / History UI (delete + dismiss failed).
     * Kept when discarded is null or any value other than 1.
     */
    public function scopeNotDiscarded($query)
    {
        return $query->where(function ($inner) {
            $inner->whereNull('discarded')->orWhere('discarded', '!=', 1);
        });
    }

    public function markDiscarded(): void
    {
        $this->forceFill(['discarded' => 1])->save();
    }

    public function markQueued(?string $requestId = null, ?string $statusUrl = null, ?string $responseUrl = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_QUEUED,
            'fal_request_id' => $requestId ?? $this->fal_request_id,
            'fal_status_url' => $statusUrl ?? $this->fal_status_url,
            'fal_response_url' => $responseUrl ?? $this->fal_response_url,
            'queued_at' => $this->queued_at ?? now(),
            'progress_message' => 'In queue',
            'error_message' => null,
            'error_type' => null,
        ])->save();
    }

    public function markInProgress(?int $queuePosition = null, ?string $message = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_IN_PROGRESS,
            'queue_position' => $queuePosition,
            'started_at' => $this->started_at ?? now(),
            'progress_message' => $message ?? 'Generating…',
        ])->save();
    }

    public function markFailed(?string $message = null, ?string $type = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
            'error_type' => $type,
            'progress_message' => 'Failed',
            'completed_at' => now(),
        ])->save();
    }
}
