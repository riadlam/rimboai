<?php

namespace App\Services\Tokens;

use App\Events\TokensUpdated;
use App\Exceptions\InsufficientTokensException;
use App\Models\User;
use App\Services\CreationTelegramNotifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class TokenService
{
    /**
     * Atomically lock the user's balance, create a generation, and debit tokens.
     *
     * @template TCreation of Model
     *
     * @param  callable(): TCreation  $create
     * @return TCreation
     */
    public function reserve(User $user, int $amount, string $creationType, callable $create): Model
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Token charge must be greater than zero.');
        }

        $creation = DB::transaction(function () use ($user, $amount, $creationType, $create) {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $available = (int) $lockedUser->tokens;

            if ($available < $amount) {
                throw new InsufficientTokensException($amount, $available);
            }

            $creation = $create();
            if (! $creation instanceof Model || ! $creation->exists) {
                throw new InvalidArgumentException('Token reservation requires a persisted creation.');
            }

            $lockedUser->tokens = $available - $amount;
            $lockedUser->save();

            DB::table('token_transactions')->insert([
                'user_id' => $lockedUser->getKey(),
                'kind' => 'debit',
                'amount' => $amount,
                'balance_after' => $lockedUser->tokens,
                'creation_type' => $creationType,
                'creation_id' => $creation->getKey(),
                'metadata' => json_encode(['reason' => 'generation_reserved'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $user->setAttribute('tokens', $lockedUser->tokens);

            return $creation;
        }, 3);

        $this->broadcastBalance($user);

        try {
            app(CreationTelegramNotifier::class)->notifyStarted($user, $creationType, $creation);
        } catch (Throwable $e) {
            report($e);
        }

        return $creation;
    }

    /**
     * Idempotently add tokens to a user (e.g. after a successful purchase).
     *
     * Idempotency is enforced by the token_transactions unique index on
     * (creation_type, creation_id, kind); a repeated credit for the same
     * reference is a no-op and returns false.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function credit(User $user, int $amount, string $creationType, int|string $creationId, array $metadata = []): bool
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Token credit must be greater than zero.');
        }

        try {
            $applied = DB::transaction(function () use ($user, $amount, $creationType, $creationId, $metadata) {
                $alreadyCredited = DB::table('token_transactions')
                    ->where('creation_type', $creationType)
                    ->where('creation_id', $creationId)
                    ->where('kind', 'credit')
                    ->exists();

                if ($alreadyCredited) {
                    return false;
                }

                /** @var User $lockedUser */
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $lockedUser->tokens = (int) $lockedUser->tokens + $amount;
                $lockedUser->save();

                DB::table('token_transactions')->insert([
                    'user_id' => $lockedUser->getKey(),
                    'kind' => 'credit',
                    'amount' => $amount,
                    'balance_after' => $lockedUser->tokens,
                    'creation_type' => $creationType,
                    'creation_id' => $creationId,
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $user->setAttribute('tokens', $lockedUser->tokens);

                return true;
            }, 3);

            if ($applied) {
                $this->broadcastBalance($user);
            }

            return $applied;
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // A concurrent credit for the same reference won the race — already applied.
            return false;
        }
    }

    /**
     * Idempotently refund a reservation when fal rejects the initial submission.
     */
    public function refund(User $user, Model $creation, string $creationType, string $reason): bool
    {
        try {
            $applied = DB::transaction(function () use ($user, $creation, $creationType, $reason) {
                $debit = DB::table('token_transactions')
                    ->where('creation_type', $creationType)
                    ->where('creation_id', $creation->getKey())
                    ->where('kind', 'debit')
                    ->lockForUpdate()
                    ->first();

                if (! $debit) {
                    return false;
                }

                $alreadyRefunded = DB::table('token_transactions')
                    ->where('creation_type', $creationType)
                    ->where('creation_id', $creation->getKey())
                    ->where('kind', 'refund')
                    ->exists();

                if ($alreadyRefunded) {
                    return false;
                }

                /** @var User $lockedUser */
                $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $amount = (int) $debit->amount;
                $lockedUser->tokens = (int) $lockedUser->tokens + $amount;
                $lockedUser->save();

                DB::table('token_transactions')->insert([
                    'user_id' => $lockedUser->getKey(),
                    'kind' => 'refund',
                    'amount' => $amount,
                    'balance_after' => $lockedUser->tokens,
                    'creation_type' => $creationType,
                    'creation_id' => $creation->getKey(),
                    'metadata' => json_encode(['reason' => $reason], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $user->setAttribute('tokens', $lockedUser->tokens);

                return true;
            }, 3);

            if ($applied) {
                $this->broadcastBalance($user);
            }

            return $applied;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Notify the owner's browser on private user.{id} only (already auth-gated).
     */
    private function broadcastBalance(User $user): void
    {
        $userId = (int) $user->getKey();
        if ($userId <= 0) {
            return;
        }

        $balance = max(0, (int) $user->tokens);

        try {
            // Prefer after-commit so clients never see a rolled-back balance.
            if (DB::transactionLevel() > 0) {
                DB::afterCommit(function () use ($userId, $balance) {
                    event(new TokensUpdated($userId, $balance));
                });

                return;
            }

            event(new TokensUpdated($userId, $balance));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
