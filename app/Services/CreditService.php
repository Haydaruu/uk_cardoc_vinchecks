<?php

namespace App\Services;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreditService
{
    public function __construct(
        protected ?AdminAuditService $auditService = null,
    ) {
        $this->auditService = $this->auditService ?? app(AdminAuditService::class);
    }

    /**
     * Add credits to a user's balance.
     */
    public function grantCredits(
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

    /**
     * Adjust credits for a user by an admin with strict idempotency and audit trail.
     *
     * @throws ValidationException
     */
    public function adjustCredits(
        User $admin,
        User $customer,
        int $delta,
        string $reason,
        string $idempotencyKey,
        ?Request $request = null,
    ): CreditTransaction {
        if ($delta === 0) {
            throw ValidationException::withMessages([
                'delta' => ['Adjustment amount cannot be zero.'],
            ]);
        }

        // Fast-path: pre-transaction idempotency check
        $existing = CreditTransaction::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $this->resolveExistingAdjustment($existing, $customer, $delta, $reason);
        }

        try {
            return DB::transaction(function () use (
                $admin,
                $customer,
                $delta,
                $reason,
                $idempotencyKey,
                $request,
            ) {
                // Secondary idempotency check within transaction using lock
                $lockedExisting = CreditTransaction::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($lockedExisting) {
                    return $this->resolveExistingAdjustment($lockedExisting, $customer, $delta, $reason);
                }

                // Pessimistic lock on customer record to prevent race conditions
                $lockedCustomer = User::query()
                    ->whereKey($customer->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $balanceBefore = (int) $lockedCustomer->credits;
                $newBalance = $balanceBefore + $delta;

                if ($newBalance < 0) {
                    throw ValidationException::withMessages([
                        'delta' => ["Insufficient credit balance. Current balance is {$balanceBefore}, requested deduction is ".abs($delta).'.'],
                    ]);
                }

                // Update customer balance
                $lockedCustomer->update([
                    'credits' => $newBalance,
                ]);

                // Record credit ledger transaction
                $creditTx = CreditTransaction::create([
                    'user_id' => $lockedCustomer->id,
                    'type' => 'admin_adjustment',
                    'amount' => $delta,
                    'balance_after' => $newBalance,
                    'idempotency_key' => $idempotencyKey,
                    'description' => $reason,
                ]);

                // Atomic audit logging within the same database transaction
                $this->auditService->log(
                    admin: $admin,
                    action: 'credits.adjusted',
                    targetType: 'customer',
                    targetId: $lockedCustomer->id,
                    reason: $reason,
                    outcome: 'success',
                    metadata: [
                        'delta' => $delta,
                        'balance_before' => $balanceBefore,
                        'balance_after' => $newBalance,
                        'idempotency_key' => $idempotencyKey,
                        'credit_transaction_id' => $creditTx->id,
                    ],
                    request: $request,
                );

                return $creditTx;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent race won by another request with the same idempotency key
            $raceWinner = CreditTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($raceWinner) {
                return $this->resolveExistingAdjustment($raceWinner, $customer, $delta, $reason);
            }

            throw $e;
        }
    }

    /**
     * Verify whether an existing transaction matches the request payload.
     *
     * @throws HttpException
     */
    protected function resolveExistingAdjustment(
        CreditTransaction $existing,
        User $customer,
        int $delta,
        string $reason,
    ): CreditTransaction {
        $isSameUser = (int) $existing->user_id === (int) $customer->id;
        $isSameDelta = (int) $existing->amount === (int) $delta;
        $isSameReason = (string) $existing->description === (string) $reason;
        $isAdjustmentType = $existing->type === 'admin_adjustment';

        if ($isSameUser && $isSameDelta && $isSameReason && $isAdjustmentType) {
            return $existing;
        }

        abort(409, 'Idempotency key has already been used with different parameters.');
    }
}
