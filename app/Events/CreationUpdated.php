<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pushes Lab creation status to the owner's browser (Pusher).
 *
 * Uses ShouldBroadcastNow so shared-host cron lag does not delay UI updates.
 */
class CreationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $userId,
        public string $type,
        public int $creationId,
        public array $payload,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'creation.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'creation_id' => $this->creationId,
            'creation' => $this->payload,
        ];
    }
}
