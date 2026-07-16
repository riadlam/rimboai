<?php

namespace App\Services;

use App\Jobs\ReconcileFalCreationCostJob;
use App\Models\UserImageCreation;
use App\Models\UserMusicCreation;
use App\Models\UserVideoCreation;
use App\Models\UserVoiceCreation;
use Illuminate\Database\Eloquent\Model;

/**
 * Persists fal wallet snapshots + actual cost_usd onto creation rows.
 *
 * fal often bills a few seconds after COMPLETED, so we reconcile immediately
 * and dispatch delayed retries. cost_usd from billing-events is authoritative.
 */
class FalWalletCostTracker
{
    public function __construct(
        private FalAccountService $account,
    ) {}

    public function recordBalanceBefore(Model $creation): void
    {
        if ($creation->getAttribute('fal_wallet_balance_before') !== null) {
            return;
        }

        $balance = $this->account->getCreditBalance();
        if ($balance === null) {
            return;
        }

        $creation->forceFill([
            'fal_wallet_balance_before' => $balance,
        ])->save();
    }

    /**
     * After a successful COMPLETED save: try cost + wallet, then schedule retries.
     */
    public function recordAfterCompletion(Model $creation): void
    {
        $this->reconcile($creation);
        $this->scheduleReconcileIfNeeded($creation);
    }

    /**
     * After a failed fal job: reconcile actual fal billing before deciding user refunds.
     */
    public function recordAfterFailure(Model $creation): void
    {
        $this->reconcile($creation);
        $this->scheduleReconcileIfNeeded($creation);
    }

    /**
     * True when fal billed this request (authoritative cost or wallet movement).
     */
    public function wasFalCharged(Model $creation): bool
    {
        $cost = $creation->getAttribute('cost_usd');
        if ($cost !== null && is_numeric($cost) && (float) $cost > 0) {
            return true;
        }

        $deducted = $creation->getAttribute('deducted_amount_from_main_wallet');
        if ($deducted !== null && is_numeric($deducted) && (float) $deducted > 0) {
            return true;
        }

        $before = $creation->getAttribute('fal_wallet_balance_before');
        $after = $creation->getAttribute('fal_wallet_balance_after');
        if ($before !== null && $after !== null && is_numeric($before) && is_numeric($after)) {
            return (float) $after < (float) $before - 0.0000001;
        }

        return false;
    }

    /**
     * Billing events can arrive seconds after ERROR; wait before refunding tokens.
     */
    public function billingMayStillArrive(Model $creation): bool
    {
        if ($this->wasFalCharged($creation)) {
            return false;
        }

        if (! $creation->getAttribute('fal_request_id')) {
            return false;
        }

        return $creation->getAttribute('cost_usd') === null;
    }

    /**
     * Retry filling cost / wallet when billing events lag behind completion.
     * Does not schedule jobs (status polls call this; completion path schedules).
     */
    public function maybeFillCostUsd(Model $creation): void
    {
        if ($this->isFullyReconciled($creation)) {
            return;
        }

        $this->reconcile($creation);
    }

    /**
     * Fill cost_usd from fal billing-events; set deducted + after from that cost.
     *
     * @return bool True when cost_usd is present after this call
     */
    public function reconcile(Model $creation): bool
    {
        $updates = [];

        $cost = $creation->getAttribute('cost_usd');
        if ($cost === null) {
            $resolved = $this->resolveCostUsd($creation);
            if ($resolved !== null) {
                $cost = $resolved;
                $updates['cost_usd'] = $resolved;
            }
        } else {
            $cost = is_numeric($cost) ? (float) $cost : null;
        }

        $before = $creation->getAttribute('fal_wallet_balance_before');
        $beforeOk = $before !== null && is_numeric($before);

        if ($cost !== null && $beforeOk) {
            // Per-request accounting from fal's bill (immune to concurrent wallet noise).
            $updates['deducted_amount_from_main_wallet'] = (float) $cost;
            $updates['fal_wallet_balance_after'] = (float) $before - (float) $cost;
        } elseif ($cost !== null) {
            $updates['deducted_amount_from_main_wallet'] = (float) $cost;

            $after = $creation->getAttribute('fal_wallet_balance_after');
            if ($after === null || $this->balancesEqual($after, $before)) {
                $live = $this->account->getCreditBalance();
                if ($live !== null) {
                    $updates['fal_wallet_balance_after'] = $live;
                }
            }
        } elseif ($beforeOk && $this->walletAfterNeedsRefresh($creation)) {
            // Billing not ready yet — only store a live after if the wallet actually moved.
            $live = $this->account->getCreditBalance();
            if ($live !== null && ! $this->balancesEqual($live, $before)) {
                $updates['fal_wallet_balance_after'] = $live;
                $updates['deducted_amount_from_main_wallet'] = (float) $before - $live;
            }
        }

        if ($updates !== []) {
            $creation->forceFill($updates)->save();
        }

        $finalCost = $creation->getAttribute('cost_usd');

        return $finalCost !== null && is_numeric($finalCost);
    }

    public function scheduleReconcileIfNeeded(Model $creation, int $attempt = 1): void
    {
        if ($this->isFullyReconciled($creation)) {
            return;
        }

        $type = $this->creationType($creation);
        if ($type === null) {
            return;
        }

        $delaySeconds = match ($attempt) {
            1 => 15,
            2 => 45,
            3 => 90,
            4 => 180,
            default => 300,
        };

        ReconcileFalCreationCostJob::dispatch($type, (int) $creation->getKey(), $attempt)
            ->delay(now()->addSeconds($delaySeconds));
    }

    public function isFullyReconciled(Model $creation): bool
    {
        $cost = $creation->getAttribute('cost_usd');
        if ($cost === null || ! is_numeric($cost)) {
            return false;
        }

        $deducted = $creation->getAttribute('deducted_amount_from_main_wallet');
        if ($deducted === null || ! is_numeric($deducted)) {
            return false;
        }

        // Stale freeze: before==after with zero deducted while we now know there was a cost.
        $before = $creation->getAttribute('fal_wallet_balance_before');
        $after = $creation->getAttribute('fal_wallet_balance_after');
        if ($before !== null && $after !== null && $this->balancesEqual($before, $after) && (float) $cost > 0) {
            return false;
        }

        return true;
    }

    private function walletAfterNeedsRefresh(Model $creation): bool
    {
        $after = $creation->getAttribute('fal_wallet_balance_after');
        if ($after === null) {
            return true;
        }

        $before = $creation->getAttribute('fal_wallet_balance_before');
        $deducted = $creation->getAttribute('deducted_amount_from_main_wallet');

        return $this->balancesEqual($after, $before)
            && ($deducted === null || abs((float) $deducted) < 0.0000001);
    }

    private function balancesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null || ! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        return abs((float) $a - (float) $b) < 0.0000001;
    }

    private function resolveCostUsd(Model $creation): ?float
    {
        $requestId = (string) ($creation->getAttribute('fal_request_id') ?? '');
        if ($requestId === '') {
            return null;
        }

        $createdAt = $creation->getAttribute('created_at') ?? $creation->getAttribute('queued_at');
        $start = $createdAt
            ? \Illuminate\Support\Carbon::parse($createdAt)->subHour()->toIso8601String()
            : now()->subDay()->toIso8601String();
        $end = now()->addHour()->toIso8601String();

        return $this->account->getRequestCostUsd($requestId, $start, $end);
    }

    private function creationType(Model $creation): ?string
    {
        return match (true) {
            $creation instanceof UserImageCreation => 'image',
            $creation instanceof UserVideoCreation => 'video',
            $creation instanceof UserMusicCreation => 'music',
            $creation instanceof UserVoiceCreation => 'voice',
            default => null,
        };
    }
}
