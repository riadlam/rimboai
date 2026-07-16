<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Persists fal wallet snapshots + actual cost_usd onto creation rows.
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
     * After a successful COMPLETED save: wallet after, deducted delta, cost_usd.
     */
    public function recordAfterCompletion(Model $creation): void
    {
        $updates = [];

        if ($creation->getAttribute('fal_wallet_balance_after') === null) {
            $after = $this->account->getCreditBalance();
            if ($after !== null) {
                $updates['fal_wallet_balance_after'] = $after;

                $before = $creation->getAttribute('fal_wallet_balance_before');
                if ($before !== null && is_numeric($before)) {
                    $updates['deducted_amount_from_main_wallet'] = (float) $before - $after;
                }
            }
        }

        if ($creation->getAttribute('cost_usd') === null) {
            $cost = $this->resolveCostUsd($creation);
            if ($cost !== null) {
                $updates['cost_usd'] = $cost;
            }
        }

        if ($updates === []) {
            return;
        }

        $creation->forceFill($updates)->save();
    }

    /**
     * Retry filling cost_usd when billing events lag behind completion.
     */
    public function maybeFillCostUsd(Model $creation): void
    {
        if ($creation->getAttribute('cost_usd') !== null) {
            return;
        }

        $cost = $this->resolveCostUsd($creation);
        if ($cost === null) {
            return;
        }

        $creation->forceFill(['cost_usd' => $cost])->save();
    }

    private function resolveCostUsd(Model $creation): ?float
    {
        $requestId = (string) ($creation->getAttribute('fal_request_id') ?? '');
        if ($requestId === '') {
            return null;
        }

        return $this->account->getRequestCostUsd($requestId);
    }
}
