<?php

namespace App\Jobs;

use App\Models\UserVideoCreation;
use App\Services\HiggsfieldWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Poll Higgsfield status until terminal. Used because HF webhooks are not
 * always delivered; UI safety-net alone is not enough (hidden tabs skip polls).
 */
class PollHiggsfieldCreationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const MAX_ATTEMPTS = 120;

    private const DELAY_SECONDS = 10;

    public function __construct(
        public int $creationId,
        public int $attempt = 1,
    ) {
        $this->onConnection('database');
    }

    public function handle(HiggsfieldWebhookProcessor $processor): void
    {
        $creation = UserVideoCreation::query()->find($this->creationId);
        if ($creation === null) {
            return;
        }

        if (! HiggsfieldWebhookProcessor::isHiggsfieldCreation($creation)) {
            return;
        }

        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            return;
        }

        if (! $creation->fal_request_id && ! $creation->fal_status_url) {
            return;
        }

        try {
            $processor->sync($creation);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('higgsfield.poll.sync_failed', [
                'creation_id' => $this->creationId,
                'attempt' => $this->attempt,
                'error' => $e->getMessage(),
            ]);
        }

        $creation->refresh();
        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            Log::info('higgsfield.poll.terminal', [
                'creation_id' => $this->creationId,
                'status' => $creation->status,
                'attempt' => $this->attempt,
            ]);

            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            Log::warning('higgsfield.poll.exhausted', [
                'creation_id' => $this->creationId,
                'attempt' => $this->attempt,
                'status' => $creation->status,
            ]);

            return;
        }

        self::dispatch($this->creationId, $this->attempt + 1)
            ->onConnection('database')
            ->delay(now()->addSeconds(self::DELAY_SECONDS));
    }
}
