<?php

use App\Contracts\VehicleDataProviderInterface;
use App\Jobs\ProcessVehicleCheck;
use App\Models\ProcessedWebhookEvent;
use App\Models\RefundRecord;
use App\Models\Report;
use App\Models\User;
use App\Services\Admin\AdminRefundReconciliationService;
use App\Services\CreditService;
use App\Services\Reports\NormalizedReportBuilder;
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
    } elseif ($action === 'credit-consume') {
        $userId = (int) $config['user_id'];
        $idempotencyKey = (string) $config['idempotency_key'];
        $referenceId = (string) $config['reference_id'];
        $holdWithBarrier = (bool) ($config['hold_with_barrier'] ?? false);
        $waitForLock = (bool) ($config['wait_for_lock'] ?? false);

        $creditService = app(CreditService::class);
        $user = User::findOrFail($userId);

        if ($barrier) {
            $barrier->mark($role.'_ready');
            $barrier->wait('consume_start_signal');
        }

        if ($waitForLock && $barrier) {
            $barrier->wait('worker_1_charged');
            $barrier->mark($role.'_attempting_consume');
        }

        $timings['step_at'] = microtime(true);

        $result = DB::transaction(function () use ($creditService, $user, $idempotencyKey, $referenceId, $barrier, $role, $holdWithBarrier) {
            $transaction = $creditService->consume(
                user: $user,
                referenceId: $referenceId,
                description: 'Concurrency verification charge',
                idempotencyKey: $idempotencyKey,
            );

            if ($holdWithBarrier && $barrier) {
                // The usage row is inserted but still uncommitted: the second
                // worker must block behind the first one and must not produce a
                // second usage row once the first worker commits.
                $barrier->mark('worker_1_charged');
                $barrier->wait($role.'_allow_commit', 15.0);
            }

            return [
                'transaction_id' => $transaction->id,
                'idempotency_key' => $transaction->idempotency_key,
                'balance_after' => $transaction->balance_after,
            ];
        });
    } elseif ($action === 'vehicle-check') {
        $vinCheckId = (int) $config['vin_check_id'];
        $existingReportId = isset($config['existing_report_id']) && $config['existing_report_id'] !== null
            ? (int) $config['existing_report_id']
            : null;
        $holdWithBarrier = (bool) ($config['hold_with_barrier'] ?? false);
        $waitForLock = (bool) ($config['wait_for_lock'] ?? false);

        if ($barrier) {
            $barrier->mark($role.'_ready');
            $barrier->wait('vehicle_check_start_signal');
        }

        if ($waitForLock && $barrier) {
            $barrier->wait('worker_1_report_created');
            $barrier->mark($role.'_attempting_check');
        }

        $timings['step_at'] = microtime(true);

        $provider = Mockery::mock(VehicleDataProviderInterface::class);
        $provider->shouldReceive('getRegistrationDetails')->andReturn([]);

        $reportBuilder = Mockery::mock(NormalizedReportBuilder::class);
        $reportBuilder->shouldReceive('build')->andReturn([
            'normalized' => [
                'vehicle' => [
                    'vin' => 'VIN-CONCURRENCY-001',
                    'make' => 'Ford',
                    'model' => 'Focus',
                    'year' => 2020,
                    'colour' => 'Blue',
                    'fuel_type' => 'Petrol',
                    'engine_capacity_cc' => 1000,
                    'image_url' => null,
                ],
                'history' => [
                    'finance' => ['count' => 0],
                    'write_off' => ['records' => []],
                ],
                'mot' => [
                    'tests' => [
                        [
                            'date' => '2025-01-01',
                            'expiry_date' => '2026-01-01',
                        ],
                    ],
                    'tax_status' => 'Taxed',
                ],
                'specifications' => [
                    'co2_gkm' => 100,
                ],
            ],
            'raw' => ['source' => 'concurrency-test'],
        ]);

        $job = new ProcessVehicleCheck($vinCheckId, $existingReportId);

        // The job transaction is nested inside this outer transaction, so the
        // report row and the vin_check row lock stay uncommitted while the
        // worker waits at the barrier. The second worker must therefore block
        // on the vin_check row lock until the first worker commits.
        $result = DB::transaction(function () use ($job, $provider, $reportBuilder, $barrier, $role, $holdWithBarrier, $vinCheckId) {
            $job->handle(
                $provider,
                $reportBuilder,
                app(CreditService::class),
            );

            if ($holdWithBarrier && $barrier) {
                $barrier->mark('worker_1_report_created');
                $barrier->wait($role.'_allow_commit', 15.0);
            }

            return [
                'report_id' => Report::where('vin_check_id', $vinCheckId)->value('id'),
                'report_count' => Report::where('vin_check_id', $vinCheckId)->count(),
            ];
        });
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
