<?php

namespace App\Services;

use App\Events\CreationUpdated;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Broadcasts Lab creation snapshots to the owning user (Pusher).
 */
class CreationRealtime
{
    /**
     * @param  array<string, mixed>  $payload  Same shape as status JSON (+ type).
     */
    public function notify(string $type, Model $creation, array $payload): void
    {
        $userId = (int) $creation->getAttribute('user_id');
        if ($userId <= 0) {
            return;
        }

        try {
            if (! isset($payload['token_balance'])) {
                $payload['token_balance'] = (int) (User::query()->whereKey($userId)->value('tokens') ?? 0);
            }

            event(new CreationUpdated(
                userId: $userId,
                type: $type,
                creationId: (int) $creation->getKey(),
                payload: array_merge($payload, ['type' => $type]),
            ));
        } catch (\Throwable $e) {
            report($e);
            Log::warning('creation.realtime.broadcast_failed', [
                'type' => $type,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
