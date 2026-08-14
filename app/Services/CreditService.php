<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CreditService

{
    /**
     * Add credits to a user's balance.
     */
    public function grant(
        User $user,
        int $amount,
        string $type,
        ?string $referenceId = null,
        ?string $idempotencyKey = null,
        ?string $description = null,
    ): CreditTransaction {
        if ($amount <= 0) {
            throw new RuntimeException('Credit amount must be greater than zero.');
        }

        return DB::transaction(function () use (
            $user,
            $amount,
            $type,
            $referenceId,
            $idempotencyKey,
            $description,
        ) {
            // Idempotency check:
            // the same payment/event must never grant credits twice.
            if ($idempotencyKey) {
                $existingTransaction = CreditTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingTransaction) {
                    return $existingTransaction;
                }
            }

            // Lock the user's row to prevent race conditions.
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $newBalance = $lockedUser->credits + $amount;

            $lockedUser->update([
                'credits' => $newBalance,
            ]);

            return CreditTransaction::create([
                'user_id' => $lockedUser->id,
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_id' => $referenceId,
                'idempotency_key' => $idempotencyKey,
                'description' => $description,
            ]);
        });
    }

    /**
     * Consume one credit from a user's balance.
     */
    public function consume(
        User $user,
        ?string $referenceId = null,
        ?string $description = null,
    ): CreditTransaction {
        return DB::transaction(function () use (
            $user,
            $referenceId,
            $description,
        ) {
            // Lock the user's row before checking the balance.
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUser->credits <= 0) {
                throw new RuntimeException('Insufficient credits.');
            }

            $newBalance = $lockedUser->credits - 1;

            $lockedUser->update([
                'credits' => $newBalance,
            ]);

            return CreditTransaction::create([
                'user_id' => $lockedUser->id,
                'type' => 'usage',
                'amount' => -1,
                'balance_after' => $newBalance,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);
        });
    }
}