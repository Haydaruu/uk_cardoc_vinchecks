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
        ->and($result->errorMessage)->toContain("terminal status 'failed'");
});

test('it executes initial capture for new purchase, creates attempt #1, settles, and dispatches job', function () {
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
        ->and($result->errorMessage)->toContain('Payment method declined');

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
