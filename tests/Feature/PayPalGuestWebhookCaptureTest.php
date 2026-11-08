<?php

use App\Jobs\ProcessPayPalGuestCapture;
use App\Jobs\ProcessPayPalOrderCapture;
use App\Jobs\ProcessVehicleCheck;
use App\Jobs\SendTelegramSaleNotification;
use App\Models\CreditCheckoutAttempt;
use App\Models\GuestReportCaptureAttempt;
use App\Models\GuestReportPurchase;
use App\Models\ProcessedWebhookEvent;
use App\Models\Report;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\GuestCaptureResult;
use App\Services\GuestReportCaptureService;
use App\Services\GuestReportCheckoutService;
use App\Services\GuestReportPaymentService;
use App\Services\PayPalService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

function createWebhookTestPurchase(array $attributes = []): GuestReportPurchase
{
    $registration = 'WH'.strtoupper(Str::random(6));

    $vinCheck = VinCheck::create([
        'registration_number' => $registration,
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => $registration,
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
        'report_data' => ['level' => 'basic'],
    ]);

    return GuestReportPurchase::create(array_merge([
        'report_id' => $report->id,
        'name' => 'Webhook Test User',
        'email' => 'wh-'.Str::random(6).'@example.com',
        'phone' => '+447000000001',
        'payment_provider' => 'paypal',
        'provider_order_id' => 'ORDER-WH-'.strtoupper(Str::random(10)),
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_LOCKED,
        'fulfillment_attempts' => 0,
    ], $attributes));
}

function createCreditTestAttempt(User $user, array $overrides = []): CreditCheckoutAttempt
{
    return CreditCheckoutAttempt::create(array_merge([
        'user_id' => $user->id,
        'intent_reference' => (string) Str::uuid(),
        'attempt_uuid' => (string) Str::uuid(),
        'invoice_id' => 'UKC-CR-'.strtoupper(Str::random(16)),
        'plan_slug' => 'starter',
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-'.strtoupper(Str::random(8)),
        'expires_at' => now()->addMinutes(30),
    ], $overrides));
}

function buildOrderApprovedWebhookPayload(string $orderId, string $eventId, ?array $purchaseUnits = null): array
{
    $resource = [
        'id' => $orderId,
        'status' => 'APPROVED',
        'intent' => 'CAPTURE',
    ];

    if ($purchaseUnits !== null) {
        $resource['purchase_units'] = $purchaseUnits;
    }

    return [
        'id' => $eventId,
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => $resource,
    ];
}

function buildCaptureCompletedWebhookPayload(
    string $captureId,
    string $orderId,
    string $eventId,
    string $status = 'COMPLETED',
): array {
    return [
        'id' => $eventId,
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => $captureId,
            'status' => $status,
            'amount' => [
                'value' => '28.00',
                'currency_code' => 'GBP',
            ],
            'supplementary_data' => [
                'related_ids' => [
                    'order_id' => $orderId,
                ],
            ],
        ],
    ];
}

function buildGuestOrderWithCaptures(
    string $orderId,
    array $captures = [],
    string $customId = 'guest-report|REF-WEBHOOK-TEST|1',
): array {
    return [
        'id' => $orderId,
        'intent' => 'CAPTURE',
        'status' => 'COMPLETED',
        'purchase_units' => [
            [
                'reference_id' => '1',
                'custom_id' => $customId,
                'payments' => [
                    'captures' => $captures,
                ],
            ],
        ],
    ];
}

function mockPayPalVerify(): void
{
    $mock = Mockery::mock(PayPalService::class);
    $mock->shouldReceive('verifyWebhook')->once()->andReturn(true);
    app()->instance(PayPalService::class, $mock);
}

// ─────────────────────────────────────────────────────────────
// Stage 2: CHECKOUT.ORDER.APPROVED routing tests
// ─────────────────────────────────────────────────────────────

test('CHECKOUT.ORDER.APPROVED for known guest order dispatches ProcessPayPalGuestCapture', function () {
    Queue::fake();

    $purchase = createWebhookTestPurchase();
    $orderId = $purchase->provider_order_id;
    $eventId = 'WH-GUEST-DISPATCH-001';

    mockPayPalVerify();

    $payload = buildOrderApprovedWebhookPayload($orderId, $eventId);

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertJson(['received' => true]);

    Queue::assertPushed(ProcessPayPalGuestCapture::class, function ($job) use ($orderId, $eventId) {
        return $job->orderId === $orderId && $job->eventId === $eventId;
    });

    Queue::assertNotPushed(ProcessPayPalOrderCapture::class);
});

test('CHECKOUT.ORDER.APPROVED for known credit order dispatches ProcessPayPalOrderCapture', function () {
    Queue::fake();

    $user = User::factory()->create();
    $creditOrderId = 'ORDER-CREDIT-'.strtoupper(Str::random(8));
    $eventId = 'WH-CREDIT-DISPATCH-001';

    $attempt = createCreditTestAttempt($user, [
        'provider_order_id' => $creditOrderId,
    ]);

    mockPayPalVerify();

    $payload = buildOrderApprovedWebhookPayload($creditOrderId, $eventId);

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertJson(['received' => true]);

    Queue::assertPushed(ProcessPayPalOrderCapture::class, function ($job) use ($creditOrderId) {
        return $job->orderId === $creditOrderId;
    });

    Queue::assertNotPushed(ProcessPayPalGuestCapture::class);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_PROVIDER_APPROVED);
});

test('CHECKOUT.ORDER.APPROVED for unknown non-empty order id does not dispatch any capture job', function () {
    Queue::fake();

    $unknownOrderId = 'ORDER-UNKNOWN-NONEMPTY-999';
    $eventId = 'WH-UNKNOWN-NONEMPTY-001';

    mockPayPalVerify();

    $payload = buildOrderApprovedWebhookPayload($unknownOrderId, $eventId);

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertJson(['received' => true]);

    Queue::assertNotPushed(ProcessPayPalGuestCapture::class);
    Queue::assertNotPushed(ProcessPayPalOrderCapture::class);
});

test('CHECKOUT.ORDER.APPROVED for empty order id returns 200 without dispatching any capture job', function () {
    Queue::fake();

    $eventId = 'WH-UNKNOWN-EMPTY-001';

    mockPayPalVerify();

    $payload = [
        'id' => $eventId,
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => [
            'id' => '',
            'status' => 'APPROVED',
        ],
    ];

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertJson(['received' => true]);

    Queue::assertNotPushed(ProcessPayPalGuestCapture::class);
    Queue::assertNotPushed(ProcessPayPalOrderCapture::class);
});

test('CHECKOUT.ORDER.APPROVED for legitimate legacy credit order dispatches ProcessPayPalOrderCapture', function () {
    Queue::fake();

    $user = User::factory()->create();
    $legacyOrderId = 'ORDER-LEGACY-001';
    $eventId = 'WH-LEGACY-001';

    // Configure a one_time plan
    config(['credit_plans.starter' => [
        'name' => 'Starter',
        'type' => 'one_time',
        'credits' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
    ]]);

    mockPayPalVerify();

    $payload = buildOrderApprovedWebhookPayload($legacyOrderId, $eventId, [
        [
            'reference_id' => 'starter',
            'custom_id' => "{$user->id}|starter",
        ],
    ]);

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertJson(['received' => true]);

    Queue::assertPushed(ProcessPayPalOrderCapture::class, function ($job) use ($legacyOrderId) {
        return $job->orderId === $legacyOrderId;
    });

    Queue::assertNotPushed(ProcessPayPalGuestCapture::class);
});

test('CHECKOUT.ORDER.APPROVED with malformed or mismatched correlation data is rejected as unknown', function () {
    Queue::fake();

    $user = User::factory()->create();
    $orderId = 'ORDER-MISMATCH-001';

    config(['credit_plans.starter' => [
        'name' => 'Starter',
        'type' => 'one_time',
        'credits' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
    ]]);

    mockPayPalVerify();

    // Mismatched reference_id vs custom_id plan slug
    $payload = buildOrderApprovedWebhookPayload($orderId, 'WH-MISMATCH-001', [
        [
            'reference_id' => 'wrong-plan',
            'custom_id' => "{$user->id}|starter",
        ],
    ]);

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertJson(['received' => true]);

    Queue::assertNotPushed(ProcessPayPalGuestCapture::class);
    Queue::assertNotPushed(ProcessPayPalOrderCapture::class);
});

test('duplicate CHECKOUT.ORDER.APPROVED for guest order is deduplicated and guest job dispatched only once', function () {
    Queue::fake();

    $purchase = createWebhookTestPurchase();
    $orderId = $purchase->provider_order_id;
    $eventId = 'WH-GUEST-DEDUP-001';
    $payload = buildOrderApprovedWebhookPayload($orderId, $eventId);

    $mock1 = Mockery::mock(PayPalService::class);
    $mock1->shouldReceive('verifyWebhook')->once()->andReturn(true);
    app()->instance(PayPalService::class, $mock1);

    $res1 = $this->postJson(route('paypal.webhook'), $payload);
    $res1->assertOk()->assertJson(['received' => true]);

    $mock2 = Mockery::mock(PayPalService::class);
    $mock2->shouldReceive('verifyWebhook')->once()->andReturn(true);
    app()->instance(PayPalService::class, $mock2);

    $res2 = $this->postJson(route('paypal.webhook'), $payload);
    $res2->assertOk()->assertJson(['received' => true]);

    Queue::assertPushed(ProcessPayPalGuestCapture::class, 1);
});

// ─────────────────────────────────────────────────────────────
// Stage 1: Lock Timeout Recovery & Bounded Delayed Retry Tests
// ─────────────────────────────────────────────────────────────

test('processing result (202 lock timeout) causes delayed release of guest capture job', function () {
    $orderId = 'ORDER-PROC-'.strtoupper(Str::random(8));

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('captureFromWebhook')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(
            status: 'processing',
            errorMessage: 'Lock held by another worker.',
            httpStatus: 202,
        ));

    $job = new ProcessPayPalGuestCapture($orderId, 'WH-JOB-PROC-001');

    $mockQueueJob = Mockery::mock(Job::class);
    $mockQueueJob->shouldReceive('release')->with(20)->once();
    $job->setJob($mockQueueJob);

    $job->handle($captureServiceMock);
});

test('retryUntil provides sufficient budget exceeding the 85-second service lock TTL', function () {
    $job = new ProcessPayPalGuestCapture('ORDER-BUDGET-001');

    $retryUntil = $job->retryUntil();

    // Must be at least 85s in the future (we use 180s)
    $remainingSeconds = $retryUntil->getTimestamp() - now()->getTimestamp();
    expect($remainingSeconds)->toBeGreaterThanOrEqual(85)
        ->and($remainingSeconds)->toBeLessThanOrEqual(300);

    // Job tries budget must also comfortably exceed 85s (10 * 20s = 200s)
    expect($job->tries)->toBeGreaterThanOrEqual(5);
});

test('job eventually completes when lock becomes available after previous release', function () {
    $orderId = 'ORDER-EVENTUAL-001';

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);

    // Attempt 1: lock busy -> release(20)
    $captureServiceMock
        ->shouldReceive('captureFromWebhook')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(
            status: 'processing',
            httpStatus: 202,
        ));

    $job = new ProcessPayPalGuestCapture($orderId, 'WH-JOB-EV-001');
    $mockQueueJob1 = Mockery::mock(Job::class);
    $mockQueueJob1->shouldReceive('release')->with(20)->once();
    $job->setJob($mockQueueJob1);

    $job->handle($captureServiceMock);

    // Attempt 2: lock becomes free, capture completes
    $captureServiceMock
        ->shouldReceive('captureFromWebhook')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(
            status: 'completed',
            httpStatus: 200,
            reconciled: true,
        ));

    $mockQueueJob2 = Mockery::mock(Job::class);
    $mockQueueJob2->shouldNotReceive('release');
    $job->setJob($mockQueueJob2);

    $job->handle($captureServiceMock);
});

test('job retry deadline is finite and stops retrying after configured deadline', function () {
    $job = new ProcessPayPalGuestCapture('ORDER-DEADLINE-001');

    $retryUntil = $job->retryUntil();

    expect($retryUntil)->toBeInstanceOf(DateTimeInterface::class)
        ->and($retryUntil->getTimestamp())->toBeGreaterThan(time());
});

test('job release never creates a second capture attempt merely because it was released', function () {
    $purchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'capture_request_started_at' => now()->subSeconds(30),
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-release-test',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
        'request_started_at' => now()->subSeconds(30),
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('captureFromWebhook')
        ->twice()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(status: 'processing', httpStatus: 202));

    $job = new ProcessPayPalGuestCapture($orderId, 'WH-RELEASE-IDEMP-001');
    $mockQueueJob = Mockery::mock(Job::class);
    $mockQueueJob->shouldReceive('release')->with(20)->twice();
    $job->setJob($mockQueueJob);

    // Run handle twice (simulating retry after release)
    $job->handle($captureServiceMock);
    $job->handle($captureServiceMock);

    // Assert attempt count and active UUID remain strictly unchanged
    expect(GuestReportCaptureAttempt::where('guest_report_purchase_id', $purchase->id)->count())->toBe(1);
    $purchase->refresh();
    expect($purchase->active_capture_attempt_uuid)->toBe($attempt->attempt_uuid);
});

// ─────────────────────────────────────────────────────────────
// Stage 3: Failed Job State Consistency Tests
// ─────────────────────────────────────────────────────────────

test('job failed() transitions pending purchase with NULL capture_status to capture_reconciliation_required', function () {
    $purchase = createWebhookTestPurchase([
        'capture_status' => null,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
    ]);

    $job = new ProcessPayPalGuestCapture($purchase->provider_order_id, 'WH-FAILED-NULL-001');
    $job->failed(new RuntimeException('Queue retries exhausted'));

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED)
        ->and($purchase->capture_failure_reason)->toContain('Queue retries exhausted');
});

test('job failed() transitions purchase in capturing state to capture_reconciliation_required', function () {
    $purchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
    ]);

    $job = new ProcessPayPalGuestCapture($purchase->provider_order_id, 'WH-FAILED-CAPT-001');
    $job->failed(new RuntimeException('Timeout'));

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED);
});

test('job failed() updates active attempt status to error atomically with failure reason', function () {
    $purchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
    ]);

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-fail-test',
        'provider_order_id' => $purchase->provider_order_id,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $job = new ProcessPayPalGuestCapture($purchase->provider_order_id, 'WH-FAILED-ATT-001');
    $job->failed(new RuntimeException('Provider timeout on retry 10'));

    $purchase->refresh();
    $attempt->refresh();

    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED)
        ->and($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_ERROR)
        ->and($attempt->failure_reason)->toContain('Provider timeout on retry 10');
});

test('job failed() never modifies an attempt that is already completed', function () {
    $purchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
    ]);

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-already-completed',
        'provider_order_id' => $purchase->provider_order_id,
        'status' => GuestReportCaptureAttempt::STATUS_COMPLETED,
        'attempt_number' => 1,
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $job = new ProcessPayPalGuestCapture($purchase->provider_order_id, 'WH-FAILED-ATT-COMP-001');
    $job->failed(new RuntimeException('Late queue failure'));

    $attempt->refresh();
    expect($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);
});

test('job failed() does not overwrite a purchase that is already paid', function () {
    $purchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'payment_status' => GuestReportPurchase::STATUS_PAID,
    ]);

    $job = new ProcessPayPalGuestCapture($purchase->provider_order_id, 'WH-FAILED-PAID-001');
    $job->failed(new RuntimeException('Transient failure'));

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_CAPTURING)
        ->and($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID);
});

test('job failed() does not overwrite a purchase that is refunded', function () {
    $purchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
    ]);

    $job = new ProcessPayPalGuestCapture($purchase->provider_order_id, 'WH-FAILED-REF-001');
    $job->failed(new RuntimeException('Transient failure'));

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_REFUNDED)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_CAPTURING);
});

test('job failed() does not overwrite a purchase that is failed or expired', function () {
    $failedPurchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_STATUS_FAILED,
        'payment_status' => GuestReportPurchase::PAYMENT_STATUS_FAILED,
    ]);

    $job1 = new ProcessPayPalGuestCapture($failedPurchase->provider_order_id, 'WH-FAILED-FAIL-001');
    $job1->failed(new RuntimeException('Transient failure'));

    $failedPurchase->refresh();
    expect($failedPurchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_STATUS_FAILED);

    $expiredPurchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'payment_status' => GuestReportPurchase::STATUS_EXPIRED,
    ]);

    $job2 = new ProcessPayPalGuestCapture($expiredPurchase->provider_order_id, 'WH-FAILED-EXP-001');
    $job2->failed(new RuntimeException('Transient failure'));

    $expiredPurchase->refresh();
    expect($expiredPurchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_CAPTURING);
});

test('job failed() racing with concurrent settlement preserves paid and completed state', function () {
    $purchase = createWebhookTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-RACE-WIN-001',
    ]);

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-race-win',
        'provider_order_id' => $purchase->provider_order_id,
        'status' => GuestReportCaptureAttempt::STATUS_COMPLETED,
        'attempt_number' => 1,
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $job = new ProcessPayPalGuestCapture($purchase->provider_order_id, 'WH-FAILED-RACE-001');
    $job->failed(new RuntimeException('Worker timed out'));

    $purchase->refresh();
    $attempt->refresh();

    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);
});

// ─────────────────────────────────────────────────────────────
// Other Integration & Job Unit Tests
// ─────────────────────────────────────────────────────────────

test('guest capture job calls captureFromWebhook and not CreditCaptureService', function () {
    $orderId = 'ORDER-JOB-'.strtoupper(Str::random(8));

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('captureFromWebhook')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(status: 'completed', httpStatus: 200));

    $job = new ProcessPayPalGuestCapture($orderId, 'WH-JOB-EVT-001');
    $job->handle($captureServiceMock);
});

test('provider exception from captureFromWebhook propagates out of job handle to trigger queue retry', function () {
    $orderId = 'ORDER-EXC-'.strtoupper(Str::random(8));

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('captureFromWebhook')
        ->once()
        ->andThrow(new RuntimeException('PayPal provider error'));

    $job = new ProcessPayPalGuestCapture($orderId, 'WH-JOB-EXC-001');

    expect(fn () => $job->handle($captureServiceMock))
        ->toThrow(RuntimeException::class, 'PayPal provider error');
});

test('webhook arrival for guest purchase with NULL capture_status triggers GuestReportCaptureService for initial capture', function () {
    Queue::fake();

    $purchase = createWebhookTestPurchase([
        'capture_status' => null,
    ]);
    $orderId = $purchase->provider_order_id;
    $eventId = 'WH-INITIAL-001';

    mockPayPalVerify();

    $payload = buildOrderApprovedWebhookPayload($orderId, $eventId);

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertJson(['received' => true]);

    Queue::assertPushed(ProcessPayPalGuestCapture::class, function ($job) use ($orderId) {
        return $job->orderId === $orderId;
    });
});

test('webhook arrival for guest purchase already in capturing state reuses existing attempt via captureFromWebhook', function () {
    Queue::fake();

    $purchase = createWebhookTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'capture_request_started_at' => now()->subSeconds(300),
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-existing-key',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
        'request_started_at' => now()->subSeconds(300),
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    mockPayPalVerify();

    $payload = buildOrderApprovedWebhookPayload($orderId, 'WH-REPLAY-001');

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertJson(['received' => true]);

    Queue::assertPushed(ProcessPayPalGuestCapture::class);

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock
        ->shouldReceive('captureFromWebhook')
        ->once()
        ->with($orderId)
        ->andReturn(new GuestCaptureResult(status: 'completed', httpStatus: 200));

    $job = new ProcessPayPalGuestCapture($orderId, 'WH-REPLAY-001');
    $job->handle($captureServiceMock);
});

test('credit order is never routed through GuestReportCaptureService', function () {
    Queue::fake();

    $user = User::factory()->create();
    $creditOrderId = 'ORDER-CREDIT-ONLY-'.strtoupper(Str::random(8));

    createCreditTestAttempt($user, [
        'provider_order_id' => $creditOrderId,
    ]);

    $captureServiceMock = Mockery::mock(GuestReportCaptureService::class);
    $captureServiceMock->shouldNotReceive('captureFromWebhook');
    $captureServiceMock->shouldNotReceive('captureAndFulfill');
    app()->instance(GuestReportCaptureService::class, $captureServiceMock);

    mockPayPalVerify();

    $payload = buildOrderApprovedWebhookPayload($creditOrderId, 'WH-CREDIT-NOGUEST-001');

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk();

    Queue::assertPushed(ProcessPayPalOrderCapture::class);
    Queue::assertNotPushed(ProcessPayPalGuestCapture::class);
});

test('already-completed guest purchase returns completed immediately without POST when job runs', function () {
    $purchase = createWebhookTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-ALREADY-DONE',
    ]);

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $job = new ProcessPayPalGuestCapture($purchase->provider_order_id, 'WH-COMPLETED-001');
    $job->handle($service);

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED);
});

test('uniqueId for guest capture job uses correct key prefix', function () {
    $job = new ProcessPayPalGuestCapture('ORDER-UNIQUE-123', null);

    expect($job->uniqueId())->toBe('paypal-guest-capture:ORDER-UNIQUE-123');
});

test('guest capture job and credit capture job have different uniqueId prefixes preventing key collision', function () {
    $orderId = 'ORDER-SHARED-123';

    $guestJob = new ProcessPayPalGuestCapture($orderId, null);
    $creditJob = new ProcessPayPalOrderCapture($orderId, null);

    expect($guestJob->uniqueId())->not->toBe($creditJob->uniqueId())
        ->and($guestJob->uniqueId())->toBe('paypal-guest-capture:'.$orderId)
        ->and($creditJob->uniqueId())->toBe('paypal-order-capture:'.$orderId);
});

// ─────────────────────────────────────────────────────────────
// PAYMENT.CAPTURE.COMPLETED Capture Selection Tests
// ─────────────────────────────────────────────────────────────

test('PAYMENT.CAPTURE.COMPLETED webhook selects second capture when first is PENDING and second is COMPLETED', function () {
    $orderId = 'ORDER-MULTI-CAP-001';
    $pendingCaptureId = 'CAP-PENDING-001';
    $completedCaptureId = 'CAP-COMPLETED-002';
    $eventId = 'WH-EVENT-MULTI-001';

    $order = buildGuestOrderWithCaptures($orderId, [
        [
            'id' => $pendingCaptureId,
            'status' => 'PENDING',
            'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
        ],
        [
            'id' => $completedCaptureId,
            'status' => 'COMPLETED',
            'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
        ],
    ]);

    $payload = buildCaptureCompletedWebhookPayload($completedCaptureId, $orderId, $eventId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->once()->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($order);
    app()->instance(PayPalService::class, $mockPayPal);

    $paymentServiceMock = Mockery::mock(GuestReportPaymentService::class);
    $paymentServiceMock->shouldReceive('handlePayPalGuestOrder')
        ->once()
        ->with($order)
        ->andReturn(true);
    app()->instance(GuestReportPaymentService::class, $paymentServiceMock);

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertExactJson(['received' => true]);

    $processedEvent = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($processedEvent)->not->toBeNull()
        ->and($processedEvent->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);
});

test('PAYMENT.CAPTURE.COMPLETED webhook referencing PENDING capture is rejected even when order contains different COMPLETED capture', function () {
    Queue::fake();

    $orderId = 'ORDER-PENDING-REF-001';
    $pendingCaptureId = 'CAP-PENDING-001';
    $completedCaptureId = 'CAP-COMPLETED-002';
    $eventId = 'WH-EVENT-PENDING-REF-001';

    $order = buildGuestOrderWithCaptures($orderId, [
        [
            'id' => $pendingCaptureId,
            'status' => 'PENDING',
            'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
        ],
        [
            'id' => $completedCaptureId,
            'status' => 'COMPLETED',
            'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
        ],
    ]);

    // Webhook event references the PENDING capture ID
    $payload = buildCaptureCompletedWebhookPayload($pendingCaptureId, $orderId, $eventId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->once()->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($order);
    app()->instance(PayPalService::class, $mockPayPal);

    $paymentServiceMock = Mockery::mock(GuestReportPaymentService::class);
    $paymentServiceMock->shouldNotReceive('handlePayPalGuestOrder');
    app()->instance(GuestReportPaymentService::class, $paymentServiceMock);

    expect(fn () => $this->withoutExceptionHandling()->postJson(route('paypal.webhook'), $payload))
        ->toThrow(RuntimeException::class, 'PayPal webhook capture does not match the order capture.');

    $processedEvent = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($processedEvent)->not->toBeNull()
        ->and($processedEvent->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($processedEvent->error_message)->toContain('PayPal webhook capture does not match the order capture.');

    Queue::assertNotPushed(ProcessVehicleCheck::class);
    Queue::assertNotPushed(SendTelegramSaleNotification::class);
});

test('PAYMENT.CAPTURE.COMPLETED webhook rejects order with only PENDING captures and executes no settlement or notifications', function () {
    Queue::fake();

    $orderId = 'ORDER-NO-COMPLETED-001';
    $pendingCaptureId = 'CAP-ONLY-PENDING-001';
    $eventId = 'WH-EVENT-ONLY-PENDING-001';

    $order = buildGuestOrderWithCaptures($orderId, [
        [
            'id' => $pendingCaptureId,
            'status' => 'PENDING',
            'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
        ],
    ]);

    $payload = buildCaptureCompletedWebhookPayload($pendingCaptureId, $orderId, $eventId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->once()->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($order);
    app()->instance(PayPalService::class, $mockPayPal);

    $paymentServiceMock = Mockery::mock(GuestReportPaymentService::class);
    $paymentServiceMock->shouldNotReceive('handlePayPalGuestOrder');
    app()->instance(GuestReportPaymentService::class, $paymentServiceMock);

    expect(fn () => $this->withoutExceptionHandling()->postJson(route('paypal.webhook'), $payload))
        ->toThrow(RuntimeException::class, 'PayPal webhook capture does not match the order capture.');

    $processedEvent = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($processedEvent)->not->toBeNull()
        ->and($processedEvent->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($processedEvent->error_message)->toContain('PayPal webhook capture does not match the order capture.');

    Queue::assertNotPushed(ProcessVehicleCheck::class);
    Queue::assertNotPushed(SendTelegramSaleNotification::class);
});

test('PAYMENT.CAPTURE.COMPLETED webhook rejects order with empty captures array and executes no settlement or notifications', function () {
    Queue::fake();

    $orderId = 'ORDER-EMPTY-CAPS-001';
    $eventId = 'WH-EVENT-EMPTY-CAPS-001';

    $order = buildGuestOrderWithCaptures($orderId, []);

    $payload = buildCaptureCompletedWebhookPayload('CAP-SOME-ID-001', $orderId, $eventId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->once()->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($order);
    app()->instance(PayPalService::class, $mockPayPal);

    $paymentServiceMock = Mockery::mock(GuestReportPaymentService::class);
    $paymentServiceMock->shouldNotReceive('handlePayPalGuestOrder');
    app()->instance(GuestReportPaymentService::class, $paymentServiceMock);

    expect(fn () => $this->withoutExceptionHandling()->postJson(route('paypal.webhook'), $payload))
        ->toThrow(RuntimeException::class, 'PayPal webhook capture does not match the order capture.');

    $processedEvent = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($processedEvent)->not->toBeNull()
        ->and($processedEvent->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($processedEvent->error_message)->toContain('PayPal webhook capture does not match the order capture.');

    Queue::assertNotPushed(ProcessVehicleCheck::class);
    Queue::assertNotPushed(SendTelegramSaleNotification::class);
});

test('PAYMENT.CAPTURE.COMPLETED webhook accepts matching single COMPLETED capture and executes settlement exactly once', function () {
    $orderId = 'ORDER-SINGLE-CAP-001';
    $completedCaptureId = 'CAP-COMPLETED-001';
    $eventId = 'WH-EVENT-SINGLE-001';

    $order = buildGuestOrderWithCaptures($orderId, [
        [
            'id' => $completedCaptureId,
            'status' => 'COMPLETED',
            'amount' => ['value' => '28.00', 'currency_code' => 'GBP'],
        ],
    ]);

    $payload = buildCaptureCompletedWebhookPayload($completedCaptureId, $orderId, $eventId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->once()->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($order);
    app()->instance(PayPalService::class, $mockPayPal);

    $paymentServiceMock = Mockery::mock(GuestReportPaymentService::class);
    $paymentServiceMock->shouldReceive('handlePayPalGuestOrder')
        ->once()
        ->with($order)
        ->andReturn(true);
    app()->instance(GuestReportPaymentService::class, $paymentServiceMock);

    $response = $this->postJson(route('paypal.webhook'), $payload);

    $response->assertOk()->assertExactJson(['received' => true]);

    $processedEvent = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($processedEvent)->not->toBeNull()
        ->and($processedEvent->status)->toBe(ProcessedWebhookEvent::STATUS_COMPLETED);
});
