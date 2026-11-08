<?php

use App\Exceptions\PayPalApiException;
use App\Models\GuestReportCaptureAttempt;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\GuestReportCheckoutService;
use App\Services\GuestReportPaymentService;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPayPalPurchaseForReturnTest(array $attributes = []): array
{
    $registration = 'PPLRET'.strtoupper(bin2hex(random_bytes(4)));

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

    $orderId = 'ORDER-'.$registration;
    $reference = (string) Str::uuid();

    $purchase = GuestReportPurchase::create(array_merge([
        'report_id' => $report->id,
        'checkout_reference' => $reference,
        'name' => 'Return Tester',
        'email' => 'return-test@example.com',
        'phone' => '+447000000111',
        'payment_provider' => 'paypal',
        'provider_order_id' => $orderId,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'capture_status' => GuestReportPurchase::CAPTURE_PAYER_ACTION_REQUIRED,
        'amount_minor' => 2199,
        'currency' => 'gbp',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_LOCKED,
        'fulfillment_attempts' => 0,
    ], $attributes));

    return [$purchase, $report];
}

function createPayPalOrderPayloadForReturnTest(GuestReportPurchase $purchase, string $orderStatus = 'APPROVED', string $captureStatus = 'COMPLETED'): array
{
    return [
        'id' => $purchase->provider_order_id,
        'intent' => 'CAPTURE',
        'status' => $orderStatus,
        'purchase_units' => [
            [
                'custom_id' => sprintf(
                    'guest-report|%s|%d',
                    $purchase->checkout_reference,
                    $purchase->report_id,
                ),
                'invoice_id' => 'UKC-GR-'.str_replace('-', '', strtoupper((string) $purchase->checkout_reference)),
                'payments' => [
                    'captures' => [
                        [
                            'id' => 'CAP-RET-'.$purchase->id,
                            'status' => $captureStatus,
                            'amount' => [
                                'value' => '21.99',
                                'currency_code' => 'GBP',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

test('valid signed return continues payer action, completes capture, and redirects to success', function () {
    [$purchase, $report] = createPayPalPurchaseForReturnTest();

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'idemp-return-test-key',
        'provider_order_id' => $purchase->provider_order_id,
        'status' => GuestReportCaptureAttempt::STATUS_PAYER_ACTION_REQUIRED,
        'attempt_number' => 1,
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $approvedOrder = createPayPalOrderPayloadForReturnTest($purchase, 'APPROVED');
    $completedOrder = createPayPalOrderPayloadForReturnTest($purchase, 'COMPLETED', 'COMPLETED');

    $payPalService = Mockery::mock(PayPalService::class);
    $payPalService->shouldReceive('getOrder')
        ->once()
        ->with($purchase->provider_order_id)
        ->andReturn($approvedOrder);

    $payPalService->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturn($approvedOrder);

    $payPalService->shouldReceive('captureOrder')
        ->once()
        ->with($purchase->provider_order_id, 'idemp-return-test-key')
        ->andReturn($completedOrder);

    $paymentService = Mockery::mock(GuestReportPaymentService::class);
    $paymentService->shouldReceive('handlePayPalGuestOrder')
        ->once()
        ->with($completedOrder)
        ->andReturn(true);

    $checkoutService = Mockery::mock(GuestReportCheckoutService::class);
    $checkoutService->shouldReceive('guestAccessToken')
        ->once()
        ->andReturn(str_repeat('c', 64));

    $this->instance(PayPalService::class, $payPalService);
    $this->instance(GuestReportPaymentService::class, $paymentService);
    $this->instance(GuestReportCheckoutService::class, $checkoutService);

    // Build the signed URL
    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    // PayPal appends token to query
    $callbackUrl = $signedUrl.'&token='.$purchase->provider_order_id;

    $response = $this->get($callbackUrl);

    $expectedRedirect = route('guest-report.checkout.success', [
        'order_id' => $purchase->provider_order_id,
        'guest_token' => str_repeat('c', 64),
    ]);

    $response->assertRedirect($expectedRedirect);

    $purchase->refresh();
    expect($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED);

    $attempt->refresh();
    expect($attempt->status)->toBe(GuestReportCaptureAttempt::STATUS_COMPLETED);
});

test('return endpoint rejects invalid or tampered signature with 403', function () {
    [$purchase] = createPayPalPurchaseForReturnTest();

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    // Tamper with order_id in query without altering signature
    $tamperedUrl = str_replace($purchase->provider_order_id, 'TAMPERED_ORDER_999', $signedUrl);

    $this->get($tamperedUrl)->assertForbidden();
});

test('return endpoint rejects expired signature with 403', function () {
    [$purchase] = createPayPalPurchaseForReturnTest();

    Carbon::setTestNow(now());

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(30),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    // Fast-forward beyond TTL
    Carbon::setTestNow(now()->addMinutes(31));

    $this->get($signedUrl)->assertForbidden();

    Carbon::setTestNow();
});

test('return endpoint rejects mismatched callback token with 403', function () {
    [$purchase] = createPayPalPurchaseForReturnTest();

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    // Valid signature, but PayPal callback token doesn't match signed order_id
    $mismatchedUrl = $signedUrl.'&token=DIFFERENT_ORDER_ID';

    $this->get($mismatchedUrl)->assertForbidden();
});

test('return endpoint rejects non-existent purchase reference with 404', function () {
    $randomUuid = (string) Str::uuid();

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => $randomUuid,
            'order_id' => 'ORDER-12345',
        ],
    );

    $this->get($signedUrl)->assertNotFound();
});

test('return endpoint rejects signed order ID that does not match purchase record with 403', function () {
    [$purchase] = createPayPalPurchaseForReturnTest();

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => 'ORDER-MISMATCHED-123',
        ],
    );

    $this->get($signedUrl)->assertForbidden();
});

test('return endpoint is idempotent when purchase is already completed', function () {
    [$purchase, $report] = createPayPalPurchaseForReturnTest([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'capture_status' => GuestReportPurchase::CAPTURE_COMPLETED,
        'provider_payment_id' => 'CAP-PREV-COMPLETED',
    ]);

    $checkoutService = Mockery::mock(GuestReportCheckoutService::class);
    $checkoutService->shouldReceive('guestAccessToken')
        ->once()
        ->andReturn(str_repeat('d', 64));

    $payPalService = Mockery::mock(PayPalService::class);
    $payPalService->shouldNotReceive('captureOrder');

    $this->instance(PayPalService::class, $payPalService);
    $this->instance(GuestReportCheckoutService::class, $checkoutService);

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    $response = $this->get($signedUrl.'&token='.$purchase->provider_order_id);

    $expectedRedirect = route('guest-report.checkout.success', [
        'order_id' => $purchase->provider_order_id,
        'guest_token' => str_repeat('d', 64),
    ]);

    $response->assertRedirect($expectedRedirect);
});

test('return endpoint redirects to checkout with error when continuation is declined', function () {
    [$purchase, $report] = createPayPalPurchaseForReturnTest();

    $attempt = GuestReportCaptureAttempt::create([
        'guest_report_purchase_id' => $purchase->id,
        'attempt_uuid' => (string) Str::uuid(),
        'idempotency_key' => 'idemp-return-decline-key',
        'provider_order_id' => $purchase->provider_order_id,
        'status' => GuestReportCaptureAttempt::STATUS_PAYER_ACTION_REQUIRED,
        'attempt_number' => 1,
    ]);
    $purchase->update(['active_capture_attempt_uuid' => $attempt->attempt_uuid]);

    $approvedOrder = createPayPalOrderPayloadForReturnTest($purchase, 'APPROVED');

    $payPalService = Mockery::mock(PayPalService::class);
    $payPalService->shouldReceive('getOrder')
        ->once()
        ->with($purchase->provider_order_id)
        ->andReturn($approvedOrder);

    $payPalService->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->andReturn($approvedOrder);

    $payPalService->shouldReceive('captureOrder')
        ->once()
        ->with($purchase->provider_order_id, 'idemp-return-decline-key')
        ->andThrow(new PayPalApiException(
            errorName: 'UNPROCESSABLE_ENTITY',
            errorMessage: 'Instrument declined',
            details: [
                ['issue' => 'INSTRUMENT_DECLINED'],
            ],
            statusCode: 422,
        ));

    $this->instance(PayPalService::class, $payPalService);

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    $response = $this->get($signedUrl.'&token='.$purchase->provider_order_id);

    $response->assertRedirect(route('guest-report.checkout.show', ['report' => $report->id]));
    $response->assertSessionHasErrors('checkout');
});

test('return endpoint rejects tampering signed reference parameter with 403', function () {
    [$purchase] = createPayPalPurchaseForReturnTest();

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    // Tamper with reference UUID in query without updating signature
    $tamperedUrl = str_replace((string) $purchase->checkout_reference, (string) Str::uuid(), $signedUrl);

    $this->get($tamperedUrl.'&token='.$purchase->provider_order_id)->assertForbidden();
});

test('return endpoint rejects arbitrary appended parameter such as opType with 403', function () {
    [$purchase] = createPayPalPurchaseForReturnTest();

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    // Appending an un-ignored parameter invalidates the signed URL HMAC
    $urlWithOpType = $signedUrl.'&token='.$purchase->provider_order_id.'&opType=payment2';

    $this->get($urlWithOpType)->assertForbidden();
});

test('return endpoint rejects appended PayerID parameter when not in signature ignore list with 403', function () {
    [$purchase] = createPayPalPurchaseForReturnTest();

    $signedUrl = URL::temporarySignedRoute(
        'guest-report.paypal.return',
        now()->addMinutes(60),
        [
            'reference' => (string) $purchase->checkout_reference,
            'order_id' => (string) $purchase->provider_order_id,
        ],
    );

    // Since PayerID is now excluded from the signature ignore list, appending it must invalidate the signature
    $urlWithPayerId = $signedUrl.'&token='.$purchase->provider_order_id.'&PayerID=PAYER12345';

    $this->get($urlWithPayerId)->assertForbidden();
});
