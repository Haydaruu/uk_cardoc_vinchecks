<?php

namespace App\Services;

use App\Jobs\ProcessVehicleCheck;
use App\Support\VehicleCheckDispatchResult;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Queue\Attributes\ReadsQueueAttributes;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Explicitly claims Laravel's unique-job lock before pushing ProcessVehicleCheck.
 *
 * Laravel's normal ProcessVehicleCheck::dispatch() path uses PendingDispatch,
 * which silently suppresses the push when the unique lock cannot be acquired.
 * This service makes that decision observable:
 *
 * - it claims the same lock key and effective TTL as Illuminate\Bus\UniqueLock;
 * - it pushes through Bus::dispatch() only after a successful claim;
 * - it reports in_flight when the lock is already held;
 * - it releases only its own lock instance if the push throws.
 *
 * It never force-releases a lock owned by another process and never uses queue
 * inspection as proof that a job is alive.
 *
 * Both dispatch() and repush() accept an optional callback. The callback runs
 * only after the lock is acquired, inside a transaction owned by this service,
 * and before the queue push. Returning false is a CAS/slot rejection: the
 * transaction is rolled back, the owned lock is released, and in_flight is
 * returned. Throwing rolls the transaction back, releases the owned lock, and
 * rethrows.
 */
class VehicleCheckDispatchService
{
    use ReadsQueueAttributes;

    public function __construct(
        protected CacheRepository $cache,
    ) {}

    /**
     * Dispatch a job after optionally committing a reservation.
     *
     * The optional reservation callback runs only after this service has
     * acquired the unique-job lock. It is executed inside a database
     * transaction owned by this service and must return exactly true to
     * permit the queue push.
     *
     * - true:   the reservation is committed and the job is pushed.
     * - false:  the reservation is rolled back, the owned lock is released,
     *           and in_flight is returned. No push occurs.
     * - throws: the reservation is rolled back, the owned lock is released,
     *           and the exception is rethrown.
     */
    public function dispatch(
        ProcessVehicleCheck $job,
        ?callable $reserve = null,
    ): VehicleCheckDispatchResult {
        $lockKey = UniqueLock::getKey($job);
        $ttlSeconds = $this->resolveTtlSeconds($job);

        $lock = $this->lockFor($this->resolveCache($job), $lockKey, $ttlSeconds);

        if (! $lock->get()) {
            return VehicleCheckDispatchResult::inFlight($lockKey, $ttlSeconds);
        }

        if ($reserve !== null) {
            try {
                if (! $this->runInTransaction($reserve)) {
                    $lock->release();

                    return VehicleCheckDispatchResult::inFlight($lockKey, $ttlSeconds);
                }
            } catch (Throwable $e) {
                $lock->release();

                throw $e;
            }
        }

        return $this->push($job, $lock, $lockKey, $ttlSeconds);
    }

    /**
     * Push the same job generation again after optionally reserving a push slot.
     *
     * The optional callback runs only after this service has acquired the
     * unique-job lock and before the queue push. It is executed inside a
     * database transaction owned by this service and must return exactly true
     * to permit the push.
     *
     * - true:   the callback transaction is committed and the job is pushed.
     * - false:  the callback transaction is rolled back, the owned lock is
     *           released, and in_flight is returned. No push occurs.
     * - throws: the callback transaction is rolled back, the owned lock is
     *           released, and the exception is rethrown.
     *
     * A false result is reported as in_flight because the existing
     * VehicleCheckDispatchResult type has no dedicated skipped outcome.
     * Callers that need to distinguish slot exhaustion from lock contention
     * must track their own callback result.
     */
    public function repush(
        ProcessVehicleCheck $job,
        ?callable $beforePush = null,
    ): VehicleCheckDispatchResult {
        $lockKey = UniqueLock::getKey($job);
        $ttlSeconds = $this->resolveTtlSeconds($job);

        $lock = $this->lockFor($this->resolveCache($job), $lockKey, $ttlSeconds);

        if (! $lock->get()) {
            return VehicleCheckDispatchResult::inFlight($lockKey, $ttlSeconds);
        }

        if ($beforePush !== null) {
            try {
                if (! $this->runInTransaction($beforePush)) {
                    $lock->release();

                    return VehicleCheckDispatchResult::inFlight($lockKey, $ttlSeconds);
                }
            } catch (Throwable $e) {
                $lock->release();

                throw $e;
            }
        }

        return $this->push($job, $lock, $lockKey, $ttlSeconds);
    }

    protected function push(
        ProcessVehicleCheck $job,
        Lock $lock,
        string $lockKey,
        int $ttlSeconds,
    ): VehicleCheckDispatchResult {
        try {
            Bus::dispatch($job);
        } catch (Throwable $e) {
            // Owner-checked release only. Never call forceRelease() here: the
            // lock may have expired and been claimed by another process while
            // the push was in progress.
            $lock->release();

            return VehicleCheckDispatchResult::pushFailed(
                $lockKey,
                $ttlSeconds,
                $this->safeErrorDescription($e),
            );
        }

        // The lock is intentionally retained. Laravel's worker releases it
        // when the unique job completes or reaches a terminal failure.
        return VehicleCheckDispatchResult::enqueued($lockKey, $ttlSeconds);
    }

    /**
     * Run a reservation callback in a transaction owned by this service.
     *
     * A callback that does not return exactly true is treated as a CAS loss:
     * every write performed inside the callback is rolled back.
     */
    protected function runInTransaction(callable $callback): bool
    {
        $level = DB::transactionLevel();

        DB::beginTransaction();

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->rollBackTo($level);

            throw $e;
        }

        if ($result !== true) {
            $this->rollBackTo($level);

            return false;
        }

        try {
            DB::commit();
        } catch (Throwable $e) {
            $this->rollBackTo($level);

            throw $e;
        }

        return true;
    }

    /**
     * Roll back every transaction level opened by this service.
     */
    protected function rollBackTo(int $level): void
    {
        while (DB::transactionLevel() > $level) {
            DB::rollBack();
        }
    }

    public function dispatchVehicleCheck(
        int $vinCheckId,
        ?int $existingReportId = null,
        ?int $guestReportPurchaseId = null,
    ): VehicleCheckDispatchResult {
        return $this->dispatch(new ProcessVehicleCheck(
            $vinCheckId,
            $existingReportId,
            $guestReportPurchaseId,
        ));
    }

    public function lockKey(ProcessVehicleCheck $job): string
    {
        return UniqueLock::getKey($job);
    }

    public function resolveTtlSeconds(ProcessVehicleCheck $job): int
    {
        // Mirror Illuminate\Bus\UniqueLock::acquire() exactly: an explicit
        // uniqueFor() method wins, otherwise the #[UniqueFor] attribute or the
        // public $uniqueFor property is resolved through ReadsQueueAttributes.
        if (method_exists($job, 'uniqueFor')) {
            return (int) $job->uniqueFor();
        }

        return (int) ($this->getAttributeValue(
            $job,
            UniqueFor::class,
            'uniqueFor',
        ) ?? 0);
    }

    protected function resolveCache(ProcessVehicleCheck $job): CacheRepository
    {
        if (method_exists($job, 'uniqueVia')) {
            return $job->uniqueVia() ?? $this->cache;
        }

        return $this->cache;
    }

    /**
     * Resolve the same lock provider that Repository::lock() would forward to.
     */
    protected function lockFor(
        CacheRepository $cache,
        string $lockKey,
        int $ttlSeconds,
    ): Lock {
        $store = $cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException(sprintf(
                'Cache store [%s] does not support atomic locks.',
                $store::class,
            ));
        }

        return $store->lock($lockKey, $ttlSeconds);
    }

    /**
     * Produce a bounded, single-line description suitable for recovery logs.
     */
    protected function safeErrorDescription(Throwable $e): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $e->getMessage()));

        if ($message === '') {
            return $e::class;
        }

        return Str::limit($e::class.': '.$message, 500);
    }
}
