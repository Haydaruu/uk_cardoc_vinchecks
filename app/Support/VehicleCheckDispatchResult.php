<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Outcome of an explicit vehicle-check dispatch attempt.
 *
 * The outcome is intentionally limited to three states:
 *
 * - enqueued:    the unique lock was claimed and the queue push completed.
 * - in_flight:   the unique lock is already held, so nothing was pushed.
 * - push_failed: the unique lock was claimed but the queue push threw.
 *
 * A claimed lock is deliberately left in place on success. Laravel's queue
 * worker releases the lock when the unique job completes or reaches a terminal
 * failure. A failed push releases only the lock instance owned by this attempt.
 */
final class VehicleCheckDispatchResult
{
    public const ENQUEUED = 'enqueued';

    public const IN_FLIGHT = 'in_flight';

    public const PUSH_FAILED = 'push_failed';

    private function __construct(
        public readonly string $outcome,
        public readonly string $lockKey,
        public readonly int $ttlSeconds,
        public readonly ?string $error = null,
    ) {
        if (! in_array($outcome, [
            self::ENQUEUED,
            self::IN_FLIGHT,
            self::PUSH_FAILED,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported vehicle check dispatch outcome [{$outcome}]."
            );
        }
    }

    public static function enqueued(string $lockKey, int $ttlSeconds): self
    {
        return new self(self::ENQUEUED, $lockKey, $ttlSeconds);
    }

    public static function inFlight(string $lockKey, int $ttlSeconds): self
    {
        return new self(self::IN_FLIGHT, $lockKey, $ttlSeconds);
    }

    public static function pushFailed(
        string $lockKey,
        int $ttlSeconds,
        string $error,
    ): self {
        return new self(self::PUSH_FAILED, $lockKey, $ttlSeconds, $error);
    }

    public function isEnqueued(): bool
    {
        return $this->outcome === self::ENQUEUED;
    }

    public function isInFlight(): bool
    {
        return $this->outcome === self::IN_FLIGHT;
    }

    public function isPushFailed(): bool
    {
        return $this->outcome === self::PUSH_FAILED;
    }

    public function errorDescription(): ?string
    {
        return $this->error;
    }

    /**
     * @return array{
     *     outcome: string,
     *     lock_key: string,
     *     ttl_seconds: int,
     *     error: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'lock_key' => $this->lockKey,
            'ttl_seconds' => $this->ttlSeconds,
            'error' => $this->error,
        ];
    }
}
