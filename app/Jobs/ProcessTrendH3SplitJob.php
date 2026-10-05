<?php

namespace App\Jobs;

use App\Models\UserVideoCreation;
use App\Services\Trends\TrendH3SplitProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Short-tick state machine for MiniMax H3 split remakes (fits 50s queue:work windows).
 */
class ProcessTrendH3SplitJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    private const MAX_TICKS = 400;

    private const DELAY_SECONDS = 12;

    public function __construct(
        public int $creationId,
        public int $tick = 1,
    ) {
        $this->onConnection('database');
    }

    public function handle(TrendH3SplitProcessor $processor): void
    {
        $creation = UserVideoCreation::query()->find($this->creationId);
        if ($creation === null) {
            return;
        }

        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            return;
        }

        try {
            $processor->tick($creation);
        } catch (\Throwable $e) {
            report($e);
            Log::error('trends.h3.split_tick_failed', [
                'creation_id' => $this->creationId,
                'tick' => $this->tick,
                'error' => $e->getMessage(),
            ]);
            // Do not keep spinning forever — fail + broadcast so the UI unlocks.
            $processor->abort(
                $creation,
                $e->getMessage() !== '' ? $e->getMessage() : 'H3 split failed unexpectedly.',
                'h3_tick_error',
            );

            return;
        }

        $creation->refresh();
        if (method_exists($creation, 'isTerminal') && $creation->isTerminal()) {
            return;
        }

        if ($this->tick >= self::MAX_TICKS) {
            Log::warning('trends.h3.split_exhausted', ['creation_id' => $this->creationId]);
            $processor->forceTimeout($creation);

            return;
        }

        self::dispatch($this->creationId, $this->tick + 1)
            ->onConnection('database')
            ->delay(now()->addSeconds(self::DELAY_SECONDS));
    }
}
