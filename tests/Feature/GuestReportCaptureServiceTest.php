<?php

use App\Exceptions\PayPalApiException;
use App\Jobs\ProcessVehicleCheck;
use App\Models\GuestReportCaptureAttempt;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\CaptureIntent;
use App\Services\GuestReportCaptureService;
use App\Services\GuestReportCheckoutService;
use App\Services\GuestReportPaymentService;
use App\Services\PayPalService;
use App\Services\TelegramNotificationService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createCaptureTestPurchase(array $attributes = []): array
{
    $registration = 'REG'.strtoupper(Str::random(6));

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

    $purchase = GuestReportPurchase::create(array_merge([
        'report_id' => $report->id,
        'name' => 'Capture Test User',
        'email' => 'guest-'.Str::random(6).'@example.com',
        'phone' => '+447000000000',
        'payment_provider' => 'paypal',
        'provider_order_id' => 'ORDER-'.strtoupper(Str::random(10)),
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2800,
        'currency' => 'GBP',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_LOCKED,
        'fulfillment_attempts' => 0,
    ], $attributes));

    return [$purchase, $report];
}

function buildFakePayPalOrder(
    GuestReportPurchase $purchase,
    string $orderStatus = 'COMPLETED',
    string $captureStatus = 'COMPLETED',
    ?string $captureId = null,
): array {
    $captureId = $captureId ?? 'CAP-'.Str::random(10);
    $amountValue = number_format($purchase->amount_minor / 100, 2, '.', '');
    $currency = strtoupper($purchase->currency);

    $customId = sprintf('guest-report|%s|%d', $purchase->checkout_reference, $purchase->report_id);
    $invoiceId = 'UKC-GR-'.str_replace('-', '', strtoupper((string) $purchase->checkout_reference));

    $payments = [];
    if (in_array($orderStatus, ['COMPLETED', 'APPROVED'], true)) {
        $payments['captures'] = [
            [
                'id' => $captureId,
                'status' => $captureStatus,
                'amount' => [
                    'value' => $amountValue,
                    'currency_code' => $currency,
                ],
            ],
        ];
    }

    return [
        'id' => $purchase->provider_order_id,
        'status' => $orderStatus,
        'purchase_units' => [
            [
                'reference_id' => (string) $purchase->id,
                'custom_id' => $customId,
                'invoice_id' => $invoiceId,
                'amount' => [
                    'value' => $amountValue,
                    'currency_code' => $currency,
                ],
                'payments' => $payments,
            ],
        ],
    ];
}

test('it handles lock timeout by returning processing result without calling provider', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    // Simulate lock already acquired
    $lock = Cache::lock("paypal-order-capture:{$orderId}", 85);
    $lock->acquire();

    try {
        $service = app(GuestReportCaptureService::class);
        $result = $service->captureAndFulfill($orderId);

        expect($result->isProcessing())->toBeTrue()
            ->and($result->status)->toBe('processing')
            ->and($result->httpStatus)->toBe(202)
            ->and($result->errorMessage)->toContain('currently being processed');
    } finally {
        $lock->release();
    }
});

test('it immediately returns completed result if purchase is already paid and completed', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-ALREADY-SETTLED',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
    ]);

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldNotReceive('getOrder');
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($purchase->provider_order_id);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->redirect)->toContain('/guest-checkout/success')
        ->and($result->reconciled)->toBeTrue()
        ->and($result->httpStatus)->toBe(200);
});

test('it rejects terminal purchase payment statuses with 422', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::PAYMENT_STATUS_FAILED,
    ]);

    $service = app(GuestReportCaptureService::class);
    $result = $service->captureAndFulfill($purchase->provider_order_id);

    expect($result->isFailed())->toBeTrue()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain("terminal status 'failed'")
        ->and($result->isTerminalFailure())->toBeTrue();
});

test('it executes initial capture for new purchase, creates attempt #1, settles, and dispatches job', function () {
    // Configure an async driver so enqueueVehicleCheck() reaches ProcessVehicleCheck::dispatch().
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'COMPLETED', 'CAP-NEW-123');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-NEW-123');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andReturn($approvedOrder);

    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, Mockery::on(fn ($key) => str_starts_with($key, 'guest-cap-')))
        ->andReturn($completedOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId, CaptureIntent::INITIAL_BUYER_CAPTURE);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->httpStatus)->toBe(200)
        ->and($result->redirect)->toContain('/guest-checkout/success');

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->capture_attempts)->toBe(1)
        ->and($purchase->active_capture_attempt_uuid)->not->toBeNull()
        ->and($purchase->provider_payment_id)->toBe('CAP-NEW-123');

    $attempt = GuestReportCaptureAttempt::where('attempt_uuid', $purchase->active_capture_attempt_uuid)->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->attempt_number)->toBe(1)
        ->and($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED)
        ->and($attempt->idempotency_key)->toBe("guest-cap-{$attempt->attempt_uuid}");

    Queue::assertPushed(ProcessVehicleCheck::class);
});

test('it settles without POST capture if GET order confirms provider status is already COMPLETED', function () {
    Queue::fake();

    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-ALREADY-DONE');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andReturn($completedOrder);

    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturn($completedOrder);

    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isCompleted())->toBeTrue();
    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAP-ALREADY-DONE');
});

test('it handles ORDER_ALREADY_CAPTURED exception by retrieving order and settling successfully', function () {
    Queue::fake();

    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-RECOVERED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andReturn($approvedOrder);

    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->andThrow(new PayPalApiException(
            errorName: 'UNPROCESSABLE_ENTITY',
            errorMessage: 'Order is already captured.',
            details: [['issue' => 'ORDER_ALREADY_CAPTURED']],
            statusCode: 422,
        ));

    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andReturn($completedOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isCompleted())->toBeTrue();
    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAP-RECOVERED');
});

test('it handles INSTRUMENT_DECLINED by transitioning to funding_declined and allowing buyer retry', function () {
    Queue::fake();

    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->andThrow(new PayPalApiException(
            errorName: 'INSTRUMENT_DECLINED',
            errorMessage: 'Card declined.',
            details: [['issue' => 'INSTRUMENT_DECLINED']],
            statusCode: 400,
        ));

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isFundingDeclined())->toBeTrue()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain('Payment method declined')
        ->and($result->isTerminalFailure())->toBeTrue();

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_FUNDING_DECLINED)
        ->and($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING);

    $attempt = GuestReportCaptureAttempt::where('attempt_uuid', $purchase->active_capture_attempt_uuid)->first();
    expect($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_FUNDING_DECLINED);

    Queue::assertNothingPushed();
});

test('explicit funding retry creates attempt #2 with new key only when purchase is in funding_declined', function () {
    Queue::fake();

    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_FUNDING_DECLINED,
        'capture_attempts' => 1,
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt1 = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-first-attempt',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_FUNDING_DECLINED,
        'attempt_number' => 1,
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt1->attempt_uuid]);

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-RETRY-SUCCESS');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    $capturedKey = null;
    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, Mockery::on(function ($key) use (&$capturedKey, $attempt1) {
            $capturedKey = $key;

            return $key !== $attempt1->idempotency_key && str_starts_with($key, 'guest-cap-');
        }))
        ->andReturn($completedOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->retryFunding($orderId);

    expect($result->isCompleted())->toBeTrue();

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->capture_attempts)->toBe(2);

    $attempt2 = GuestReportCaptureAttempt::where('idempotency_key', $capturedKey)->first();
    expect($attempt2)->not->toBeNull()
        ->and($attempt2->attempt_number)->toBe(2)
        ->and($attempt2->status)->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);
});

test('explicit funding retry is rejected with 422 if purchase is not in funding_declined', function () {
    [$purchase] = createCaptureTestPurchase([
        'capture_status' => null, // not funding_declined
    ]);

    $service = app(GuestReportCaptureService::class);
    $result = $service->retryFunding($purchase->provider_order_id);

    expect($result->isFailed())->toBeTrue()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain('only valid after previous funding was declined');
});

test('it handles PAYER_ACTION_REQUIRED and extracts auth challenge url', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->andThrow(new PayPalApiException(
            errorName: 'UNPROCESSABLE_ENTITY',
            errorMessage: 'Customer authentication required.',
            details: [
                [
                    'issue' => 'PAYER_ACTION_REQUIRED',
                    'links' => [
                        [
                            'rel' => 'payer-action',
                            'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=CHALLENGE-TOKEN',
                        ],
                    ],
                ],
            ],
            statusCode: 422,
        ));

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isPayerActionRequired())->toBeTrue()
        ->and($result->payerActionUrl)->toContain('https://www.sandbox.paypal.com/checkoutnow?token=CHALLENGE-TOKEN');

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_PAYER_ACTION_REQUIRED);
});

test('payer-action continuation reuses existing attempt without creating a new attempt', function () {
    Queue::fake();

    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_PAYER_ACTION_REQUIRED,
        'capture_attempts' => 1,
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-challenge-key',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_PAYER_ACTION_REQUIRED,
        'attempt_number' => 1,
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-3DS-DONE');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, 'guest-cap-challenge-key')
        ->andReturn($completedOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->continuePayerAction($orderId);

    expect($result->isCompleted())->toBeTrue();
    expect(GuestReportCaptureAttempt::where('guest_report_purchase_id', $purchase->id)->count())->toBe(1);

    $attempt->refresh();
    expect($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);
});

test('capture response with status PENDING sets capture_pending and strictly does NOT mark paid or fulfill', function () {
    Queue::fake();

    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');
    $pendingCaptureOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'PENDING', 'CAP-PENDING-123');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('captureOrder')->andReturn($pendingCaptureOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isProcessing())->toBeTrue()
        ->and($result->httpStatus)->toBe(202);

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_PENDING)
        ->and($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING)
        ->and($purchase->provider_payment_id)->toBe('CAP-PENDING-123');

    $attempt = GuestReportCaptureAttempt::where('attempt_uuid', $purchase->active_capture_attempt_uuid)->first();
    expect($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_PENDING);

    // Strictly NO fulfillment dispatched
    Queue::assertNothingPushed();
});

test('subsequent calls to a purchase in capture_pending strictly execute GET-only and never POST capture', function () {
    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_PENDING,
        'provider_payment_id' => 'CAP-STILL-PENDING',
    ]);
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);

    // POST capture must NEVER be called!
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isProcessing())->toBeTrue()
        ->and($result->httpStatus)->toBe(202);
});

test('ambiguous retry replay reuses exact attempt key if within six hours', function () {
    Queue::fake();

    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'capture_request_started_at' => now()->subMinutes(15),
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-fresh-attempt-key',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
        'request_started_at' => now()->subMinutes(15),
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-REPLAYED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    // Must reuse exact key
    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, 'guest-cap-fresh-attempt-key')
        ->andReturn($completedOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->replayAmbiguousAttempt($orderId);

    expect($result->isCompleted())->toBeTrue();
});

test('ambiguous retry replay older than six hours does NOT issue POST capture and falls back to GET-only', function () {
    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'capture_request_started_at' => now()->subHours(7), // > 6h
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-stale-attempt-key',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
        'request_started_at' => now()->subHours(7),
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    // POST capture must NEVER be issued for expired attempt window!
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isProcessing())->toBeTrue();
});

test('isAttemptWithinSixHours evaluates strict 21600 second boundary and null timestamps', function () {
    $service = app(GuestReportCaptureService::class);

    // 1. Exactly 21,599 seconds ago -> within 6h (allowed)
    $attempt21599 = new GuestReportCaptureAttempt([
        'request_started_at' => now()->subSeconds(21599),
    ]);
    expect($service->isAttemptWithinSixHours($attempt21599))->toBeTrue();

    // 2. Exactly 21,600 seconds ago (exactly 6h) -> prohibited (< 21600 required)
    $attempt21600 = new GuestReportCaptureAttempt([
        'request_started_at' => now()->subSeconds(21600),
    ]);
    expect($service->isAttemptWithinSixHours($attempt21600))->toBeFalse();

    // 3. Exactly 21,601 seconds ago -> prohibited
    $attempt21601 = new GuestReportCaptureAttempt([
        'request_started_at' => now()->subSeconds(21601),
    ]);
    expect($service->isAttemptWithinSixHours($attempt21601))->toBeFalse();

    // 4. Null request_started_at -> strictly prohibited (never assumed fresh)
    $attemptNull = new GuestReportCaptureAttempt([
        'request_started_at' => null,
    ]);
    expect($service->isAttemptWithinSixHours($attemptNull))->toBeFalse();
    expect($service->isAttemptWithinSixHours(null))->toBeFalse();
});

test('ambiguous retry replay at 21599 seconds still permits POST capture with same key', function () {
    Queue::fake();

    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'capture_request_started_at' => now()->subSeconds(21599),
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-21599s-key',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
        'request_started_at' => now()->subSeconds(21599),
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-REPLAYED-21599');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, 'guest-cap-21599s-key')
        ->andReturn($completedOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->replayAmbiguousAttempt($orderId);

    expect($result->isCompleted())->toBeTrue();
});

test('ambiguous retry replay at 21600 seconds disallows POST capture and falls back to GET-only', function () {
    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'capture_request_started_at' => now()->subSeconds(21600),
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-21600s-key',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
        'request_started_at' => now()->subSeconds(21600),
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    // POST capture must NEVER be called at boundary >= 21600s
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isProcessing())->toBeTrue();
});

test('ambiguous retry replay with NULL started_at disallows POST capture and falls back to GET-only', function () {
    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'capture_request_started_at' => null,
    ]);
    $orderId = $purchase->provider_order_id;

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-null-started-key',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
        'request_started_at' => null,
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->andReturn($approvedOrder);

    // POST capture must NEVER be called when timestamp is NULL
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureAndFulfill($orderId);

    expect($result->isProcessing())->toBeTrue();
});

test('historical paid record missing proof is verified via GET-only and settled when capture proof is found', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
        'provider_payment_id' => null,
    ]);
    $orderId = $purchase->provider_order_id;

    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-FOUND-PROOF');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andReturn($completedOrder);

    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturn($completedOrder);

    // Zero POST calls
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->reconcileGetOnly($orderId);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->reconciled)->toBeTrue();

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAP-FOUND-PROOF');
});

// ─────────────────────────────────────────────────────────────
// Concurrency Hardening & Stale Response Regression Tests (§3.5.1)
// ─────────────────────────────────────────────────────────────

test('browser capture returning PENDING after concurrent settlement preserves paid and completed state', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');
    $pendingOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'PENDING', 'CAP-PENDING-001');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);

    // Concurrently settle the purchase while captureOrder is executing!
    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, Mockery::any())
        ->andReturnUsing(function () use ($purchase, $pendingOrder) {
            // Simulate concurrent webhook settling the purchase
            $purchase->update([
                'payment_status' => GuestReportPurchase::STATUS_PAID,
                'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
                'provider_payment_id' => 'CAP-CONCURRENT-SETTLED',
                'paid_at' => now(),
            ]);

            return $pendingOrder;
        });

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->status)->toBe('completed');

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAP-CONCURRENT-SETTLED');
});

test('browser capture returning terminal decline after concurrent settlement preserves paid and completed state', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, Mockery::any())
        ->andReturnUsing(function () use ($purchase) {
            // Concurrent webhook finishes and settles purchase
            $purchase->update([
                'payment_status' => GuestReportPurchase::STATUS_PAID,
                'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
                'provider_payment_id' => 'CAP-CONCURRENT-DONE',
                'paid_at' => now(),
            ]);

            // Terminal decline thrown by PayPal POST API
            throw new PayPalApiException('Declined', 422, [
                'name' => 'UNPROCESSABLE_ENTITY',
                'details' => [
                    ['issue' => 'MAX_NUMBER_OF_PAYMENT_ATTEMPTS_EXCEEDED', 'description' => 'Terminal decline'],
                ],
            ]);
        });

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->status)->toBe('completed');

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAP-CONCURRENT-DONE');
});

test('browser capture throwing generic exception after concurrent settlement preserves paid and completed state', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, Mockery::any())
        ->andReturnUsing(function () use ($purchase) {
            // Webhook completed settlement
            $purchase->update([
                'payment_status' => GuestReportPurchase::STATUS_PAID,
                'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
                'provider_payment_id' => 'CAP-WEBHOOK-WON',
                'paid_at' => now(),
            ]);

            // Network timeout / connection dropped
            throw new RuntimeException('Network connection timeout during capture.');
        });

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->status)->toBe('completed');

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAP-WEBHOOK-WON');
});

test('old attempt response arriving after newer attempt became active does not modify newer attempt or purchase', function () {
    [$purchase] = createCaptureTestPurchase([
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'capture_attempts' => 2,
    ]);
    $orderId = $purchase->provider_order_id;

    // Old attempt 1
    $oldAttempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'guest-cap-old-1',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 1,
        'request_started_at' => now()->subMinutes(5),
    ]);

    // Newer attempt 2 (active)
    $activeAttemptUuid = (string) Str::uuid();
    $activeAttempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => $activeAttemptUuid,
        'idempotency_key' => 'guest-cap-active-2',
        'provider_order_id' => $orderId,
        'status' => GuestReportCaptureAttempt::STATUS_CAPTURING,
        'attempt_number' => 2,
        'request_started_at' => now(),
    ]);

    $purchase->update([
        'active_capture_attempt_uuid' => $activeAttemptUuid,
    ]);

    $payPalMock = Mockery::mock(PayPalService::class);
    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    // Call handleCaptureResponse directly with oldAttempt and PENDING response
    $delayedPendingOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'PENDING', 'CAP-OLD-PENDING');

    $reflector = new ReflectionMethod(GuestReportCaptureService::class, 'handleCaptureResponse');
    $reflector->setAccessible(true);
    $result = $reflector->invoke($service, $delayedPendingOrder, $purchase, $oldAttempt, $orderId);

    expect($result->isProcessing())->toBeTrue();

    $purchase->refresh();
    $activeAttempt->refresh();
    $oldAttempt->refresh();

    // Purchase remains in capturing state with active attempt untouched
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_CAPTURING)
        ->and($purchase->active_capture_attempt_uuid)->toBe($activeAttemptUuid)
        ->and($purchase->provider_payment_id)->toBeNull();

    // Active attempt remains capturing
    expect($activeAttempt->status)->toBe(GuestReportCaptureAttempt::STATUS_CAPTURING);

    // Old attempt was not modified by the late response
    expect($oldAttempt->status)->toBe(GuestReportCaptureAttempt::STATUS_CAPTURING);
});

test('reconciliation proof failure arriving after concurrent settlement preserves completed state and suppresses alerts', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
        'provider_payment_id' => null,
    ]);
    $orderId = $purchase->provider_order_id;

    $payPalMock = Mockery::mock(PayPalService::class);
    // getOrder returns order without captures or not completed
    $emptyOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED');
    $emptyOrder['purchase_units'][0]['payments']['captures'] = [];

    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturnUsing(function () use ($purchase, $emptyOrder) {
        // Concurrently, another worker proved and completed the capture!
        $purchase->update([
            'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
            'provider_payment_id' => 'CAP-VERIFIED-PROOF-123',
            'last_reconciled_at' => now(),
        ]);

        return $emptyOrder;
    });

    $telegramMock = Mockery::mock(TelegramNotificationService::class);
    $telegramMock->shouldNotReceive('sendMessage');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
        $telegramMock,
    );

    $result = $service->reconcileGetOnly($orderId);

    expect($result->isCompleted())->toBeTrue();

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAP-VERIFIED-PROOF-123');
});

test('delayed provider response arriving after refund does not overwrite refunded state', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');
    $pendingOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'PENDING', 'CAP-PENDING-999');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, Mockery::any())
        ->andReturnUsing(function () use ($purchase, $pendingOrder) {
            // Purchase was refunded in the meantime
            $purchase->update([
                'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
                'refunded_at' => now(),
            ]);

            return $pendingOrder;
        });

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isFailed())->toBeTrue()
        ->and($result->httpStatus)->toBe(422);

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_REFUNDED);
});

test('normal PENDING response with no concurrent settlement transitions to capture_pending and records provider payment id', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');
    $pendingOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'PENDING', 'CAP-NORMAL-PENDING-001');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);
    $payPalMock->shouldReceive('captureOrder')->once()->with($orderId, Mockery::any())->andReturn($pendingOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isProcessing())->toBeTrue()
        ->and($result->httpStatus)->toBe(202);

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_PENDING)
        ->and($purchase->provider_payment_id)->toBe('CAP-NORMAL-PENDING-001')
        ->and($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING);

    $attempt = $purchase->captureAttempts()->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_PENDING);
});

test('purchase with REFUNDED payment status and CAPTURE_COMPLETED fails safely without success redirect in Phase 1', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-REFUNDED-ALREADY',
    ]);

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldNotReceive('getOrder');
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($purchase->provider_order_id);

    expect($result->isFailed())->toBeTrue()
        ->and($result->status)->toBe('failed')
        ->and($result->redirect)->toBeNull()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain('cannot be captured');

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_REFUNDED)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED);
});

test('purchase with inconsistent PENDING payment status and CAPTURE_COMPLETED fails safely without success redirect in Phase 1', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-INCONSISTENT-001',
    ]);

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldNotReceive('getOrder');
    $payPalMock->shouldNotReceive('captureOrder');

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($purchase->provider_order_id);

    expect($result->isFailed())->toBeTrue()
        ->and($result->status)->toBe('failed')
        ->and($result->redirect)->toBeNull()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain('Inconsistent purchase state: capture is marked completed but payment status is');

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED);
});

test('purchase with FAILED payment status and CAPTURE_COMPLETED fails safely without success redirect in Phase 1', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::PAYMENT_STATUS_FAILED,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-FAILED-INCONSISTENT',
    ]);

    $payPalMock = Mockery::mock(PayPalService::class);
    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($purchase->provider_order_id);

    expect($result->isFailed())->toBeTrue()
        ->and($result->redirect)->toBeNull()
        ->and($result->httpStatus)->toBe(422);
});

test('purchase with EXPIRED payment status and CAPTURE_COMPLETED fails safely without success redirect in Phase 1', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_EXPIRED,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-EXPIRED-INCONSISTENT',
    ]);

    $payPalMock = Mockery::mock(PayPalService::class);
    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($purchase->provider_order_id);

    expect($result->isFailed())->toBeTrue()
        ->and($result->redirect)->toBeNull()
        ->and($result->httpStatus)->toBe(422);
});

test('purchase with PAID payment status and CAPTURE_COMPLETED returns completed with success redirect in Phase 1', function () {
    [$purchase] = createCaptureTestPurchase([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-PAID-VALID',
    ]);

    $payPalMock = Mockery::mock(PayPalService::class);
    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($purchase->provider_order_id);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->redirect)->toContain('/guest-checkout/success')
        ->and($result->httpStatus)->toBe(200);
});

test('resolveProtectedStateResult ensures REFUNDED, FAILED, and EXPIRED purchases fail safely even if capture_status is completed', function () {
    [$purchase] = createCaptureTestPurchase();
    $service = app(GuestReportCaptureService::class);
    $reflector = new ReflectionMethod(GuestReportCaptureService::class, 'resolveProtectedStateResult');
    $reflector->setAccessible(true);

    // 1. REFUNDED + CAPTURE_COMPLETED
    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-REF-1',
    ]);
    /** @var GuestCaptureResult $resRefunded */
    $resRefunded = $reflector->invoke($service, $purchase->fresh());
    expect($resRefunded->isFailed())->toBeTrue()
        ->and($resRefunded->redirect)->toBeNull()
        ->and($resRefunded->httpStatus)->toBe(422)
        ->and($resRefunded->errorMessage)->toContain('refunded');

    // 2. FAILED + CAPTURE_COMPLETED
    $purchase->update([
        'payment_status' => GuestReportPurchase::PAYMENT_STATUS_FAILED,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
    ]);
    /** @var GuestCaptureResult $resFailed */
    $resFailed = $reflector->invoke($service, $purchase->fresh());
    expect($resFailed->isFailed())->toBeTrue()
        ->and($resFailed->redirect)->toBeNull()
        ->and($resFailed->httpStatus)->toBe(422)
        ->and($resFailed->errorMessage)->toContain('terminal status');

    // 3. EXPIRED + CAPTURE_COMPLETED
    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_EXPIRED,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
    ]);
    /** @var GuestCaptureResult $resExpired */
    $resExpired = $reflector->invoke($service, $purchase->fresh());
    expect($resExpired->isFailed())->toBeTrue()
        ->and($resExpired->redirect)->toBeNull()
        ->and($resExpired->httpStatus)->toBe(422)
        ->and($resExpired->errorMessage)->toContain('terminal status');

    // 4. PAID + CAPTURE_COMPLETED
    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-PAID-1',
    ]);
    /** @var GuestCaptureResult $resPaid */
    $resPaid = $reflector->invoke($service, $purchase->fresh());
    expect($resPaid->isCompleted())->toBeTrue()
        ->and($resPaid->redirect)->toContain('/guest-checkout/success')
        ->and($resPaid->httpStatus)->toBe(200);

    // 5. Inconsistent: PENDING + CAPTURE_COMPLETED
    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-PENDING-INCONSISTENT',
    ]);
    /** @var GuestCaptureResult $resInconsistent */
    $resInconsistent = $reflector->invoke($service, $purchase->fresh());
    expect($resInconsistent->isFailed())->toBeTrue()
        ->and($resInconsistent->redirect)->toBeNull()
        ->and($resInconsistent->httpStatus)->toBe(422)
        ->and($resInconsistent->errorMessage)->toContain('Purchase state is inconsistent');
});

test('provider COMPLETED response arriving after purchase has been refunded does not restore capture tracking to completed or return success', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-LATE-COMPLETED-999');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);

    $payPalMock->shouldReceive('captureOrder')
        ->once()
        ->with($orderId, Mockery::any())
        ->andReturnUsing(function () use ($purchase, $completedOrder) {
            // Concurrent refund occurs while capture request was in flight
            $purchase->update([
                'payment_status' => GuestReportPurchase::STATUS_REFUNDED,
                'refunded_at' => now(),
            ]);

            return $completedOrder;
        });

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isFailed())->toBeTrue()
        ->and($result->redirect)->toBeNull()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain('refunded');

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_REFUNDED)
        ->and($purchase->capture_status)->not->toBe(GuestReportPurchase::CAPTURE_COMPLETED);

    $attempt = $purchase->captureAttempts()->first();
    expect($attempt?->status)->not->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);
});

test('provider COMPLETED response where payment service does not settle purchase does not mark attempt or capture completed', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-FAIL-SETTLE-001');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);
    $payPalMock->shouldReceive('captureOrder')->once()->with($orderId, Mockery::any())->andReturn($completedOrder);

    $paymentMock = Mockery::mock(GuestReportPaymentService::class);
    $paymentMock->shouldReceive('handlePayPalGuestOrder')
        ->once()
        ->andReturn(false);

    $service = new GuestReportCaptureService(
        $payPalMock,
        $paymentMock,
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isFailed())->toBeTrue()
        ->and($result->redirect)->toBeNull()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain("Purchase payment status is 'pending', not paid");

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING)
        ->and($purchase->capture_status)->not->toBe(GuestReportPurchase::CAPTURE_COMPLETED);

    $attempt = $purchase->captureAttempts()->first();
    expect($attempt?->status)->not->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);
});

test('genuine successful settlement marks correct attempt and purchase completed with success redirect', function () {
    // Configure an async driver so enqueueVehicleCheck() reaches ProcessVehicleCheck::dispatch().
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-GENUINE-SUCCESS-123');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);
    $payPalMock->shouldReceive('captureOrder')->once()->with($orderId, Mockery::any())->andReturn($completedOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->redirect)->toContain('/guest-checkout/success')
        ->and($result->httpStatus)->toBe(200);

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAP-GENUINE-SUCCESS-123')
        ->and($purchase->active_capture_attempt_uuid)->not->toBeNull();

    $attempt = GuestReportCaptureAttempt::where('attempt_uuid', $purchase->active_capture_attempt_uuid)->first();

    expect($attempt)->not->toBeNull()
        ->and((int) $attempt->guest_report_purchase_id)->toBe((int) $purchase->id)
        ->and($attempt->provider_order_id)->toBe($purchase->provider_order_id)
        ->and($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);

    Queue::assertPushed(ProcessVehicleCheck::class);
});

test('capture tracking and attempt complete even if post-settlement fulfillment dispatch fails', function () {
    // Configure an async driver so the sync guard does not intercept before the Dispatcher mock.
    config(['queue.default' => 'database']);

    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $approvedOrder = buildFakePayPalOrder($purchase, 'APPROVED', 'PENDING');
    $completedOrder = buildFakePayPalOrder($purchase, 'COMPLETED', 'COMPLETED', 'CAP-ISOLATED-DISPATCH-001');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($approvedOrder);
    $payPalMock->shouldReceive('captureOrder')->once()->with($orderId, Mockery::any())->andReturn($completedOrder);

    // Mock dispatcher to fail specifically on ProcessVehicleCheck
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->andReturnUsing(function ($job) {
            if ($job instanceof ProcessVehicleCheck) {
                throw new RuntimeException('Simulated queue dispatch connection failure');
            }

            return null;
        });
    app()->instance(Dispatcher::class, $dispatcher);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->captureInitialBuyer($orderId);

    expect($result->isCompleted())->toBeTrue()
        ->and($result->status)->toBe('completed')
        ->and($result->redirect)->toContain('/guest-checkout/success')
        ->and($result->httpStatus)->toBe(200);

    $purchase->refresh();

    // Purchase is successfully paid and completed, fulfillment remains pending for future recovery
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING)
        ->and($purchase->provider_payment_id)->toBe('CAP-ISOLATED-DISPATCH-001')
        ->and($purchase->active_capture_attempt_uuid)->not->toBeNull();

    // Capture attempt was still safely updated to completed
    $attempt = GuestReportCaptureAttempt::where('attempt_uuid', $purchase->active_capture_attempt_uuid)->first();

    expect($attempt)->not->toBeNull()
        ->and((int) $attempt->guest_report_purchase_id)->toBe((int) $purchase->id)
        ->and($attempt->provider_order_id)->toBe($purchase->provider_order_id)
        ->and($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);
});

// ─────────────────────────────────────────────────────────────
// Terminal Failure Classification Tests
// ─────────────────────────────────────────────────────────────

test('unapproved CREATED order remains retryable rather than terminal', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $createdOrder = buildFakePayPalOrder($purchase, 'CREATED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($createdOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($createdOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->reconcileGetOnly($orderId);

    expect($result->isFailed())->toBeTrue()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain('has not been approved yet')
        ->and($result->isTerminalFailure())->toBeFalse();
});

test('confirmed VOIDED order is classified terminal', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $voidedOrder = buildFakePayPalOrder($purchase, 'VOIDED');

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')->once()->with($orderId)->andReturn($voidedOrder);
    $payPalMock->shouldReceive('prepareGuestReportOrderForCapture')->once()->andReturn($voidedOrder);

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->reconcileGetOnly($orderId);

    expect($result->isFailed())->toBeTrue()
        ->and($result->httpStatus)->toBe(422)
        ->and($result->errorMessage)->toContain('voided by provider')
        ->and($result->isTerminalFailure())->toBeTrue();

    $purchase->refresh();
    expect($purchase->payment_status)->toBe(GuestReportPurchase::PAYMENT_STATUS_FAILED)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_STATUS_FAILED);
});

test('provider communication failure remains retryable rather than terminal', function () {
    [$purchase] = createCaptureTestPurchase();
    $orderId = $purchase->provider_order_id;

    $payPalMock = Mockery::mock(PayPalService::class);
    $payPalMock->shouldReceive('getOrder')
        ->once()
        ->with($orderId)
        ->andThrow(new RuntimeException('Connection timed out.'));

    $service = new GuestReportCaptureService(
        $payPalMock,
        app(GuestReportPaymentService::class),
        app(GuestReportCheckoutService::class),
    );

    $result = $service->reconcileGetOnly($orderId);

    expect($result->isFailed())->toBeTrue()
        ->and($result->httpStatus)->toBe(500)
        ->and($result->errorMessage)->toContain('Unable to communicate with payment provider')
        ->and($result->isTerminalFailure())->toBeFalse();
});

test('missing purchase remains retryable so correlation recovery policy applies', function () {
    $service = app(GuestReportCaptureService::class);

    $result = $service->reconcileGetOnly('ORDER-MISSING-'.Str::random(8));

    expect($result->isFailed())->toBeTrue()
        ->and($result->httpStatus)->toBe(404)
        ->and($result->errorMessage)->toContain('purchase record not found')
        ->and($result->isTerminalFailure())->toBeFalse();
});
