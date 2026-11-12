<?php

use App\Models\ProcessedWebhookEvent;
use App\Models\RefundRecord;
use App\Services\Admin\AdminRefundReconciliationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ConcurrencyBarrier;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// 1. Mandatory Safety Guard
$conn = config('database.default');
$db = DB::connection()->getDatabaseName();

if ($conn !== 'pgsql' || $db !== 'ukcardoc_concurrency_test') {
    fwrite(STDERR, "FATAL SAFETY GUARD: Worker connection={$conn}, db={$db}. Only pgsql ukcardoc_concurrency_test is permitted.\n");
    exit(99);
}

// 2. Load Config from JSON input file
$configFile = $argv[1] ?? null;
if (! $configFile || ! file_exists($configFile)) {
    fwrite(STDERR, "Missing or invalid config file argument.\n");
    exit(1);
}

$config = json_decode(file_get_contents($configFile), true);
if (! is_array($config)) {
    fwrite(STDERR, "Invalid JSON in config file.\n");
    exit(1);
}

$action = $config['action'] ?? '';
$role = $config['role'] ?? 'worker';
$barrierDir = $config['barrier_dir'] ?? null;
$barrier = $barrierDir ? new ConcurrencyBarrier($barrierDir) : null;

$pid = getmypid();
$pgBackendPid = (int) DB::selectOne('SELECT pg_backend_pid() as pid')->pid;

if ($barrier) {
    $barrier->setMeta($role.'_meta', [
        'pid' => $pid,
        'pg_backend_pid' => $pgBackendPid,
        'role' => $role,
    ]);
}

$timings = [
    'started_at' => microtime(true),
    'step_at' => null,
    'completed_at' => null,
];

$callbackCalled = false;
$result = null;
$error = null;
$insertAttempted = false;
$insertSucceeded = false;
$markerId = null;

try {
    /** @var AdminRefundReconciliationService $service */
    $service = app(AdminRefundReconciliationService::class);

    if ($action === 'webhook-insert') {
        $provider = $config['provider'];
        $eventId = $config['event_id'];
        $eventType = $config['event_type'] ?? 'charge.refunded';
        $holdWithBarrier = (bool) ($config['hold_with_barrier'] ?? false);
        $waitForInsert = (bool) ($config['wait_for_insert'] ?? false);
        $inOuterTransaction = (bool) ($config['in_outer_transaction'] ?? false);

        ProcessedWebhookEvent::saving(function () use (&$insertAttempted) {
            $insertAttempted = true;
        });

        ProcessedWebhookEvent::created(function () use (&$insertSucceeded, $barrier, $holdWithBarrier) {
            $insertSucceeded = true;
            if ($holdWithBarrier && $barrier) {
                $barrier->mark('w1_inserted');
                $barrier->wait('w1_allow_commit', 15.0);
            }
        });

        if ($barrier) {
            $barrier->mark($role.'_ready');
            $barrier->wait('start_signal');
        }

        if ($waitForInsert && $barrier) {
            $barrier->wait('w1_inserted');
            $barrier->mark($role.'_attempting_insert');
        }

        $timings['step_at'] = microtime(true);

        if ($inOuterTransaction) {
            // Use a ProcessedWebhookEvent marker record (no FK to users table) to prove
            // that the outer DB::transaction() is NOT aborted by an inner SAVEPOINT collision.
            $markerProvider = (string) ($config['marker_provider'] ?? 'stripe_sp_marker');
            $markerEventId = 'sp_marker_'.$pid.'_'.Str::random(8);
            $outerResult = DB::transaction(function () use ($service, $provider, $eventId, $eventType, &$callbackCalled, $pid, $role, $markerProvider, $markerEventId) {
                $dedupRes = $service->executeWithDeduplication(
                    provider: $provider,
                    eventId: $eventId,
                    eventType: $eventType,
                    callback: function () use (&$callbackCalled) {
                        $callbackCalled = true;
                    },
                    payloadSummary: ['worker_pid' => $pid, 'role' => $role],
                );

                // Run an additional INSERT in the outer transaction to prove it was NOT aborted
                // by the inner SAVEPOINT rollback caused by the unique constraint collision.
                $marker = ProcessedWebhookEvent::create([
                    'provider' => $markerProvider,
                    'event_id' => $markerEventId,
                    'event_type' => 'savepoint.marker',
                    'status' => ProcessedWebhookEvent::STATUS_COMPLETED,
                    'payload_summary' => ['marker' => true, 'worker_pid' => $pid, 'role' => $role],
                ]);

                return [
                    'dedup' => $dedupRes,
                    'marker_id' => $marker->id,
                ];
            });

            $result = $outerResult['dedup'];
            $markerId = $outerResult['marker_id'];
        } else {
            $result = $service->executeWithDeduplication(
                provider: $provider,
                eventId: $eventId,
                eventType: $eventType,
                callback: function () use (&$callbackCalled) {
                    $callbackCalled = true;
                },
                payloadSummary: ['worker_pid' => $pid, 'role' => $role],
            );
        }
    } elseif ($action === 'webhook-stale-reclaim') {
        $provider = $config['provider'];
        $eventId = $config['event_id'];
        $eventType = $config['event_type'] ?? 'charge.refunded';
        $leaseSeconds = (int) ($config['lease_seconds'] ?? 120);
        $holdWithBarrier = (bool) ($config['hold_with_barrier'] ?? false);
        $waitForReclaim = (bool) ($config['wait_for_reclaim'] ?? false);

        if ($barrier) {
            $barrier->mark($role.'_ready');
            $barrier->wait('reclaim_start_signal');
        }

        if ($waitForReclaim && $barrier) {
            $barrier->wait('worker_a_reclaimed');
            $barrier->mark($role.'_attempting_reclaim');
        }

        $timings['step_at'] = microtime(true);

        if ($holdWithBarrier) {
            $result = DB::transaction(function () use ($service, $provider, $eventId, $eventType, &$callbackCalled, $pid, $role, $leaseSeconds, $barrier) {
                return $service->executeWithDeduplication(
                    provider: $provider,
                    eventId: $eventId,
                    eventType: $eventType,
                    callback: function () use (&$callbackCalled, $barrier, $role) {
                        $callbackCalled = true;
                        if ($barrier) {
                            $barrier->mark($role.'_reclaimed');
                            $barrier->wait($role.'_allow_commit', 15.0);
                        }
                    },
                    payloadSummary: ['worker_pid' => $pid, 'role' => $role],
                    leaseSeconds: $leaseSeconds,
                );
            });
        } else {
            $result = $service->executeWithDeduplication(
                provider: $provider,
                eventId: $eventId,
                eventType: $eventType,
                callback: function () use (&$callbackCalled) {
                    $callbackCalled = true;
                },
                payloadSummary: ['worker_pid' => $pid, 'role' => $role],
                leaseSeconds: $leaseSeconds,
            );
        }
    } elseif ($action === 'refund-settle') {
        $recordId = (int) $config['record_id'];
        $method = $config['method'] ?? 'settleSuccess';
        $providerRefundId = $config['provider_refund_id'] ?? null;
        $reason = $config['reason'] ?? 'Definitive refund failure';
        $holdWithBarrier = (bool) ($config['hold_with_barrier'] ?? false);
        $waitForLock = (bool) ($config['wait_for_lock'] ?? false);

        if ($holdWithBarrier && $barrier) {
            RefundRecord::updating(function () use ($barrier, $role) {
                $barrier->mark($role.'_locked');
                $barrier->wait($role.'_allow_commit', 15.0);
            });
        }

        if ($barrier) {
            $barrier->mark($role.'_ready');
            $barrier->wait('settle_start_signal');
        }

        if ($waitForLock && $barrier) {
            $barrier->wait('worker_1_locked');
            $barrier->mark($role.'_attempting_settle');
        }

        $timings['step_at'] = microtime(true);

        $record = RefundRecord::findOrFail($recordId);

        // Access protected methods via closure bind
        if ($method === 'settleSuccess') {
            $invoker = Closure::bind(function (AdminRefundReconciliationService $svc, RefundRecord $r, ?string $ref) {
                return $svc->settleRefundSuccess($r, $ref);
            }, null, AdminRefundReconciliationService::class);

            $result = $invoker($service, $record, $providerRefundId);
        } else {
            $invoker = Closure::bind(function (AdminRefundReconciliationService $svc, RefundRecord $r, string $res) {
                return $svc->settleRefundDefinitiveFailure($r, $res);
            }, null, AdminRefundReconciliationService::class);

            $result = $invoker($service, $record, $reason);
        }
    } else {
        throw new InvalidArgumentException("Unknown worker action: {$action}");
    }
} catch (Throwable $e) {
    $error = [
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
}

$timings['completed_at'] = microtime(true);

echo json_encode([
    'success' => $error === null,
    'pid' => $pid,
    'pg_backend_pid' => $pgBackendPid,
    'action' => $action,
    'role' => $role,
    'timings' => $timings,
    'callback_called' => $callbackCalled,
    'insert_attempted' => $insertAttempted,
    'insert_succeeded' => $insertSucceeded,
    'marker_id' => $markerId,
    'result' => $result,
    'error' => $error,
]);
