<?php

namespace App\Services\Tokens;

use App\Models\TokenLot;
use App\Models\TokenLotAllocation;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class TokenLotLedger
{
    public function enabled(): bool
    {
        return Schema::hasTable('token_lots') && Schema::hasTable('token_lot_allocations');
    }

    /**
     * @param  array{amount_dzd?: float, fee_dzd?: float, net_dzd?: float, usd_dzd_rate?: float, net_usd?: float, usd_per_token?: float, payment_id?: int|null}  $economics
     */
    public function createLot(User $user, int $tokens, string $source, array $economics = []): ?TokenLot
    {
        if (! $this->enabled() || $tokens <= 0) {
            return null;
        }

        return TokenLot::query()->create([
            'user_id' => $user->getKey(),
            'source' => $source,
            'tokens_total' => $tokens,
            'tokens_remaining' => $tokens,
            'amount_dzd' => $economics['amount_dzd'] ?? 0,
            'fee_dzd' => $economics['fee_dzd'] ?? 0,
            'net_dzd' => $economics['net_dzd'] ?? 0,
            'usd_dzd_rate' => $economics['usd_dzd_rate'] ?? null,
            'net_usd' => $economics['net_usd'] ?? null,
            'usd_per_token' => $economics['usd_per_token'] ?? null,
            'payment_id' => $economics['payment_id'] ?? null,
        ]);
    }

    public function grantStarter(User $user, int $tokens): ?TokenLot
    {
        return $this->createLot($user, $tokens, TokenLot::SOURCE_STARTER, [
            'amount_dzd' => 0,
            'fee_dzd' => 0,
            'net_dzd' => 0,
        ]);
    }

    public function grantPurchase(User $user, int $tokens, float $amountDzd, int $paymentId): ?TokenLot
    {
        $feePercent = max(0.0, (float) config('credits.gateway_fee_percent', 0));
        $feeFixed = max(0.0, (float) config('credits.gateway_fee_fixed_dzd', 0));
        $fee = round(($amountDzd * $feePercent / 100) + $feeFixed, 2);
        $netDzd = max(0.0, round($amountDzd - $fee, 2));
        $rate = max(0.0001, (float) config('credits.usd_dzd_rate', 250));
        $netUsd = $rate > 0 ? round($netDzd / $rate, 6) : null;
        $perToken = $tokens > 0 && $netUsd !== null ? round($netUsd / $tokens, 10) : null;

        return $this->createLot($user, $tokens, TokenLot::SOURCE_PURCHASE, [
            'amount_dzd' => $amountDzd,
            'fee_dzd' => $fee,
            'net_dzd' => $netDzd,
            'usd_dzd_rate' => $rate,
            'net_usd' => $netUsd,
            'usd_per_token' => $perToken,
            'payment_id' => $paymentId,
        ]);
    }

    /**
     * Ensure a user with a leftover balance has at least one lot (legacy unknown cash).
     */
    public function ensureLegacyCover(User $user): void
    {
        if (! $this->enabled()) {
            return;
        }

        $balance = (int) $user->tokens;
        if ($balance <= 0) {
            return;
        }

        $remaining = (int) TokenLot::query()
            ->where('user_id', $user->getKey())
            ->sum('tokens_remaining');

        $gap = $balance - $remaining;
        if ($gap > 0) {
            $this->createLot($user, $gap, TokenLot::SOURCE_LEGACY);
        }
    }

    /**
     * Consume lots FIFO. Call inside the same DB transaction as the token debit.
     *
     * @return list<array{lot_id: int, tokens: int}>
     */
    public function consume(User $user, int $amount, string $creationType, int $creationId): array
    {
        if (! $this->enabled() || $amount <= 0) {
            return [];
        }

        $this->ensureLegacyCover($user);

        $needed = $amount;
        $used = [];
        $lots = TokenLot::query()
            ->where('user_id', $user->getKey())
            ->where('tokens_remaining', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lots as $lot) {
            if ($needed <= 0) {
                break;
            }
            $take = min($needed, (int) $lot->tokens_remaining);
            $lot->tokens_remaining = (int) $lot->tokens_remaining - $take;
            $lot->save();
            TokenLotAllocation::query()->create([
                'token_lot_id' => $lot->id,
                'user_id' => $user->getKey(),
                'creation_type' => $creationType,
                'creation_id' => $creationId,
                'kind' => 'debit',
                'tokens' => $take,
            ]);
            $used[] = ['lot_id' => (int) $lot->id, 'tokens' => $take];
            $needed -= $take;
        }

        return $used;
    }

    /**
     * Restore the exact debit allocations on refund. Call inside the refund transaction.
     */
    public function restore(User $user, string $creationType, int $creationId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $already = TokenLotAllocation::query()
            ->where('creation_type', $creationType)
            ->where('creation_id', $creationId)
            ->where('kind', 'refund')
            ->exists();
        if ($already) {
            return;
        }

        $debits = TokenLotAllocation::query()
            ->where('creation_type', $creationType)
            ->where('creation_id', $creationId)
            ->where('kind', 'debit')
            ->get();

        foreach ($debits as $debit) {
            $lot = TokenLot::query()->lockForUpdate()->find($debit->token_lot_id);
            if ($lot) {
                $lot->tokens_remaining = (int) $lot->tokens_remaining + (int) $debit->tokens;
                $lot->save();
            }
            TokenLotAllocation::query()->create([
                'token_lot_id' => $debit->token_lot_id,
                'user_id' => $user->getKey(),
                'creation_type' => $creationType,
                'creation_id' => $creationId,
                'kind' => 'refund',
                'tokens' => $debit->tokens,
            ]);
        }
    }
}
