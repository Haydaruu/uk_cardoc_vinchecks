<?php

use App\Jobs\ProcessVehicleCheck;
use App\Models\CreditTransaction;
use App\Models\GuestReportPurchase;
use App\Models\ProcessedWebhookEvent;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\Admin\AdminRefundReconciliationService;
use App\Services\AdminAuditService;
use App\Services\CreditCaptureService;
use App\Services\CreditService;
use App\Services\GuestReportCaptureService;
use App\Services\GuestReportFulfillmentService;
use App\Services\GuestReportPaymentService;
use App\Services\PayPalService;
use App\Services\VehicleCheckDispatchService;
use App\Services\WebhookRecoveryService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stripe\Exception\ApiConnectionException;
use Stripe\StripeClient;

uses(RefreshDatabase::class);

function createMockRecoveryService(
    ?StripeClient $stripeClient = null,
    ?PayPalService $payPalService = null,
    ?VehicleCheckDispatchService $vehicleCheckDispatchService = null,
    ?GuestReportFulfillmentService $guestReportFulfillmentService = null,
): WebhookRecoveryService {
    $auditMock = Mockery::mock(AdminAuditService::class);
    $payPal = $payPalService ?? Mockery::mock(PayPalService::class);
    $refundRecon = new AdminRefundReconciliationService($auditMock, $payPal, $stripeClient);
    $guestCapture = Mockery::mock(GuestReportCaptureService::class);
    $guestPayment = app(GuestReportPaymentService::class);
    $creditCapture = Mockery::mock(CreditCaptureService::class);
    $creditService = app(CreditService::class);
    $vehicleCheckDispatchService ??= new VehicleCheckDispatchService(Cache::store());
    $fulfillmentService = $guestReportFulfillmentService
        ?? new GuestReportFulfillmentService($vehicleCheckDispatchService);

    return new WebhookRecoveryService(
        refundReconciliationService: $refundRecon,
        guestReportCaptureService: $guestCapture,
        guestReportPaymentService: $guestPayment,
        creditCaptureService: $creditCapture,
        creditService: $creditService,
        payPalService: $payPal,
        guestReportFulfillmentService: $fulfillmentService,
        stripeClient: $stripeClient,
    );
}

function createPayPalGuestPurchaseForRecoveryTest(
    string $fulfillmentStatus = GuestReportPurchase::FULFILLMENT_PENDING,
): array {
    $registration = 'PPREC'.strtoupper(bin2hex(random_bytes(4)));

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => $registration,
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['level' => 'basic'],
    ]);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'PayPal Recovery Guest',
        'email' => 'paypal-recovery@example.com',
        'phone' => '+447000000000',
        'payment_provider' => 'paypal',
        'provider_order_id' => 'ORDER-'.$registration,
        'provider_payment_id' => 'CAPTURE-RECOVERY-001',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'amount_minor' => 2199,
        'currency' => 'GBP',
        'paid_at' => now(),
        'fulfillment_status' => $fulfillmentStatus,
        'fulfillment_attempts' => 0,
    ]);

    return [$purchase, $report];
}

function createStalePayPalOrderEvent(
    GuestReportPurchase $purchase,
    int $retryCount = 0,
    ?string $ownershipToken = null,
): ProcessedWebhookEvent {
    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_m2_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => $ownershipToken ?? (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
        'retry_count' => $retryCount,
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    return $event->refresh();
}

function holdVehicleCheckLock(GuestReportPurchase $purchase, Report $report): Lock
{
    $holder = Cache::store()->lock(
        UniqueLock::getKey(vehicleCheckJobFor($purchase, $report)),
        60,
    );

    expect($holder->get())->toBeTrue();

    return $holder;
}

function vehicleCheckJobFor(GuestReportPurchase $purchase, Report $report): ProcessVehicleCheck
{
    return new ProcessVehicleCheck(
        (int) $report->vin_check_id,
        (int) $report->id,
        (int) $purchase->id,
    );
}

test('1. stale event found and claimed atomically by recovery service', function () {
    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_stale_claim_'.Str::random(8),
        'event_type' => 'unknown.type',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['object_id' => 'obj_123'],
    ]);

    // Backdate updated_at to make it stale (> 10m ago)
    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(15),
    ]);

    $service = createMockRecoveryService();
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['candidates_count'])->toBe(1)
        ->and($result['claimed_count'])->toBe(1)
        ->and($result['manual_review_count'])->toBe(1); // Unsupported type moves to manual_review

    $fresh = ProcessedWebhookEvent::find($event->id);
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_MANUAL_REVIEW)
        ->and($fresh->ownership_token)->not->toBe($event->ownership_token);
});

test('2. racing recovery scheduler fails to claim same event when affected is 0', function () {
    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_racing_claim_'.Str::random(8),
        'event_type' => 'charge.refunded',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['object_id' => 're_123'],
    ]);

    // Keep updated_at fresh (only 2 minutes ago)
    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(2),
    ]);

    $service = createMockRecoveryService();
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['candidates_count'])->toBe(0)
        ->and($result['claimed_count'])->toBe(0);

    $fresh = ProcessedWebhookEvent::find($event->id);
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_PROCESSING)
        ->and($fresh->ownership_token)->toBe($event->ownership_token);
});

test('3. credit payment already confirmed in domain does not grant credits twice and marks event completed', function () {
    $user = User::factory()->create(['credits' => 10]);
    $intentId = 'pi_already_paid_'.Str::random(8);

    // Seed existing Transaction & CreditTransaction
    Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'UKC-TEST-'.Str::random(6),
        'currency' => 'GBP',
        'amount' => 10.00,
        'payment_method' => 'stripe',
        'type' => 'payment',
        'category' => 'credit_purchase',
        'status' => 'success',
        'payment_gateway_ref' => $intentId,
    ]);

    CreditTransaction::create([
        'user_id' => $user->id,
        'amount' => 5,
        'balance_after' => 15,
        'type' => 'purchase',
        'reference_id' => $intentId,
        'idempotency_key' => $intentId,
        'description' => 'Test credit grant',
    ]);

    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_pi_'.Str::random(8),
        'event_type' => 'payment_intent.succeeded',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['object_id' => $intentId],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(15),
    ]);

    $service = createMockRecoveryService();
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(1);

    // Assert credits remained exactly 10 (no double credit grant)
    expect(User::find($user->id)->credits)->toBe(10);
    expect(CreditTransaction::where('idempotency_key', $intentId)->count())->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED)
        ->and($fresh->processed_at)->not->toBeNull();
});

test('4. guest purchase confirmed PAID with fulfillment pending triggers vehicle check enqueue', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    $vehicle = Vehicle::create([
        'registration_number' => 'AB12CDE',
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => 'AB12CDE',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    $sessionId = 'cs_guest_paid_'.Str::random(8);
    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'buyer@example.com',
        'checkout_reference' => (string) Str::uuid(),
        'payment_provider' => 'stripe',
        'provider_order_id' => $sessionId,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
    ]);

    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_cs_'.Str::random(8),
        'event_type' => 'checkout.session.completed',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['object_id' => $sessionId],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $service = createMockRecoveryService();
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(1);

    Queue::assertPushed(ProcessVehicleCheck::class, function ($job) use ($purchase) {
        return $job->uniqueId() === 'guest-report-purchase-'.$purchase->id
            && (new ReflectionProperty($job, 'fulfillmentOrigin'))->getValue($job) === 'recovery';
    });

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->not->toBeNull()
        ->and($purchase->fulfillment_generation)->toBe(1)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);
});

test('5. provider API exception triggers retry with backoff and advances next_retry_at', function () {
    $intentId = 'pi_api_fail_'.Str::random(8);

    // Mock StripeClient to throw connection exception
    $stripeMock = Mockery::mock(StripeClient::class);
    $piServiceMock = Mockery::mock();
    $piServiceMock->shouldReceive('retrieve')
        ->with($intentId)
        ->andThrow(new ApiConnectionException('Connection to Stripe timed out'));
    $stripeMock->paymentIntents = $piServiceMock;

    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_timeout_'.Str::random(8),
        'event_type' => 'payment_intent.succeeded',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['object_id' => $intentId],
        'retry_count' => 0,
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(12),
    ]);

    $service = createMockRecoveryService(stripeClient: $stripeMock);
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['retried_count'])->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($fresh->retry_count)->toBe(1)
        ->and($fresh->next_retry_at)->not->toBeNull()
        ->and($fresh->error_message)->toContain('Connection to Stripe timed out');
});

test('6. event exceeding MAX_RETRIES escalates to manual_review', function () {
    $intentId = 'pi_max_retry_'.Str::random(8);

    $stripeMock = Mockery::mock(StripeClient::class);
    $piServiceMock = Mockery::mock();
    $piServiceMock->shouldReceive('retrieve')
        ->with($intentId)
        ->andThrow(new ApiConnectionException('Persistent API failure'));
    $stripeMock->paymentIntents = $piServiceMock;

    // Seed event already at retry_count = 2 (next failure is 3 >= MAX_RETRIES)
    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_max_retry_'.Str::random(8),
        'event_type' => 'payment_intent.succeeded',
        'status' => ProcessedWebhookEvent::STATUS_FAILED,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['object_id' => $intentId],
        'retry_count' => 2,
        'next_retry_at' => now()->subMinute(),
    ]);

    $service = createMockRecoveryService(stripeClient: $stripeMock);
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['manual_review_count'])->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_MANUAL_REVIEW)
        ->and($fresh->retry_count)->toBe(3)
        ->and($fresh->error_message)->toContain('Exceeded max retries (3)');
});

test('7. malformed event missing resource ID is immediately flagged for manual_review', function () {
    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_missing_id_'.Str::random(8),
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => [], // Empty summary!
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(15),
    ]);

    $service = createMockRecoveryService();
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['manual_review_count'])->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_MANUAL_REVIEW)
        ->and($fresh->error_message)->toContain('no resource_id in payload_summary');
});

test('8. ownership token prevents stale worker from overwriting recovered event status', function () {
    $staleWorkerToken = (string) Str::uuid();
    $intentId = 'pi_fenced_'.Str::random(8);

    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_fenced_'.Str::random(8),
        'event_type' => 'payment_intent.succeeded',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => $staleWorkerToken,
        'payload_summary' => ['object_id' => $intentId],
    ]);

    // Backdate to make stale
    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    // Create domain records so recovery service completes it
    $user = User::factory()->create();
    Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'UKC-TEST-'.Str::random(6),
        'currency' => 'GBP',
        'amount' => 10.00,
        'payment_method' => 'stripe',
        'type' => 'payment',
        'category' => 'credit_purchase',
        'status' => 'success',
        'payment_gateway_ref' => $intentId,
    ]);
    CreditTransaction::create([
        'user_id' => $user->id,
        'amount' => 5,
        'balance_after' => 15,
        'type' => 'purchase',
        'reference_id' => $intentId,
        'idempotency_key' => $intentId,
    ]);

    // Recovery runs and claims it with a new token
    $service = createMockRecoveryService();
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10);
    expect($result['completed_count'])->toBe(1);

    $recoveredEvent = ProcessedWebhookEvent::find($event->id);
    expect($recoveredEvent->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);
    $recoveryToken = $recoveredEvent->ownership_token;
    expect($recoveryToken)->not->toBe($staleWorkerToken);

    // Stale worker tries to update using its expired $staleWorkerToken
    $affected = ProcessedWebhookEvent::query()
        ->where('id', $event->id)
        ->where('ownership_token', $staleWorkerToken)
        ->update(['status' => ProcessedWebhookEvent::STATUS_FAILED]);

    expect($affected)->toBe(0);

    // Status remains completed
    expect(ProcessedWebhookEvent::find($event->id)->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);
});

test('9. dry-run mode returns candidate inspection without modifying database', function () {
    $event = ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => 'evt_dry_run_'.Str::random(8),
        'event_type' => 'charge.refunded',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['object_id' => 're_dry_123'],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(15),
    ]);

    $service = createMockRecoveryService();
    $result = $service->recoverStaleEvents(limit: 10, staleMinutes: 10, dryRun: true);

    expect($result['candidates_count'])->toBe(1)
        ->and($result['claimed_count'])->toBe(0)
        ->and($result['details'][0]['action'])->toBe('would_reconcile');

    $fresh = ProcessedWebhookEvent::find($event->id);
    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_PROCESSING)
        ->and($fresh->ownership_token)->toBe($event->ownership_token);
});

test('10. batch limit is strictly observed', function () {
    for ($i = 0; $i < 5; $i++) {
        $e = ProcessedWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => 'evt_limit_batch_'.Str::random(8),
            'event_type' => 'unknown.type',
            'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
            'ownership_token' => (string) Str::uuid(),
            'payload_summary' => ['object_id' => 'obj_'.$i],
        ]);
        ProcessedWebhookEvent::where('id', $e->id)->update(['updated_at' => now()->subMinutes(20)]);
    }

    $service = createMockRecoveryService();
    $result = $service->recoverStaleEvents(limit: 2, staleMinutes: 10);

    expect($result['candidates_count'])->toBe(2)
        ->and($result['claimed_count'])->toBe(2);
});

test('11. incoming duplicate webhook for event in manual_review is ignored by executeWithDeduplication', function () {
    $eventId = 'evt_manual_review_'.Str::random(8);

    ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => $eventId,
        'event_type' => 'charge.refunded',
        'status' => ProcessedWebhookEvent::STATUS_MANUAL_REVIEW,
        'ownership_token' => (string) Str::uuid(),
        'error_message' => 'Escalated to manual review',
    ]);

    $auditMock = Mockery::mock(AdminAuditService::class);
    $payPalMock = Mockery::mock(PayPalService::class);
    $service = new AdminRefundReconciliationService($auditMock, $payPalMock);

    $called = false;
    $res = $service->executeWithDeduplication(
        provider: 'stripe',
        eventId: $eventId,
        eventType: 'charge.refunded',
        callback: function () use (&$called) {
            $called = true;
        },
    );

    expect($called)->toBeFalse()
        ->and($res['processed'])->toBeFalse()
        ->and($res['status'])->toBe('manual_review');
});

test('12. console command webhooks:reconcile-stale executes successfully', function () {
    $this->artisan('webhooks:reconcile-stale --dry-run')
        ->assertExitCode(0);
});

test('13. paypal order approved for guest purchase already paid enqueues ProcessVehicleCheck and completes the stale event', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest();

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_order_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['candidates_count'])->toBe(1)
        ->and($result['claimed_count'])->toBe(1)
        ->and($result['completed_count'])->toBe(1)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(0)
        ->and($result['skipped_count'])->toBe(0)
        ->and($result['details'][0]['action'])->toBe('completed');

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED)
        ->and($fresh->processed_at)->not->toBeNull()
        ->and($fresh->error_message)->toBeNull()
        ->and($fresh->retry_count)->toBe(0);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
    Queue::assertPushed(
        ProcessVehicleCheck::class,
        fn (ProcessVehicleCheck $job): bool => $job->uniqueId() === 'guest-report-purchase-'.$purchase->id
            && (new ReflectionProperty($job, 'fulfillmentOrigin'))->getValue($job) === 'recovery'
    );

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING)
        ->and($purchase->fulfillment_attempts)->toBe(0)
        ->and($purchase->fulfillment_dispatch_uuid)->not->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(1);
});

test('14. paypal order approved for guest purchase with locked fulfillment normalizes to pending and enqueues ProcessVehicleCheck', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest(
        GuestReportPurchase::FULFILLMENT_LOCKED,
    );

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_LOCKED);

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_locked_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(1)
        ->and($result['retried_count'])->toBe(0);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('15. paypal guest recovery retries when the vehicle check cannot be enqueued', function () {
    config(['queue.default' => 'database']);

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest();

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('Queue connection refused');
        }

        return null;
    });
    app()->instance(Dispatcher::class, $dispatcher);

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_retry_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['retried_count'])->toBe(1)
        ->and($result['completed_count'])->toBe(0)
        ->and($result['in_flight_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(0)
        ->and($result['details'][0]['action'])->toBe('retry');

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($fresh->processed_at)->toBeNull()
        ->and($fresh->retry_count)->toBe(1)
        ->and($fresh->next_retry_at)->not->toBeNull()
        ->and($fresh->error_message)->toContain('Queue connection refused');

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
});

test('16. paypal guest recovery escalates to manual review when the paid purchase has no associated report', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Deferred foreign-key simulation requires SQLite.');
    }

    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForRecoveryTest();

    // Simulate a dangling report reference (data corruption) without tripping
    // SQLite's immediate foreign-key check inside the RefreshDatabase transaction.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    DB::table('guest_report_purchases')
        ->where('id', $purchase->id)
        ->update(['report_id' => $report->id + 999999]);

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_missing_report_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['candidates_count'])->toBe(1)
        ->and($result['claimed_count'])->toBe(1)
        ->and($result['completed_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_MANUAL_REVIEW)
        ->and($fresh->error_message)->toContain('no associated report');

    Queue::assertNothingPushed();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
});

test('17. paypal guest recovery escalates to manual review when the report has no vin_check_id', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForRecoveryTest();
    $report->update(['vin_check_id' => null]);

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_missing_vin_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_MANUAL_REVIEW)
        ->and($fresh->error_message)->toContain('no vin_check_id');

    Queue::assertNothingPushed();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
});

test('18. paypal guest recovery retries when fulfillment previously failed', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest(
        GuestReportPurchase::FULFILLMENT_FAILED,
    );

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_failed_fulfillment_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(1)
        ->and($result['manual_review_count'])->toBe(0);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($fresh->retry_count)->toBe(1)
        ->and($fresh->next_retry_at)->not->toBeNull()
        ->and($fresh->error_message)->toContain('re-enqueued');

    Queue::assertPushed(ProcessVehicleCheck::class, 1);

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_FAILED)
        ->and($purchase->fulfillment_attempts)->toBe(0);
});

test('19. paypal capture recovery dispatches exactly one vehicle check for pending fulfillment', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest();

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_pending_dispatch_'.Str::random(8),
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_payment_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(1)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(0);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
    Queue::assertPushed(
        ProcessVehicleCheck::class,
        fn (ProcessVehicleCheck $job): bool => $job->uniqueId() === 'guest-report-purchase-'.$purchase->id
    );

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
});

test('20. paypal guest recovery does not re-dispatch a completed fulfillment', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest(
        GuestReportPurchase::FULFILLMENT_COMPLETED,
    );

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_completed_fulfillment_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(1)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(0);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);

    Queue::assertNothingPushed();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_COMPLETED);
});

test('21. paypal guest recovery escalates failed fulfillment to manual review at the retry boundary', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest(
        GuestReportPurchase::FULFILLMENT_FAILED,
    );

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_retry_boundary_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'retry_count' => WebhookRecoveryService::MAX_RETRIES - 1,
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_MANUAL_REVIEW)
        ->and($fresh->retry_count)->toBe(WebhookRecoveryService::MAX_RETRIES)
        ->and($fresh->error_message)->toContain('Exceeded max retries')
        ->and($fresh->error_message)->toContain('retry limit reached');

    Queue::assertNothingPushed();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_FAILED);
});

test('22. paypal guest recovery below the retry boundary still dispatches failed fulfillment and schedules a retry', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest(
        GuestReportPurchase::FULFILLMENT_FAILED,
    );

    $event = ProcessedWebhookEvent::create([
        'provider' => 'paypal',
        'event_id' => 'evt_paypal_below_boundary_'.Str::random(8),
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'status' => ProcessedWebhookEvent::STATUS_PROCESSING,
        'ownership_token' => (string) Str::uuid(),
        'payload_summary' => ['resource_id' => $purchase->provider_order_id],
    ]);

    ProcessedWebhookEvent::where('id', $event->id)->update([
        'retry_count' => WebhookRecoveryService::MAX_RETRIES - 2,
        'updated_at' => now()->subMinutes(20),
    ]);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(1)
        ->and($result['in_flight_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(0);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($fresh->retry_count)->toBe(WebhookRecoveryService::MAX_RETRIES - 1)
        ->and($fresh->next_retry_at)->not->toBeNull()
        ->and($fresh->error_message)->toContain('re-enqueued');

    Queue::assertPushed(ProcessVehicleCheck::class, 1);

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-RECOVERY-001')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_FAILED)
        ->and($purchase->fulfillment_attempts)->toBe(0);
});

test('23. pending fulfillment with the vehicle check lock held reports in flight and keeps the retry count', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForRecoveryTest();
    $event = createStalePayPalOrderEvent($purchase, retryCount: 1);
    $holder = holdVehicleCheckLock($purchase, $report);

    try {
        $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);
    } finally {
        $holder->release();
    }

    expect($result['candidates_count'])->toBe(1)
        ->and($result['claimed_count'])->toBe(1)
        ->and($result['in_flight_count'])->toBe(1)
        ->and($result['completed_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(0)
        ->and($result['skipped_count'])->toBe(0)
        ->and($result['details'][0]['action'])->toBe('in_flight');

    Queue::assertNothingPushed();

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_PROCESSING)
        ->and($fresh->retry_count)->toBe(1)
        ->and($fresh->processed_at)->toBeNull()
        ->and($fresh->updated_at->greaterThan(now()->subMinute()))->toBeTrue();

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
});

test('24. failed fulfillment with the vehicle check lock held reports in flight and keeps the retry count', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForRecoveryTest(
        GuestReportPurchase::FULFILLMENT_FAILED,
    );
    $event = createStalePayPalOrderEvent($purchase, retryCount: 1);
    $holder = holdVehicleCheckLock($purchase, $report);

    try {
        $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);
    } finally {
        $holder->release();
    }

    expect($result['in_flight_count'])->toBe(1)
        ->and($result['completed_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(0)
        ->and($result['details'][0]['action'])->toBe('in_flight');

    Queue::assertNothingPushed();

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_PROCESSING)
        ->and($fresh->retry_count)->toBe(1)
        ->and($fresh->processed_at)->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_FAILED);
});

test('25. processing fulfillment with the vehicle check lock held reports in flight', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForRecoveryTest(
        GuestReportPurchase::FULFILLMENT_PROCESSING,
    );
    $event = createStalePayPalOrderEvent($purchase);
    $holder = holdVehicleCheckLock($purchase, $report);

    try {
        $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);
    } finally {
        $holder->release();
    }

    expect($result['in_flight_count'])->toBe(1)
        ->and($result['completed_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(0)
        ->and($result['details'][0]['action'])->toBe('in_flight');

    Queue::assertNothingPushed();

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_PROCESSING)
        ->and($fresh->retry_count)->toBe(0)
        ->and($fresh->processed_at)->toBeNull();

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PROCESSING);
});

test('26. a queue push exception produces a retry outcome and never completes the event', function () {
    config(['queue.default' => 'database']);

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest();

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($job) {
        if ($job instanceof ProcessVehicleCheck) {
            throw new RuntimeException('M2 queue connection refused');
        }

        return null;
    });
    app()->instance(Dispatcher::class, $dispatcher);

    $event = createStalePayPalOrderEvent($purchase);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(0)
        ->and($result['in_flight_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(1)
        ->and($result['manual_review_count'])->toBe(0)
        ->and($result['details'][0]['action'])->toBe('retry');

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($fresh->processed_at)->toBeNull()
        ->and($fresh->retry_count)->toBe(1)
        ->and($fresh->error_message)->toContain('M2 queue connection refused')
        ->and($fresh->error_message)->toContain('vehicle check push failed');

    $purchase->refresh();

    expect($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
});

test('27. the retry boundary prevents any lock claim or dispatcher call', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForRecoveryTest(
        GuestReportPurchase::FULFILLMENT_FAILED,
    );
    $event = createStalePayPalOrderEvent(
        $purchase,
        retryCount: WebhookRecoveryService::MAX_RETRIES - 1,
    );

    $fulfillmentService = Mockery::mock(GuestReportFulfillmentService::class);
    $fulfillmentService->shouldNotReceive('reserveAndDispatch');

    $result = createMockRecoveryService(guestReportFulfillmentService: $fulfillmentService)
        ->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(0)
        ->and($result['in_flight_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['manual_review_count'])->toBe(1);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_MANUAL_REVIEW)
        ->and($fresh->retry_count)->toBe(WebhookRecoveryService::MAX_RETRIES)
        ->and($fresh->error_message)->toContain('retry limit reached');

    Queue::assertNothingPushed();

    $probe = Cache::store()->lock(
        UniqueLock::getKey(vehicleCheckJobFor($purchase, $report)),
        1,
    );

    expect($probe->get())->toBeTrue();

    $probe->release();
});

test('28. in-flight recovery preserves ownership token fencing', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForRecoveryTest();
    $staleWorkerToken = (string) Str::uuid();
    $event = createStalePayPalOrderEvent(
        $purchase,
        ownershipToken: $staleWorkerToken,
    );
    $holder = holdVehicleCheckLock($purchase, $report);

    try {
        $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);
    } finally {
        $holder->release();
    }

    expect($result['in_flight_count'])->toBe(1);

    $recovered = ProcessedWebhookEvent::find($event->id);

    expect($recovered->status)->toBe(ProcessedWebhookEvent::STATUS_PROCESSING)
        ->and($recovered->ownership_token)->not->toBe($staleWorkerToken);

    $affected = ProcessedWebhookEvent::query()
        ->where('id', $event->id)
        ->where('ownership_token', $staleWorkerToken)
        ->update(['status' => ProcessedWebhookEvent::STATUS_FAILED]);

    expect($affected)->toBe(0)
        ->and(ProcessedWebhookEvent::find($event->id)->status)
        ->toBe(ProcessedWebhookEvent::STATUS_PROCESSING);
});

test('29. an in-flight event remains eligible for recovery after the processing timeout', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForRecoveryTest();
    $event = createStalePayPalOrderEvent($purchase);
    $holder = holdVehicleCheckLock($purchase, $report);

    $first = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($first['in_flight_count'])->toBe(1)
        ->and($first['completed_count'])->toBe(0);

    Queue::assertNothingPushed();

    $holder->release();

    $this->travel(11)->minutes();

    $second = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($second['candidates_count'])->toBe(1)
        ->and($second['in_flight_count'])->toBe(0)
        ->and($second['completed_count'])->toBe(1);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);

    expect(ProcessedWebhookEvent::find($event->id)->status)
        ->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);
});

test('30. recovery call sites require the processed webhook event argument', function () {
    $dispatchMethod = new ReflectionMethod(
        WebhookRecoveryService::class,
        'dispatchVehicleCheckIfEligible',
    );
    $dispatchEvent = $dispatchMethod->getParameters()[1];

    $correlationMethod = new ReflectionMethod(
        WebhookRecoveryService::class,
        'reconcilePayPalOrderCorrelation',
    );
    $correlationEvent = $correlationMethod->getParameters()[2];

    expect($dispatchEvent->getName())->toBe('event')
        ->and($dispatchEvent->isOptional())->toBeFalse()
        ->and($dispatchEvent->allowsNull())->toBeFalse()
        ->and($correlationEvent->getName())->toBe('event')
        ->and($correlationEvent->isOptional())->toBeFalse()
        ->and($correlationEvent->allowsNull())->toBeFalse();
});

test('31. recovery repushes the existing dispatch generation and allocates one push slot', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest();
    $dispatchUuid = (string) Str::uuid();

    DB::table('guest_report_purchases')
        ->where('id', $purchase->id)
        ->update([
            'fulfillment_generation' => 1,
            'fulfillment_dispatch_uuid' => $dispatchUuid,
            'fulfillment_dispatch_expires_at' => now()->addMinutes(10),
            'fulfillment_dispatch_push_slots_allocated' => 1,
        ]);

    $event = createStalePayPalOrderEvent($purchase);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['completed_count'])->toBe(1)
        ->and($result['retried_count'])->toBe(0)
        ->and($result['in_flight_count'])->toBe(0);

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($purchase->fulfillment_generation)->toBe(1)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(2);

    Queue::assertPushed(
        ProcessVehicleCheck::class,
        fn (ProcessVehicleCheck $job): bool => (new ReflectionProperty($job, 'fulfillmentOrigin'))->getValue($job) === 'recovery'
            && (new ReflectionProperty($job, 'fulfillmentDispatchUuid'))->getValue($job) === $dispatchUuid
    );

    expect(ProcessedWebhookEvent::find($event->id)->status)
        ->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);
});

test('32. recovery escalates to manual review when all dispatch push slots are used', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest();
    $dispatchUuid = (string) Str::uuid();

    DB::table('guest_report_purchases')
        ->where('id', $purchase->id)
        ->update([
            'fulfillment_generation' => 1,
            'fulfillment_dispatch_uuid' => $dispatchUuid,
            'fulfillment_dispatch_expires_at' => now()->addMinutes(10),
            'fulfillment_dispatch_push_slots_allocated' => GuestReportFulfillmentService::MAX_PUSH_SLOTS,
        ]);

    $event = createStalePayPalOrderEvent($purchase);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['manual_review_count'])->toBe(1)
        ->and($result['in_flight_count'])->toBe(0)
        ->and($result['retried_count'])->toBe(0);

    $purchase->refresh();

    expect($purchase->fulfillment_dispatch_uuid)->toBe($dispatchUuid)
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(GuestReportFulfillmentService::MAX_PUSH_SLOTS)
        ->and($purchase->fulfillment_manual_review_at)->not->toBeNull();

    Queue::assertNotPushed(ProcessVehicleCheck::class);

    expect(ProcessedWebhookEvent::find($event->id)->status)
        ->toBe(ProcessedWebhookEvent::STATUS_MANUAL_REVIEW);
});

test('33. recovery maps a sync driver skip to retry without completing the event', function () {
    // phpunit.xml sets QUEUE_CONNECTION=sync; no async override here.
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForRecoveryTest();
    $event = createStalePayPalOrderEvent($purchase);

    $result = createMockRecoveryService()->recoverStaleEvents(limit: 10, staleMinutes: 10);

    expect($result['retried_count'])->toBe(1)
        ->and($result['completed_count'])->toBe(0)
        ->and($result['in_flight_count'])->toBe(0);

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->fulfillment_dispatch_uuid)->toBeNull()
        ->and($purchase->fulfillment_dispatch_push_slots_allocated)->toBe(0);

    Queue::assertNotPushed(ProcessVehicleCheck::class);

    $fresh = ProcessedWebhookEvent::find($event->id);

    expect($fresh->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($fresh->retry_count)->toBe(1)
        ->and($fresh->error_message)->toContain('sync queue driver');
});
