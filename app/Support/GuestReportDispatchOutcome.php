<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Outcome of a guest-report fulfillment reservation attempt.
 *
 * The four states are deliberately explicit:
 *
 * - enqueued:     the reservation CAS committed and M1 pushed the job.
 * - in_flight:    M1's lock was already held, or the reservation CAS lost.
 * - push_failed:  the reservation committed but M1's queue push threw.
 * - sync_skipped: the sync queue driver was detected, so nothing was reserved
 *                 or pushed.
 *
 * push_failed does not prove the job was never delivered: a queue push can fail
 * after the payload reached the store.
 */
final class GuestReportDispatchOutcome
{
    public const ENQUEUED = 'enqueued';

    public const IN_FLIGHT = 'in_flight';

    public const PUSH_FAILED = 'push_failed';

    public const SYNC_SKIPPED = 'sync_skipped';

    private function __construct(
        public readonly string $outcome,
        public readonly ?string $dispatchUuid = null,
        public readonly ?string $error = null,
    ) {
        if (! in_array($outcome, [
            self::ENQUEUED,
            self::IN_FLIGHT,
            self::PUSH_FAILED,
            self::SYNC_SKIPPED,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported guest report dispatch outcome [{$outcome}]."
            );
        }
    }

    public static function enqueued(string $dispatchUuid): self
    {
        return new self(self::ENQUEUED, $dispatchUuid);
    }

    public static function inFlight(): self
    {
        return new self(self::IN_FLIGHT);
    }

    public static function pushFailed(?string $dispatchUuid, ?string $error): self
    {
        return new self(self::PUSH_FAILED, $dispatchUuid, $error);
    }

    public static function syncSkipped(): self
    {
        return new self(self::SYNC_SKIPPED);
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

    public function isSyncSkipped(): bool
    {
        return $this->outcome === self::SYNC_SKIPPED;
    }

    /**
     * @return array{
     *     outcome: string,
     *     dispatch_uuid: string|null,
     *     error: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'dispatch_uuid' => $this->dispatchUuid,
            'error' => $this->error,
        ];
    }
}
