<?php

namespace App\Jobs;

use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Models\UserVoiceCreation;
use App\Services\FalFailureRefundDecider;
use App\Services\FalWalletCostTracker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReconcileFalCreationCostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public string $creationType,
        public int $creationId,
        public int $attempt = 1,
    ) {}

    public function handle(FalWalletCostTracker $tracker, FalFailureRefundDecider $refundDecider): void
    {
        $creation = match ($this->creationType) {
            'image' => UserImageCreation::query()->find($this->creationId),
            'video' => UserVideoCreation::query()->find($this->creationId),
            'music' => UserMusicCreation::query()->find($this->creationId),
            'voice' => UserVoiceCreation::query()->find($this->creationId),
            default => null,
        };

        if ($creation === null) {
            return;
        }

        $tracker->reconcile($creation);
        $creation->refresh();

        $exhausted = $this->attempt >= 5;

        if ((string) $creation->getAttribute('status') === 'failed') {
            $refundDecider->finalize($this->creationType, $creation, $exhausted);

            if ($exhausted) {
                return;
            }
        }

        if ($tracker->isFullyReconciled($creation->fresh())) {
            return;
        }

        if ($exhausted) {
            Log::warning('fal wallet cost reconcile exhausted retries', [
                'type' => $this->creationType,
                'id' => $this->creationId,
                'fal_request_id' => $creation->fal_request_id,
                'cost_usd' => $creation->cost_usd,
            ]);

            return;
        }

        $tracker->scheduleReconcileIfNeeded($creation, $this->attempt + 1);
    }
}
