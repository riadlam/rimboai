<?php

namespace App\Console\Commands;

use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Models\UserVoiceCreation;
use App\Services\FalFailureRefundDecider;
use App\Services\FalWalletCostTracker;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared-hosting safety net: fill cost_usd / wallet-after for recent creations
 * when delayed queue jobs never ran (no queue:work daemon).
 */
class FalReconcileWalletCosts extends Command
{
    protected $signature = 'fal:reconcile-wallet-costs
                            {--hours=48 : Look back this many hours}
                            {--limit=40 : Max creations to reconcile per run}';

    protected $description = 'Reconcile fal cost_usd + wallet snapshots for recent Lab creations';

    public function handle(FalWalletCostTracker $tracker, FalFailureRefundDecider $refundDecider): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $limit = max(1, min(100, (int) $this->option('limit')));
        $since = now()->subHours($hours);

        $types = [
            'image' => UserImageCreation::class,
            'video' => UserVideoCreation::class,
            'music' => UserMusicCreation::class,
            'voice' => UserVoiceCreation::class,
        ];

        $done = 0;

        foreach ($types as $type => $class) {
            /** @var Builder $query */
            $query = $class::query()
                ->where('created_at', '>=', $since)
                ->whereNotNull('fal_request_id')
                ->where(function (Builder $q) {
                    $q->whereNull('cost_usd')
                        ->orWhereNull('fal_wallet_balance_after');
                })
                ->whereIn('status', ['completed', 'failed'])
                ->orderByDesc('id')
                ->limit($limit);

            /** @var Model $creation */
            foreach ($query->get() as $creation) {
                $ageMinutes = $creation->created_at?->diffInMinutes(now()) ?? 0;
                $finalizeZero = $ageMinutes >= 20 || (string) $creation->getAttribute('status') === 'failed';

                $tracker->reconcile($creation, $finalizeZero && (string) $creation->status === 'failed');
                $creation->refresh();

                if ((string) $creation->getAttribute('status') === 'failed') {
                    $refundDecider->finalize($type, $creation, $finalizeZero);
                }

                $done++;
                $this->line(sprintf(
                    '%s#%s status=%s cost=%s after=%s',
                    $type,
                    $creation->getKey(),
                    $creation->getAttribute('status'),
                    $creation->getAttribute('cost_usd') ?? 'null',
                    $creation->getAttribute('fal_wallet_balance_after') ?? 'null',
                ));
            }
        }

        $this->info("Reconciled {$done} creation(s).");

        return self::SUCCESS;
    }
}
