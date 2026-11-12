<?php

use App\Models\ProcessedWebhookEvent;
use App\Services\Admin\AdminRefundReconciliationService;
use App\Services\AdminAuditService;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Webhook Deduplication & Concurrency Tests
|--------------------------------------------------------------------------
|
| Note on test environment:
| These tests execute on SQLite (:memory:) using RefreshDatabase. They verify
| application-level lease calculations, ownership fencing token matching, stale
| reclamation, error handling, and state transitions.
|
| They do NOT simulate true parallel multi-connection PostgreSQL concurrency,
| engine-level SAVEPOINT behavior on UniqueConstraintViolationException, or
| row-level locks (which require Phase 3.5.3 PostgreSQL verification).
|
*/

uses(RefreshDatabase::class);

function createReconciliationServiceForTest(): AdminRefundReconciliationService
{
    $auditMock = Mockery::mock(AdminAuditService::class);
    $payPalMock = Mockery::mock(PayPalService::class);

    return new AdminRefundReconciliationService($auditMock, $payPalMock);
}

test('first delivery acquires ownership, sets ownership token, executes callback, and marks event completed', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_first_delivery_'.Str::random(10);

    $called = 0;
    $result = $service->executeWithDeduplication(
        provider: 'stripe',
        eventId: $eventId,
        eventType: 'charge.refunded',
        callback: function () use (&$called) {
            $called++;
        },
        payloadSummary: ['amount' => 1999],
    );

    expect($result)->toBe([
        'processed' => true,
        'status' => 'completed',
    ]);
    expect($called)->toBe(1);

    $event = ProcessedWebhookEvent::where('provider', 'stripe')
        ->where('event_id', $eventId)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED)
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->ownership_token)->not->toBeNull()
        ->and(Str::isUuid($event->ownership_token))->toBeTrue()
        ->and($event->payload_summary)->toBe(['amount' => 1999]);
});

test('duplicate delivery while event is processing returns in_flight without running callback', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_in_flight_'.Str::random(10);

    // Simulate another worker actively processing this event with an active lease
    ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => $eventId,
        'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
    ]);

    $called = 0;
    $result = $service->executeWithDeduplication(
        provider: 'paypal',
        eventId: $eventId,
        eventType: 'PAYMENT.CAPTURE.REFUNDED',
        callback: function () use (&$called) {
            $called++;
        },
    );

    expect($result['processed'])->toBeFalse()
        ->and($result['status'])->toBe('in_flight')
        ->and($called)->toBe(0);

    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event->status)->toBe(ProcessedWebhookEvent::STATUS_PROCESSING);
});

test('duplicate delivery after event is completed returns already_completed without running callback or altering token', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_completed_'.Str::random(10);
    $initialToken = (string) Str::uuid();

    // Simulate an already completed event
    ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => $eventId,
        'event_type' => 'charge.refunded',
        'status' => ProcessedWebhookEvent::STATUS_COMPLETED,
        'ownership_token' => $initialToken,
        'processed_at' => now(),
    ]);

    $called = 0;
    $result = $service->executeWithDeduplication(
        provider: 'stripe',
        eventId: $eventId,
        eventType: 'charge.refunded',
        callback: function () use (&$called) {
            $called++;
        },
    );

    expect($result['processed'])->toBeFalse()
        ->and($result['status'])->toBe('already_completed')
        ->and($called)->toBe(0);

    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event->ownership_token)->toBe($initialToken);
});

test('concurrent delivery simulation during active callback returns in_flight and prevents duplicate execution', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_simulated_concurrent_'.Str::random(10);

    // Note: In SQLite, this tests that while Worker 1 is in-flight, Worker 2 sees the processing state
    // and returns 'in_flight' rather than executing the callback.
    $calledWorker1 = 0;
    $calledWorker2 = 0;

    $result1 = $service->executeWithDeduplication(
        provider: 'stripe',
        eventId: $eventId,
        eventType: 'charge.refunded',
        callback: function () use (&$calledWorker1, $service, $eventId, &$calledWorker2, &$result2) {
            $calledWorker1++;

            // While Worker 1 is actively inside its callback, Worker 2 arrives:
            $result2 = $service->executeWithDeduplication(
                provider: 'stripe',
                eventId: $eventId,
                eventType: 'charge.refunded',
                callback: function () use (&$calledWorker2) {
                    $calledWorker2++;
                },
            );
        },
    );

    // Worker 1 must complete successfully
    expect($result1['processed'])->toBeTrue()
        ->and($calledWorker1)->toBe(1);

    // Worker 2 must have received in_flight without executing its callback
    expect($result2['processed'])->toBeFalse()
        ->and($result2['status'])->toBe('in_flight')
        ->and($calledWorker2)->toBe(0);
});

test('failed event can be retried and receives a fresh ownership token', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_failed_retry_'.Str::random(10);
    $oldToken = (string) Str::uuid();

    // Simulate earlier failure with old token
    ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => $eventId,
        'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
        'status' => ProcessedWebhookEvent::STATUS_FAILED,
        'ownership_token' => $oldToken,
        'error_message' => 'Transient network error on previous attempt',
    ]);

    $called = 0;
    $result = $service->executeWithDeduplication(
        provider: 'paypal',
        eventId: $eventId,
        eventType: 'PAYMENT.CAPTURE.REFUNDED',
        callback: function () use (&$called) {
            $called++;
        },
    );

    expect($result['processed'])->toBeTrue()
        ->and($result['status'])->toBe('completed')
        ->and($called)->toBe(1);

    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED)
        ->and($event->error_message)->toBeNull()
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->ownership_token)->not->toBeNull()
        ->and($event->ownership_token)->not->toBe($oldToken);
});

test('stale processing event older than lease threshold can be reclaimed with fresh ownership token', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_stale_reclaim_'.Str::random(10);
    $initialToken = (string) Str::uuid();

    // Simulate an event that started processing 10 minutes ago and worker died (lease = 120s)
    $staleEvent = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => $eventId,
        'event_type' => 'charge.refunded',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => $initialToken,
    ]);
    ProcessedWebhookEvent::where('id', $staleEvent->id)->update([
        'updated_at' => now()->subMinutes(10),
        'created_at' => now()->subMinutes(10),
    ]);

    $called = 0;
    $result = $service->executeWithDeduplication(
        provider: 'stripe',
        eventId: $eventId,
        eventType: 'charge.refunded',
        callback: function () use (&$called) {
            $called++;
        },
        leaseSeconds: 120,
    );

    expect($result['processed'])->toBeTrue()
        ->and($result['status'])->toBe('completed')
        ->and($called)->toBe(1);

    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED)
        ->and($event->processed_at)->not->toBeNull()
        ->and($event->ownership_token)->not->toBe($initialToken);
});

test('stale processing event cannot be reclaimed if still within lease threshold', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_still_active_'.Str::random(10);

    // Event started processing 30 seconds ago (lease = 120s)
    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => $eventId,
        'event_type' => 'charge.refunded',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
    ]);
    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subSeconds(30),
    ]);

    $called = 0;
    $result = $service->executeWithDeduplication(
        provider: 'stripe',
        eventId: $eventId,
        eventType: 'charge.refunded',
        callback: function () use (&$called) {
            $called++;
        },
        leaseSeconds: 120,
    );

    expect($result['processed'])->toBeFalse()
        ->and($result['status'])->toBe('in_flight')
        ->and($called)->toBe(0);

    $fresh = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_PROCESSING);
});

test('stale Worker A losing ownership to Worker B cannot overwrite status to completed due to fencing token mismatch', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_fencing_stale_completion_'.Str::random(10);

    $worker1Result = null;
    $worker2Result = null;

    // Worker 1 acquires ownership
    $worker1Result = $service->executeWithDeduplication(
        provider: 'stripe',
        eventId: $eventId,
        eventType: 'charge.refunded',
        leaseSeconds: 10,
        callback: function () use ($service, $eventId, &$worker2Result) {
            // Worker 1 starts slow callback.
            // Simulate that lease expires (10s lease exceeded):
            ProcessedWebhookEvent::where('event_id', $eventId)->update([
                'updated_at' => now()->subSeconds(15),
            ]);

            // Worker 2 reclaims the stale lease while Worker 1 is still in callback
            $worker2Result = $service->executeWithDeduplication(
                provider: 'stripe',
                eventId: $eventId,
                eventType: 'charge.refunded',
                leaseSeconds: 10,
                callback: function () {
                    // Worker 2 completes its work quickly
                },
            );
        },
    );

    // Worker 2 acquired and completed
    expect($worker2Result['processed'])->toBeTrue()
        ->and($worker2Result['status'])->toBe('completed');

    // Worker 1 finished callback but its fencing token was overwritten by Worker 2,
    // so Worker 1 receives ownership_lost and does NOT corrupt Worker 2's record
    expect($worker1Result['processed'])->toBeFalse()
        ->and($worker1Result['status'])->toBe('ownership_lost');

    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);
});

test('stale Worker A throwing an exception after losing ownership cannot overwrite status to failed', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_fencing_stale_exception_'.Str::random(10);

    $worker2Token = null;
    $exceptionCaught = false;

    try {
        $service->executeWithDeduplication(
            provider: 'stripe',
            eventId: $eventId,
            eventType: 'charge.refunded',
            leaseSeconds: 10,
            callback: function () use ($service, $eventId, &$worker2Token) {
                // Lease expires:
                ProcessedWebhookEvent::where('event_id', $eventId)->update([
                    'updated_at' => now()->subSeconds(15),
                ]);

                // Worker 2 reclaims:
                $service->executeWithDeduplication(
                    provider: 'stripe',
                    eventId: $eventId,
                    eventType: 'charge.refunded',
                    leaseSeconds: 10,
                    callback: function () {},
                );

                $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
                $worker2Token = $event->ownership_token;

                // Worker 1 now encounters an exception
                throw new RuntimeException('Late failure from stale Worker 1');
            },
        );
    } catch (RuntimeException $e) {
        $exceptionCaught = true;
        expect($e->getMessage())->toBe('Late failure from stale Worker 1');
    }

    expect($exceptionCaught)->toBeTrue();

    // Verify that Worker 1's exception handler did NOT change status to failed,
    // because Worker 1's token did not match the reclaimed token in the database
    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED)
        ->and($event->ownership_token)->toBe($worker2Token)
        ->and($event->error_message)->toBeNull();
});

test('callback exception marks event failed with error message and re-throws when token matches', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_exception_'.Str::random(10);

    $exceptionThrown = false;

    try {
        $service->executeWithDeduplication(
            provider: 'stripe',
            eventId: $eventId,
            eventType: 'charge.refunded',
            callback: function () {
                throw new RuntimeException('Connection timeout while contacting Stripe API');
            },
        );
    } catch (RuntimeException $e) {
        $exceptionThrown = true;
        expect($e->getMessage())->toBe('Connection timeout while contacting Stripe API');
    }

    expect($exceptionThrown)->toBeTrue();

    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event)->not->toBeNull()
        ->and($event->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($event->error_message)->toContain('Connection timeout while contacting Stripe API')
        ->and($event->processed_at)->toBeNull();
});

test('payload summary is preserved on initial event creation', function () {
    $service = createReconciliationServiceForTest();
    $eventId = 'evt_payload_'.Str::random(10);

    $service->executeWithDeduplication(
        provider: 'paypal',
        eventId: $eventId,
        eventType: 'PAYMENT.CAPTURE.COMPLETED',
        callback: function () {},
        payloadSummary: ['capture_id' => 'CAP-12345', 'amount' => '50.00'],
    );

    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event->payload_summary)->toBe([
        'capture_id' => 'CAP-12345',
        'amount' => '50.00',
    ]);
});
