<?php

use App\Jobs\ProcessVehicleCheck;
use App\Services\VehicleCheckDispatchService;
use App\Support\VehicleCheckDispatchResult;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function vehicleCheckDispatchJob(int $purchaseId): ProcessVehicleCheck
{
    return new ProcessVehicleCheck(
        vinCheckId: $purchaseId + 1000,
        existingReportId: $purchaseId + 2000,
        guestReportPurchaseId: $purchaseId,
    );
}

test('1. free lock returns enqueued and pushes exactly one job', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(101);
    $service = new VehicleCheckDispatchService(Cache::store());

    $result = $service->dispatch($job);

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED)
        ->and($result->isEnqueued())->toBeTrue()
        ->and($result->isInFlight())->toBeFalse()
        ->and($result->isPushFailed())->toBeFalse()
        ->and($result->lockKey)->toBe(UniqueLock::getKey($job));

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
    Queue::assertPushed(
        ProcessVehicleCheck::class,
        fn (ProcessVehicleCheck $pushed): bool => $pushed->uniqueId() === $job->uniqueId(),
    );
});

test('2. pre-held lock returns in flight without pushing', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(102);
    $lockKey = UniqueLock::getKey($job);
    $holder = Cache::store()->lock($lockKey, 60);

    expect($holder->get())->toBeTrue();

    $result = (new VehicleCheckDispatchService(Cache::store()))->dispatch($job);

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::IN_FLIGHT)
        ->and($result->isInFlight())->toBeTrue()
        ->and($result->isEnqueued())->toBeFalse()
        ->and($result->isPushFailed())->toBeFalse();

    Queue::assertNothingPushed();

    expect($holder->isLocked())->toBeTrue()
        ->and($holder->isOwnedByCurrentProcess())->toBeTrue();
});

test('3. push exception returns push failed and releases the owned lock', function () {
    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $job): void {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('database queue unavailable');
        }
    });

    $job = vehicleCheckDispatchJob(103);
    $lockKey = UniqueLock::getKey($job);
    $service = new VehicleCheckDispatchService(Cache::store());

    $result = $service->dispatch($job);

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::PUSH_FAILED)
        ->and($result->isPushFailed())->toBeTrue()
        ->and($result->errorDescription())->toContain('database queue unavailable')
        ->and($result->errorDescription())->toContain(RuntimeException::class);

    Queue::assertNothingPushed();

    $probe = Cache::store()->lock($lockKey, 60);

    expect($probe->get())->toBeTrue();

    $probe->release();
});

test('4. a subsequent dispatch succeeds after a push failure', function () {
    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $job): void {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('transient queue failure');
        }
    });

    $job = vehicleCheckDispatchJob(104);
    $service = new VehicleCheckDispatchService(Cache::store());

    $failed = $service->dispatch($job);

    expect($failed->outcome)->toBe(VehicleCheckDispatchResult::PUSH_FAILED);

    // Replacing the fake drops the failure callback. The failed attempt also
    // released its own lock, so the next attempt must be able to claim it.
    Queue::fake();

    $result = $service->dispatch($job);

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('5. concurrent dispatch attempts produce one claim and one push', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(105);

    $first = (new VehicleCheckDispatchService(Cache::store()))->dispatch($job);
    $second = (new VehicleCheckDispatchService(Cache::store()))->dispatch($job);

    expect($first->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED)
        ->and($second->outcome)->toBe(VehicleCheckDispatchResult::IN_FLIGHT);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('6. lock key exactly matches laravel unique lock key', function () {
    $job = vehicleCheckDispatchJob(106);
    $service = new VehicleCheckDispatchService(Cache::store());

    expect($service->lockKey($job))->toBe(UniqueLock::getKey($job))
        ->and($service->lockKey($job))->toBe(
            'laravel_unique_job:'.ProcessVehicleCheck::class.':guest-report-purchase-106',
        );
});

test('7. effective ttl matches laravel resolved unique ttl', function () {
    $job = vehicleCheckDispatchJob(107);

    $laravelStore = new class extends ArrayStore
    {
        /** @var array<int, array{name: string, seconds: int}> */
        public array $lockCalls = [];

        public function lock($name, $seconds = 0, $owner = null)
        {
            $this->lockCalls[] = ['name' => $name, 'seconds' => $seconds];

            return parent::lock($name, $seconds, $owner);
        }
    };

    $laravelCache = new CacheRepository($laravelStore);

    expect((new UniqueLock($laravelCache))->acquire($job))->toBeTrue();

    $serviceStore = new class extends ArrayStore
    {
        /** @var array<int, array{name: string, seconds: int}> */
        public array $lockCalls = [];

        public function lock($name, $seconds = 0, $owner = null)
        {
            $this->lockCalls[] = ['name' => $name, 'seconds' => $seconds];

            return parent::lock($name, $seconds, $owner);
        }
    };

    $serviceCache = new CacheRepository($serviceStore);
    $service = new VehicleCheckDispatchService($serviceCache);

    expect($service->resolveTtlSeconds($job))->toBe(3600)
        ->and($service->resolveTtlSeconds($job))->toBe((int) $job->uniqueFor)
        ->and($laravelStore->lockCalls[0]['seconds'])->toBe(3600)
        ->and($serviceStore->lockCalls)->toBe([]);

    Queue::fake();

    $result = $service->dispatch($job);

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED)
        ->and($result->ttlSeconds)->toBe($laravelStore->lockCalls[0]['seconds'])
        ->and($serviceStore->lockCalls[0]['name'])->toBe($laravelStore->lockCalls[0]['name'])
        ->and($serviceStore->lockCalls[0]['seconds'])->toBe($laravelStore->lockCalls[0]['seconds']);
});

test('8. never force releases another process lock', function () {
    $job = vehicleCheckDispatchJob(108);
    $lockKey = UniqueLock::getKey($job);
    $takeover = null;

    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $pushed) use ($lockKey, &$takeover): void {
        if (! $pushed instanceof ProcessVehicleCheck) {
            return;
        }

        // Simulate the service's lock expiring and another process claiming
        // the same key while the queue push is in progress.
        $takeover = Cache::store()->lock($lockKey, 60);
        $takeover->forceRelease();
        expect($takeover->get())->toBeTrue();

        throw new RuntimeException('push failed after lock takeover');
    });

    $result = (new VehicleCheckDispatchService(Cache::store()))->dispatch($job);

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::PUSH_FAILED)
        ->and($takeover)->not->toBeNull()
        ->and($takeover->isLocked())->toBeTrue()
        ->and($takeover->isOwnedByCurrentProcess())->toBeTrue();

    Queue::assertNothingPushed();
});

test('9. queue fake does not bypass the explicit lock claim', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(109);
    $lockKey = UniqueLock::getKey($job);
    $service = new VehicleCheckDispatchService(Cache::store());

    $first = $service->dispatch($job);

    expect($first->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);

    // QueueFake records the push but runs no worker, so the explicitly claimed
    // lock must still be held. A second attempt must therefore be in_flight.
    $probe = Cache::store()->lock($lockKey, 1);

    expect($probe->get())->toBeFalse();

    $second = $service->dispatch($job);

    expect($second->outcome)->toBe(VehicleCheckDispatchResult::IN_FLIGHT);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

// ---------------------------------------------------------------------------
// Additive reservation and repush API
// ---------------------------------------------------------------------------

test('10. reservation callback runs after lock acquisition and before push', function () {
    $events = [];
    $job = vehicleCheckDispatchJob(110);
    $lockKey = UniqueLock::getKey($job);

    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $pushed) use (&$events): void {
        if ($pushed instanceof ProcessVehicleCheck) {
            $events[] = 'push';
        }
    });

    $result = (new VehicleCheckDispatchService(Cache::store()))->dispatch(
        $job,
        function () use ($lockKey, &$events): bool {
            $events[] = 'reserve';

            // The M1 lock must already be held while the callback runs.
            expect(Cache::store()->lock($lockKey, 60)->get())->toBeFalse();

            return true;
        },
    );

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED)
        ->and($events)->toBe(['reserve', 'push']);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('11. lock unavailable skips the reservation callback and performs no database writes', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(111);
    $lockKey = UniqueLock::getKey($job);
    $holder = Cache::store()->lock($lockKey, 60);

    expect($holder->get())->toBeTrue();

    $probeKey = 'm1-reservation-skip-111';
    $invoked = false;

    $result = (new VehicleCheckDispatchService(Cache::store()))->dispatch(
        $job,
        function () use ($probeKey, &$invoked): bool {
            $invoked = true;

            DB::table('cache')->insert([
                'key' => $probeKey,
                'value' => 'written',
                'expiration' => time(),
            ]);

            return true;
        },
    );

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::IN_FLIGHT)
        ->and($invoked)->toBeFalse()
        ->and(DB::table('cache')->where('key', $probeKey)->exists())->toBeFalse();

    Queue::assertNothingPushed();

    $holder->release();
});

test('12. reservation CAS loss returns in flight, rolls back, and releases the owned lock', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(112);
    $lockKey = UniqueLock::getKey($job);
    $probeKey = 'm1-reservation-cas-loss-112';
    $invocations = 0;

    $result = (new VehicleCheckDispatchService(Cache::store()))->dispatch(
        $job,
        function () use ($probeKey, &$invocations): bool {
            $invocations++;

            DB::table('cache')->insert([
                'key' => $probeKey,
                'value' => 'written',
                'expiration' => time(),
            ]);

            return false;
        },
    );

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::IN_FLIGHT)
        ->and($invocations)->toBe(1)
        ->and(DB::table('cache')->where('key', $probeKey)->exists())->toBeFalse();

    Queue::assertNothingPushed();

    $probe = Cache::store()->lock($lockKey, 60);

    expect($probe->get())->toBeTrue();

    $probe->release();
});

test('13. reservation callback exception rolls back, releases the owned lock, and rethrows', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(113);
    $lockKey = UniqueLock::getKey($job);
    $probeKey = 'm1-reservation-throw-113';

    $service = new VehicleCheckDispatchService(Cache::store());

    expect(function () use ($service, $job, $probeKey): void {
        $service->dispatch($job, function () use ($probeKey): bool {
            DB::table('cache')->insert([
                'key' => $probeKey,
                'value' => 'written',
                'expiration' => time(),
            ]);

            throw new RuntimeException('reservation failed');
        });
    })->toThrow(RuntimeException::class, 'reservation failed');

    expect(DB::table('cache')->where('key', $probeKey)->exists())->toBeFalse();

    Queue::assertNothingPushed();

    $probe = Cache::store()->lock($lockKey, 60);

    expect($probe->get())->toBeTrue();

    $probe->release();
});

test('14. queue push exception after a successful reservation releases the owned lock and returns push failed', function () {
    $probeKey = 'm1-reservation-push-fail-114';
    $invoked = false;

    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $job): void {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('database queue unavailable');
        }
    });

    $job = vehicleCheckDispatchJob(114);
    $lockKey = UniqueLock::getKey($job);

    $result = (new VehicleCheckDispatchService(Cache::store()))->dispatch(
        $job,
        function () use ($probeKey, &$invoked): bool {
            $invoked = true;

            DB::table('cache')->insert([
                'key' => $probeKey,
                'value' => 'reserved',
                'expiration' => time(),
            ]);

            return true;
        },
    );

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::PUSH_FAILED)
        ->and($result->isPushFailed())->toBeTrue()
        ->and($invoked)->toBeTrue()
        ->and(DB::table('cache')->where('key', $probeKey)->exists())->toBeTrue();

    Queue::assertNothingPushed();

    $probe = Cache::store()->lock($lockKey, 60);

    expect($probe->get())->toBeTrue();

    $probe->release();
});

test('15. repush callback runs after lock acquisition and before push', function () {
    $events = [];
    $job = vehicleCheckDispatchJob(115);
    $lockKey = UniqueLock::getKey($job);

    $fake = Queue::fake();
    $fake->beforePushing(function (mixed $pushed) use (&$events): void {
        if ($pushed instanceof ProcessVehicleCheck) {
            $events[] = 'push';
        }
    });

    $result = (new VehicleCheckDispatchService(Cache::store()))->repush(
        $job,
        function () use ($lockKey, &$events): bool {
            $events[] = 'slot';

            // The M1 lock must already be held while the callback runs.
            expect(Cache::store()->lock($lockKey, 60)->get())->toBeFalse();

            return true;
        },
    );

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED)
        ->and($events)->toBe(['slot', 'push']);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('16. repush callback rejection prevents push and releases the owned lock', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(116);
    $lockKey = UniqueLock::getKey($job);
    $probeKey = 'm1-repush-rejected-116';
    $invocations = 0;

    $result = (new VehicleCheckDispatchService(Cache::store()))->repush(
        $job,
        function () use ($probeKey, &$invocations): bool {
            $invocations++;

            DB::table('cache')->insert([
                'key' => $probeKey,
                'value' => 'slot',
                'expiration' => time(),
            ]);

            return false;
        },
    );

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::IN_FLIGHT)
        ->and($invocations)->toBe(1)
        ->and(DB::table('cache')->where('key', $probeKey)->exists())->toBeFalse();

    Queue::assertNothingPushed();

    $probe = Cache::store()->lock($lockKey, 60);

    expect($probe->get())->toBeTrue();

    $probe->release();
});

test('17. repush callback exception rolls back, releases the owned lock, and rethrows', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(117);
    $lockKey = UniqueLock::getKey($job);
    $probeKey = 'm1-repush-throw-117';

    $service = new VehicleCheckDispatchService(Cache::store());

    expect(function () use ($service, $job, $probeKey): void {
        $service->repush($job, function () use ($probeKey): bool {
            DB::table('cache')->insert([
                'key' => $probeKey,
                'value' => 'slot',
                'expiration' => time(),
            ]);

            throw new RuntimeException('slot allocation failed');
        });
    })->toThrow(RuntimeException::class, 'slot allocation failed');

    expect(DB::table('cache')->where('key', $probeKey)->exists())->toBeFalse();

    Queue::assertNothingPushed();

    $probe = Cache::store()->lock($lockKey, 60);

    expect($probe->get())->toBeTrue();

    $probe->release();
});

test('18. existing dispatchVehicleCheck callers without callbacks retain enqueued behaviour', function () {
    Queue::fake();

    $result = (new VehicleCheckDispatchService(Cache::store()))
        ->dispatchVehicleCheck(118, 2118, 1118);

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED)
        ->and($result->isEnqueued())->toBeTrue();

    Queue::assertPushed(
        ProcessVehicleCheck::class,
        fn (ProcessVehicleCheck $pushed): bool => $pushed->uniqueId() === 'guest-report-purchase-1118',
    );
});

test('19. repush without a callback pushes and retains the lock', function () {
    Queue::fake();

    $job = vehicleCheckDispatchJob(119);
    $lockKey = UniqueLock::getKey($job);

    $result = (new VehicleCheckDispatchService(Cache::store()))->repush($job);

    expect($result->outcome)->toBe(VehicleCheckDispatchResult::ENQUEUED)
        ->and($result->lockKey)->toBe($lockKey);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);

    $probe = Cache::store()->lock($lockKey, 1);

    expect($probe->get())->toBeFalse();
});
