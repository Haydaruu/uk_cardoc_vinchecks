<?php

use App\Models\AdminActivityLog;
use App\Models\CreditTransaction;
use App\Models\ProcessedWebhookEvent;
use App\Models\RefundRecord;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VinCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ConcurrencyBarrier;

/*
|--------------------------------------------------------------------------
| Phase 3.5.3: PostgreSQL Multi-Process Concurrency & Blocking Verification
|--------------------------------------------------------------------------
|
| These tests execute against real PostgreSQL (ukcardoc_concurrency_test)
| using independent PHP processes and separate PostgreSQL connections.
|
| They verify:
| 1. True unique constraint collision handling and savepoint rollback via pg_blocking_pids observation.
| 2. Nested transaction / SAVEPOINT recovery preserving outer transaction commit.
| 3. Atomic conditional UPDATE serialization during stale ownership reclaim via lock observation.
| 4. SELECT ... FOR UPDATE row-level lock blocking during refund settlement, terminal guards,
|    and exact-once credit restoration.
|
*/

function getPostgreSqlWorkerEnv(): array
{
    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => config('database.connections.pgsql.host', '127.0.0.1'),
        'DB_PORT' => (string) config('database.connections.pgsql.port', '5432'),
        'DB_DATABASE' => 'ukcardoc_concurrency_test',
        'DB_USERNAME' => config('database.connections.pgsql.username', 'postgres'),
        'DB_PASSWORD' => config('database.connections.pgsql.password', ''),
    ];
}

function runConcurrencyWorker(array $config, float $timeout = 20.0): array
{
    $configFile = storage_path('framework/tmp/cfg_'.Str::random(10).'.json');
    file_put_contents($configFile, json_encode($config, JSON_THROW_ON_ERROR));

    $process = new Process(
        [PHP_BINARY, base_path('tests/Support/PostgreSqlConcurrencyWorker.php'), $configFile],
        base_path(),
        getPostgreSqlWorkerEnv()
    );

    $process->setTimeout($timeout);
    $process->start();

    return [
        'process' => $process,
        'config_file' => $configFile,
    ];
}

function collectWorkerResult(array $workerHandle): array
{
    /** @var Process $process */
    $process = $workerHandle['process'];
    $configFile = $workerHandle['config_file'];

    $process->wait();

    if (file_exists($configFile)) {
        @unlink($configFile);
    }

    $stdout = trim($process->getOutput());
    $stderr = trim($process->getErrorOutput());

    if (! $process->isSuccessful()) {
        throw new RuntimeException("Worker failed with exit code {$process->getExitCode()}.\nSTDOUT: {$stdout}\nSTDERR: {$stderr}");
    }

    $decoded = json_decode($stdout, true);
    if (! is_array($decoded)) {
        throw new RuntimeException("Worker returned invalid JSON.\nSTDOUT: {$stdout}\nSTDERR: {$stderr}");
    }

    return $decoded;
}

function waitForBlocking(int $blockedBackendPid, int $blockingBackendPid, float $timeoutSeconds = 8.0): array
{
    $start = microtime(true);
    while ((microtime(true) - $start) < $timeoutSeconds) {
        $row = DB::selectOne(
            'SELECT pid, wait_event_type, wait_event, pg_blocking_pids(pid)::text as blocking_pids
             FROM pg_stat_activity
             WHERE pid = ?',
            [$blockedBackendPid]
        );

        if ($row && $row->wait_event_type === 'Lock') {
            if (str_contains($row->blocking_pids, (string) $blockingBackendPid)) {
                return (array) $row;
            }
        }
        usleep(5000); // 5ms polling
    }

    throw new RuntimeException("Timeout waiting for PostgreSQL backend PID {$blockedBackendPid} to be blocked by {$blockingBackendPid}");
}

function cleanupWorkers(array $workerHandles, ?ConcurrencyBarrier $barrier = null): void
{
    foreach ($workerHandles as $handle) {
        if (isset($handle['process']) && $handle['process'] instanceof Process && $handle['process']->isRunning()) {
            $handle['process']->stop(1.0);
        }
        if (isset($handle['config_file']) && file_exists($handle['config_file'])) {
            @unlink($handle['config_file']);
        }
    }
    $barrier?->cleanup();
}

beforeEach(function () {
    if (config('database.default') !== 'pgsql' || DB::connection()->getDatabaseName() !== 'ukcardoc_concurrency_test') {
        $this->markTestSkipped('Phase 3.5.3 tests require PostgreSQL and ukcardoc_concurrency_test database.');
    }
});

test('A. concurrent initial webhook insert: proves actual postgresql lock blocking via pg_blocking_pids, savepoint recovery, and single callback execution', function () {
    $eventId = 'evt_pg_block_ins_'.Str::random(12);
    $provider = 'stripe';
    $barrierDir = storage_path('framework/tmp/barrier_'.Str::random(8));
    $barrier = new ConcurrencyBarrier($barrierDir);
    $runningWorkers = [];

    try {
        $w1 = runConcurrencyWorker([
            'action' => 'webhook-insert',
            'role' => 'worker_1',
            'provider' => $provider,
            'event_id' => $eventId,
            'hold_with_barrier' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w1;

        $w2 = runConcurrencyWorker([
            'action' => 'webhook-insert',
            'role' => 'worker_2',
            'provider' => $provider,
            'event_id' => $eventId,
            'wait_for_insert' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w2;

        $barrier->wait('worker_1_ready');
        $barrier->wait('worker_2_ready');

        $w1Meta = $barrier->getMeta('worker_1_meta');
        $w2Meta = $barrier->getMeta('worker_2_meta');

        expect($w1Meta)->not->toBeNull()
            ->and($w2Meta)->not->toBeNull()
            ->and($w1Meta['pg_backend_pid'])->not->toBe($w2Meta['pg_backend_pid']);

        // Signal both to start
        $barrier->mark('start_signal');

        // Worker 1 inserts and pauses at barrier; Worker 2 arrives and attempts insert
        $barrier->wait('w1_inserted');

        // OBSERVE ACTUAL POSTGRESQL BLOCKING: Worker 2 must be blocked by Worker 1 in pg_stat_activity
        $blockInfo = waitForBlocking($w2Meta['pg_backend_pid'], $w1Meta['pg_backend_pid']);

        expect($blockInfo['wait_event_type'])->toBe('Lock')
            ->and($blockInfo['blocking_pids'])->toContain((string) $w1Meta['pg_backend_pid']);

        // Release Worker 1 to commit
        $barrier->mark('w1_allow_commit');

        $res1 = collectWorkerResult($w1);
        $res2 = collectWorkerResult($w2);

        // Assertions
        expect($res1['success'])->toBeTrue()
            ->and($res1['callback_called'])->toBeTrue()
            ->and($res1['result']['status'])->toBe('completed');

        expect($res2['success'])->toBeTrue()
            ->and($res2['callback_called'])->toBeFalse()
            ->and($res2['insert_attempted'])->toBeTrue()
            ->and($res2['insert_succeeded'])->toBeFalse()
            ->and($res2['result']['status'])->toBeIn(['in_flight', 'already_completed']);

        // Database row verification
        $events = ProcessedWebhookEvent::where('provider', $provider)->where('event_id', $eventId)->get();
        expect($events)->toHaveCount(1)
            ->and($events->first()->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);

    } finally {
        cleanupWorkers($runningWorkers, $barrier);
        ProcessedWebhookEvent::where('provider', $provider)->where('event_id', $eventId)->delete();
    }
});

test('B. nested transaction / SAVEPOINT: collision does not abort outer transaction and subsequent queries commit successfully', function () {
    $eventId = 'evt_pg_savepoint_'.Str::random(12);
    $provider = 'stripe';
    $markerProvider = 'sp_marker_'.Str::random(10);
    $barrierDir = storage_path('framework/tmp/barrier_'.Str::random(8));
    $barrier = new ConcurrencyBarrier($barrierDir);
    $runningWorkers = [];
    $markerId = null;

    try {
        $w1 = runConcurrencyWorker([
            'action' => 'webhook-insert',
            'role' => 'worker_1',
            'provider' => $provider,
            'event_id' => $eventId,
            'hold_with_barrier' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w1;

        // Worker 2 calls executeWithDeduplication INSIDE an outer DB::transaction, then saves a
        // ProcessedWebhookEvent marker record to prove outer transaction survived the SAVEPOINT rollback.
        $w2 = runConcurrencyWorker([
            'action' => 'webhook-insert',
            'role' => 'worker_2',
            'provider' => $provider,
            'event_id' => $eventId,
            'wait_for_insert' => true,
            'in_outer_transaction' => true,
            'marker_provider' => $markerProvider,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w2;

        $barrier->wait('worker_1_ready');
        $barrier->wait('worker_2_ready');

        $w1Meta = $barrier->getMeta('worker_1_meta');
        $w2Meta = $barrier->getMeta('worker_2_meta');

        $barrier->mark('start_signal');
        $barrier->wait('w1_inserted');

        // Observe blocking in pg_stat_activity
        $blockInfo = waitForBlocking($w2Meta['pg_backend_pid'], $w1Meta['pg_backend_pid']);
        expect($blockInfo['wait_event_type'])->toBe('Lock');

        // Release Worker 1
        $barrier->mark('w1_allow_commit');

        $res1 = collectWorkerResult($w1);
        $res2 = collectWorkerResult($w2);

        expect($res1['success'])->toBeTrue()
            ->and($res2['success'])->toBeTrue()
            ->and($res2['result']['status'])->toBeIn(['in_flight', 'already_completed']);

        // Crucial SAVEPOINT proof: Worker 2 successfully saved and committed its marker record
        // in the outer transaction AFTER the inner SAVEPOINT was rolled back due to collision.
        $markerId = $res2['marker_id'];
        expect($markerId)->not->toBeNull();

        $savedMarker = ProcessedWebhookEvent::where('provider', $markerProvider)->find($markerId);
        expect($savedMarker)->not->toBeNull()
            ->and($savedMarker->event_type)->toBe('savepoint.marker')
            ->and($savedMarker->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);

    } finally {
        cleanupWorkers($runningWorkers, $barrier);
        ProcessedWebhookEvent::where('provider', $provider)->where('event_id', $eventId)->delete();
        ProcessedWebhookEvent::where('provider', $markerProvider)->delete();
    }
});

test('C. concurrent stale ownership reclaim: proves row lock blocking via pg_blocking_pids, and only winner executes callback', function () {
    $eventId = 'evt_pg_stale_block_'.Str::random(12);
    $provider = 'stripe';
    $initialToken = (string) Str::uuid();
    $barrierDir = storage_path('framework/tmp/barrier_'.Str::random(8));
    $barrier = new ConcurrencyBarrier($barrierDir);
    $runningWorkers = [];

    // Seed stale event directly and commit
    $seeded = ProcessedWebhookEvent::create([
        'provider' => $provider,
        'event_id' => $eventId,
        'event_type' => 'charge.refunded',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => $initialToken,
    ]);
    ProcessedWebhookEvent::where('id', $seeded->id)->update([
        'updated_at' => now()->subMinutes(10),
        'created_at' => now()->subMinutes(10),
    ]);
    DB::commit();

    try {
        $wa = runConcurrencyWorker([
            'action' => 'webhook-stale-reclaim',
            'role' => 'worker_a',
            'provider' => $provider,
            'event_id' => $eventId,
            'lease_seconds' => 120,
            'hold_with_barrier' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $wa;

        $wb = runConcurrencyWorker([
            'action' => 'webhook-stale-reclaim',
            'role' => 'worker_b',
            'provider' => $provider,
            'event_id' => $eventId,
            'lease_seconds' => 120,
            'wait_for_reclaim' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $wb;

        $barrier->wait('worker_a_ready');
        $barrier->wait('worker_b_ready');

        $waMeta = $barrier->getMeta('worker_a_meta');
        $wbMeta = $barrier->getMeta('worker_b_meta');

        $barrier->mark('reclaim_start_signal');

        // Worker A performs conditional UPDATE inside transaction, marks worker_a_reclaimed
        $barrier->wait('worker_a_reclaimed');

        // Worker B attempts conditional UPDATE on the same row, getting blocked by Worker A's row lock
        $blockInfo = waitForBlocking($wbMeta['pg_backend_pid'], $waMeta['pg_backend_pid']);
        expect($blockInfo['wait_event_type'])->toBe('Lock')
            ->and($blockInfo['blocking_pids'])->toContain((string) $waMeta['pg_backend_pid']);

        // Release Worker A to complete callback and commit
        $barrier->mark('worker_a_allow_commit');

        $resA = collectWorkerResult($wa);
        $resB = collectWorkerResult($wb);

        expect($resA['callback_called'])->toBeTrue()
            ->and($resB['callback_called'])->toBeFalse()
            ->and($resA['result']['status'])->toBe('completed')
            ->and($resB['result']['status'])->toBeIn(['in_flight', 'already_completed']);

        $event = ProcessedWebhookEvent::where('provider', $provider)->where('event_id', $eventId)->first();
        expect($event->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED)
            ->and($event->ownership_token)->not->toBe($initialToken);

    } finally {
        cleanupWorkers($runningWorkers, $barrier);
        ProcessedWebhookEvent::where('provider', $provider)->where('event_id', $eventId)->delete();
    }
});

test('D1. refund row-lock settlement: proves SELECT FOR UPDATE blocking via pg_blocking_pids, and terminal success guard', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user = User::factory()->create(['role' => 'user', 'credits' => 5]);
    $tx = Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'UKC-TEST-'.Str::random(6),
        'currency' => 'GBP',
        'amount' => 19.99,
        'payment_method' => 'stripe',
        'type' => 'payment',
        'category' => 'credit_purchase',
        'status' => 'success',
        'payment_gateway_ref' => 'pi_test_'.Str::random(10),
    ]);
    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $admin->id,
        'gateway' => 'stripe',
        'payment_reference' => $tx->payment_gateway_ref,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Test lock blocking',
        'metadata' => ['credits_to_deduct' => 5],
    ]);
    DB::commit();

    $barrierDir = storage_path('framework/tmp/barrier_'.Str::random(8));
    $barrier = new ConcurrencyBarrier($barrierDir);
    $runningWorkers = [];

    try {
        $w1 = runConcurrencyWorker([
            'action' => 'refund-settle',
            'role' => 'worker_1',
            'record_id' => $record->id,
            'method' => 'settleSuccess',
            'provider_refund_id' => 're_win_001',
            'hold_with_barrier' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w1;

        $w2 = runConcurrencyWorker([
            'action' => 'refund-settle',
            'role' => 'worker_2',
            'record_id' => $record->id,
            'method' => 'settleSuccess',
            'provider_refund_id' => 're_lose_002',
            'wait_for_lock' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w2;

        $barrier->wait('worker_1_ready');
        $barrier->wait('worker_2_ready');

        $w1Meta = $barrier->getMeta('worker_1_meta');
        $w2Meta = $barrier->getMeta('worker_2_meta');

        $barrier->mark('settle_start_signal');

        // Worker 1 acquires lock and pauses at barrier
        $barrier->wait('worker_1_locked');

        // OBSERVE ACTUAL POSTGRESQL ROW LOCK BLOCKING on Worker 2
        $blockInfo = waitForBlocking($w2Meta['pg_backend_pid'], $w1Meta['pg_backend_pid']);
        expect($blockInfo['wait_event_type'])->toBe('Lock')
            ->and($blockInfo['blocking_pids'])->toContain((string) $w1Meta['pg_backend_pid']);

        // Release Worker 1 to commit
        $barrier->mark('worker_1_allow_commit');

        $res1 = collectWorkerResult($w1);
        $res2 = collectWorkerResult($w2);

        expect($res1['result']['status'])->toBe('settled')
            ->and($res2['result']['status'])->toBe('already_success');

        // Exact assertion on AdminActivityLog: exactly 1 log record was created
        $auditLogs = AdminActivityLog::where('target_id', $tx->id)->get();
        expect($auditLogs)->toHaveCount(1)
            ->and($auditLogs->first()->outcome)->toBe('success');

        $freshRecord = RefundRecord::findOrFail($record->id);
        expect($freshRecord->status)->toBe(RefundRecord::STATUS_SUCCESS)
            ->and($freshRecord->refund_gateway_ref)->toBe('re_win_001');

    } finally {
        cleanupWorkers($runningWorkers, $barrier);
        RefundRecord::where('id', $record->id)->delete();
        Transaction::where('id', $tx->id)->delete();
        AdminActivityLog::where('target_id', $tx->id)->delete();
        User::whereIn('id', [$admin->id, $user->id])->delete();
    }
});

test('D2. refund row-lock settlement: late definitive failure blocked by lock and ignored after success', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user = User::factory()->create(['role' => 'user', 'credits' => 5]);
    $tx = Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'UKC-TEST-'.Str::random(6),
        'currency' => 'GBP',
        'amount' => 19.99,
        'payment_method' => 'stripe',
        'type' => 'payment',
        'category' => 'credit_purchase',
        'status' => 'success',
        'payment_gateway_ref' => 'pi_test_'.Str::random(10),
    ]);
    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $admin->id,
        'gateway' => 'stripe',
        'payment_reference' => $tx->payment_gateway_ref,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Test late failure protection',
        'metadata' => ['credits_to_deduct' => 5],
    ]);
    DB::commit();

    $barrierDir = storage_path('framework/tmp/barrier_'.Str::random(8));
    $barrier = new ConcurrencyBarrier($barrierDir);
    $runningWorkers = [];

    try {
        $w1 = runConcurrencyWorker([
            'action' => 'refund-settle',
            'role' => 'worker_1',
            'record_id' => $record->id,
            'method' => 'settleSuccess',
            'provider_refund_id' => 're_win_001',
            'hold_with_barrier' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w1;

        $w2 = runConcurrencyWorker([
            'action' => 'refund-settle',
            'role' => 'worker_2',
            'record_id' => $record->id,
            'method' => 'settleFailure',
            'reason' => 'Late failure delivery',
            'wait_for_lock' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w2;

        $barrier->wait('worker_1_ready');
        $barrier->wait('worker_2_ready');

        $w1Meta = $barrier->getMeta('worker_1_meta');
        $w2Meta = $barrier->getMeta('worker_2_meta');

        $barrier->mark('settle_start_signal');
        $barrier->wait('worker_1_locked');

        // Prove Worker 2 blocked
        $blockInfo = waitForBlocking($w2Meta['pg_backend_pid'], $w1Meta['pg_backend_pid']);
        expect($blockInfo['wait_event_type'])->toBe('Lock');

        $barrier->mark('worker_1_allow_commit');

        $res1 = collectWorkerResult($w1);
        $res2 = collectWorkerResult($w2);

        expect($res1['result']['status'])->toBe('settled')
            ->and($res2['result']['status'])->toBe('ignored_late_failure');

        // Verify data integrity:
        $freshRecord = RefundRecord::findOrFail($record->id);
        expect($freshRecord->status)->toBe(RefundRecord::STATUS_SUCCESS);

        $freshUser = User::findOrFail($user->id);
        expect($freshUser->credits)->toBe(5); // No credits restored

        expect(CreditTransaction::where('user_id', $user->id)->count())->toBe(0);

        // Exactly 1 audit log created by Worker 1
        expect(AdminActivityLog::where('target_id', $tx->id)->count())->toBe(1);

    } finally {
        cleanupWorkers($runningWorkers, $barrier);
        RefundRecord::where('id', $record->id)->delete();
        Transaction::where('id', $tx->id)->delete();
        CreditTransaction::where('user_id', $user->id)->delete();
        AdminActivityLog::where('target_id', $tx->id)->delete();
        User::whereIn('id', [$admin->id, $user->id])->delete();
    }
});

test('D3. refund row-lock settlement: concurrent duplicate failure settlement restores credits exactly once', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user = User::factory()->create(['role' => 'user', 'credits' => 5]);
    $tx = Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'UKC-TEST-'.Str::random(6),
        'currency' => 'GBP',
        'amount' => 19.99,
        'payment_method' => 'stripe',
        'type' => 'payment',
        'category' => 'credit_purchase',
        'status' => 'success',
        'payment_gateway_ref' => 'pi_test_'.Str::random(10),
    ]);
    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $admin->id,
        'gateway' => 'stripe',
        'payment_reference' => $tx->payment_gateway_ref,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Concurrent duplicate failure',
        'metadata' => ['credits_to_deduct' => 5],
    ]);
    DB::commit();

    $barrierDir = storage_path('framework/tmp/barrier_'.Str::random(8));
    $barrier = new ConcurrencyBarrier($barrierDir);
    $runningWorkers = [];

    try {
        $w1 = runConcurrencyWorker([
            'action' => 'refund-settle',
            'role' => 'worker_1',
            'record_id' => $record->id,
            'method' => 'settleFailure',
            'reason' => 'First failure delivery',
            'hold_with_barrier' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w1;

        $w2 = runConcurrencyWorker([
            'action' => 'refund-settle',
            'role' => 'worker_2',
            'record_id' => $record->id,
            'method' => 'settleFailure',
            'reason' => 'Second failure delivery',
            'wait_for_lock' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w2;

        $barrier->wait('worker_1_ready');
        $barrier->wait('worker_2_ready');

        $w1Meta = $barrier->getMeta('worker_1_meta');
        $w2Meta = $barrier->getMeta('worker_2_meta');

        $barrier->mark('settle_start_signal');
        $barrier->wait('worker_1_locked');

        // Prove Worker 2 blocked
        $blockInfo = waitForBlocking($w2Meta['pg_backend_pid'], $w1Meta['pg_backend_pid']);
        expect($blockInfo['wait_event_type'])->toBe('Lock');

        $barrier->mark('worker_1_allow_commit');

        $res1 = collectWorkerResult($w1);
        $res2 = collectWorkerResult($w2);

        expect($res1['result']['status'])->toBe('reversed')
            ->and($res2['result']['status'])->toBe('already_failed');

        // User credits restored EXACTLY ONCE: 5 + 5 = 10
        $freshUser = User::findOrFail($user->id);
        expect($freshUser->credits)->toBe(10);

        // Exactly ONE CreditTransaction record exists
        $creditTxs = CreditTransaction::where('user_id', $user->id)->get();
        expect($creditTxs)->toHaveCount(1)
            ->and($creditTxs->first()->amount)->toBe(5);

        // Exactly ONE audit log exists
        expect(AdminActivityLog::where('target_id', $tx->id)->count())->toBe(1);

    } finally {
        cleanupWorkers($runningWorkers, $barrier);
        RefundRecord::where('id', $record->id)->delete();
        Transaction::where('id', $tx->id)->delete();
        CreditTransaction::where('user_id', $user->id)->delete();
        AdminActivityLog::where('target_id', $tx->id)->delete();
        User::whereIn('id', [$admin->id, $user->id])->delete();
    }
});

test('E. concurrent duplicate credit consumption: two processes charging the same vehicle check deduct exactly one credit', function () {
    $user = User::factory()->create(['role' => 'user', 'credits' => 5]);

    $registration = 'IDEM-CONC-'.Str::random(8);

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $registration,
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    $report = Report::create([
        'user_id' => $user->id,
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['level' => 'basic'],
    ]);

    // The worker processes use independent connections, so the fixture rows
    // must be committed before the workers start.
    DB::commit();

    $idempotencyKey = 'usage:vin_check:'.$vinCheck->id;

    $barrierDir = storage_path('framework/tmp/barrier_'.Str::random(8));
    $barrier = new ConcurrencyBarrier($barrierDir);
    $runningWorkers = [];

    try {
        $w1 = runConcurrencyWorker([
            'action' => 'credit-consume',
            'role' => 'worker_1',
            'user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'reference_id' => (string) $report->id,
            'hold_with_barrier' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w1;

        $w2 = runConcurrencyWorker([
            'action' => 'credit-consume',
            'role' => 'worker_2',
            'user_id' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'reference_id' => (string) $report->id,
            'wait_for_lock' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w2;

        $barrier->wait('worker_1_ready');
        $barrier->wait('worker_2_ready');

        $w1Meta = $barrier->getMeta('worker_1_meta');
        $w2Meta = $barrier->getMeta('worker_2_meta');

        $barrier->mark('consume_start_signal');
        $barrier->wait('worker_1_charged');

        // Prove Worker 2 blocked behind Worker 1 (row lock on the user balance).
        $blockInfo = waitForBlocking($w2Meta['pg_backend_pid'], $w1Meta['pg_backend_pid']);
        expect($blockInfo['wait_event_type'])->toBe('Lock');

        // Release Worker 1 so it commits its usage row.
        $barrier->mark('worker_1_allow_commit');

        $res1 = collectWorkerResult($w1);
        $res2 = collectWorkerResult($w2);

        expect($res1['success'])->toBeTrue()
            ->and($res2['success'])->toBeTrue()
            ->and($res1['result']['transaction_id'])->toBe($res2['result']['transaction_id'])
            ->and($res1['result']['idempotency_key'])->toBe($idempotencyKey)
            ->and($res2['result']['idempotency_key'])->toBe($idempotencyKey);

        // Exactly ONE usage row exists for the vehicle check.
        $usageRows = CreditTransaction::where('user_id', $user->id)
            ->where('type', 'usage')
            ->where('idempotency_key', $idempotencyKey)
            ->get();

        expect($usageRows)->toHaveCount(1)
            ->and($usageRows->first()->amount)->toBe(-1)
            ->and($usageRows->first()->reference_id)->toBe((string) $report->id);

        // Exactly ONE credit was deducted: 5 - 1 = 4.
        $freshUser = User::findOrFail($user->id);
        expect($freshUser->credits)->toBe(4);

    } finally {
        cleanupWorkers($runningWorkers, $barrier);
        CreditTransaction::where('user_id', $user->id)->delete();
        Report::where('id', $report->id)->delete();
        VinCheck::where('id', $vinCheck->id)->delete();
        Vehicle::where('id', $vehicle->id)->delete();
        User::whereKey($user->id)->delete();
    }
});

test('F. concurrent fresh vehicle check execution: two processes create exactly one report for the same vin check', function () {
    $user = User::factory()->create(['role' => 'user', 'credits' => 5]);

    $registration = 'IDEM-REPORT-'.Str::random(8);

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $vinCheck = VinCheck::create([
        'user_id' => $user->id,
        'registration_number' => $registration,
        'check_type' => 'premium',
        'status' => 'pending',
        'stage' => 'queued',
    ]);

    // The worker processes use independent connections, so the fixture rows
    // must be committed before the workers start.
    DB::commit();

    $barrierDir = storage_path('framework/tmp/barrier_'.Str::random(8));
    $barrier = new ConcurrencyBarrier($barrierDir);
    $runningWorkers = [];

    try {
        $w1 = runConcurrencyWorker([
            'action' => 'vehicle-check',
            'role' => 'worker_1',
            'vin_check_id' => $vinCheck->id,
            'existing_report_id' => null,
            'hold_with_barrier' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w1;

        $w2 = runConcurrencyWorker([
            'action' => 'vehicle-check',
            'role' => 'worker_2',
            'vin_check_id' => $vinCheck->id,
            'existing_report_id' => null,
            'wait_for_lock' => true,
            'barrier_dir' => $barrierDir,
        ]);
        $runningWorkers[] = $w2;

        $barrier->wait('worker_1_ready');
        $barrier->wait('worker_2_ready');

        $w1Meta = $barrier->getMeta('worker_1_meta');
        $w2Meta = $barrier->getMeta('worker_2_meta');

        $barrier->mark('vehicle_check_start_signal');
        $barrier->wait('worker_1_report_created');

        // Prove Worker 2 blocked behind Worker 1 on the vin_check row lock.
        $blockInfo = waitForBlocking($w2Meta['pg_backend_pid'], $w1Meta['pg_backend_pid']);
        expect($blockInfo['wait_event_type'])->toBe('Lock');

        // Release Worker 1 so it commits its report row.
        $barrier->mark('worker_1_allow_commit');

        $res1 = collectWorkerResult($w1);
        $res2 = collectWorkerResult($w2);

        expect($res1['success'])->toBeTrue()
            ->and($res2['success'])->toBeTrue()
            ->and($res1['result']['report_id'])->toBe($res2['result']['report_id']);

        // Exactly ONE report exists for the vehicle check.
        $reports = Report::where('vin_check_id', $vinCheck->id)->get();
        expect($reports)->toHaveCount(1)
            ->and($reports->first()->report_type)->toBe('premium');

        // Exactly ONE credit was deducted: 5 - 1 = 4.
        $freshUser = User::findOrFail($user->id);
        expect($freshUser->credits)->toBe(4);

        $usageRows = CreditTransaction::where('user_id', $user->id)
            ->where('type', 'usage')
            ->get();

        expect($usageRows)->toHaveCount(1)
            ->and($usageRows->first()->idempotency_key)->toBe('usage:vin_check:'.$vinCheck->id)
            ->and($usageRows->first()->reference_id)->toBe((string) $reports->first()->id);

    } finally {
        cleanupWorkers($runningWorkers, $barrier);
        CreditTransaction::where('user_id', $user->id)->delete();
        Report::where('vin_check_id', $vinCheck->id)->delete();
        VinCheck::where('id', $vinCheck->id)->delete();
        Vehicle::where('id', $vehicle->id)->delete();
        User::whereKey($user->id)->delete();
    }
});
