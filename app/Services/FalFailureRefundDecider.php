<?php

namespace App\Services;

use App\Models\User;
use App\Services\Tokens\TokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * After a fal failure, reconcile wallet cost first, then refund Rimbo tokens only if fal did not bill.
 */
class FalFailureRefundDecider
{
    public function __construct(
        private readonly FalWalletCostTracker $walletCost,
        private readonly TokenService $tokens,
    ) {}

    /**
     * Capture fal cost/wallet snapshots and refund user tokens when fal did not charge.
     *
     * @param  bool  $billingExhausted  True when delayed billing retries have finished.
     */
    public function finalize(string $type, Model $creation, bool $billingExhausted = false): void
    {
        $creation->refresh();

        if ((string) $creation->getAttribute('status') !== 'failed') {
            return;
        }

        if (! $creation->getAttribute('fal_request_id')) {
            return;
        }

        try {
            $this->walletCost->recordAfterFailure($creation);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('fal.failure.wallet_reconcile_failed', [
                'type' => $type,
                'creation_id' => $creation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }

        $creation->refresh();

        if ($this->walletCost->wasFalCharged($creation)) {
            Log::info('fal.failure.no_user_refund_charged', [
                'type' => $type,
                'creation_id' => $creation->getKey(),
                'request_id' => $creation->getAttribute('fal_request_id'),
                'cost_usd' => $creation->getAttribute('cost_usd'),
                'deducted' => $creation->getAttribute('deducted_amount_from_main_wallet'),
            ]);

            return;
        }

        if (! $billingExhausted && $this->walletCost->billingMayStillArrive($creation)) {
            Log::info('fal.failure.refund_deferred_billing', [
                'type' => $type,
                'creation_id' => $creation->getKey(),
                'request_id' => $creation->getAttribute('fal_request_id'),
            ]);

            return;
        }

        /** @var User|null $user */
        $user = User::query()->find($creation->getAttribute('user_id'));
        if ($user === null) {
            return;
        }

        $refunded = $this->tokens->refund($user, $creation, $type, 'fal_failed_no_charge');

        Log::info('fal.failure.user_refund_decision', [
            'type' => $type,
            'creation_id' => $creation->getKey(),
            'request_id' => $creation->getAttribute('fal_request_id'),
            'refunded' => $refunded,
            'cost_usd' => $creation->getAttribute('cost_usd'),
            'deducted' => $creation->getAttribute('deducted_amount_from_main_wallet'),
            'billing_exhausted' => $billingExhausted,
        ]);
    }
}
