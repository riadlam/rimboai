<?php

namespace App\Models\Concerns;

use App\Models\User;
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

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ], true);
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
