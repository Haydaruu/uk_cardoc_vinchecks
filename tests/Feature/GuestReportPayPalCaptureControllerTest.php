<?php

use App\Exceptions\PayPalApiException;
use App\Exceptions\PayPalGuestOrderMismatchException;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\GuestReportCheckoutService;
use App\Services\GuestReportPaymentService;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createPayPalPurchaseForCaptureControllerTest(): array
{
    $registration = 'PPLCAP'.strtoupper(bin2hex(random_bytes(4)));

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

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'PayPal Controller Test',
        'email' => 'paypal-controller@example.com',
        'phone' => '+447000000000',
        'payment_provider' => 'paypal',
        'provider_order_id' => 'ORDER-'.$registration,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2199,
        'currency' => 'gbp',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_LOCKED,
        'fulfillment_attempts' => 0,
    ]);

    return [$purchase, $report];
}

function createPayPalOrderForCaptureControllerTest(
    GuestReportPurchase $purchase,
    string $status = 'COMPLETED',
): array {
    return [
        'id' => $purchase->provider_order_id,
        'intent' => 'CAPTURE',
        'status' => $status,
        'purchase_units' => [
            [
                'custom_id' => sprintf(
                    'guest-report|%s|%d',
                    $purchase->checkout_reference,
                    $purchase->report_id,
                ),
                'invoice_id' => 'UKC-GR-'.str_replace(
                    '-',
                    '',
                    strtoupper((string) $purchase->checkout_reference),
                ),
                'payments' => [
                    'captures' => [
                        [
                            'id' => 'CAPTURE-'.$purchase->id,
                            'status' => 'COMPLETED',
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

test('paypal capture endpoint captures approved order and verifies completed order', function () {
    [$purchase, $report] = createPayPalPurchaseForCaptureControllerTest();

    $approvedOrder = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'APPROVED',
    );

    $completedOrder = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'COMPLETED',
    );

    $payPalService = Mockery::mock(PayPalService::class);

    $payPalService->shouldReceive('getOrder')
        ->once()
        ->with($purchase->provider_order_id)
        ->andReturn($approvedOrder);

    $payPalService->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->with(
            Mockery::on(
                fn ($actualPurchase) => $actualPurchase instanceof GuestReportPurchase
                    && $actualPurchase->id === $purchase->id
            ),
            $approvedOrder,
        )
        ->andReturn($approvedOrder);

    $payPalService->shouldReceive('captureOrder')
        ->once()
        ->with($purchase->provider_order_id, Mockery::any())
        ->andReturn($completedOrder);

    $paymentService = Mockery::mock(GuestReportPaymentService::class);

    $paymentService->shouldReceive('handlePayPalGuestOrder')
        ->once()
        ->with($completedOrder)
        ->andReturn(true);

    $guestToken = str_repeat('a', 64);

    $checkoutService = Mockery::mock(GuestReportCheckoutService::class);

    $checkoutService->shouldReceive('guestAccessToken')
        ->once()
        ->with(Mockery::on(
            fn ($value) => $value instanceof GuestReportPurchase
                && $value->id === $purchase->id
        ))
        ->andReturn($guestToken);

    $this->instance(PayPalService::class, $payPalService);
    $this->instance(GuestReportPaymentService::class, $paymentService);
    $this->instance(GuestReportCheckoutService::class, $checkoutService);

    $response = $this->postJson(
        route('guest-report.paypal.capture', ['report' => $report->id]),
        ['order_id' => $purchase->provider_order_id],
    );

    $expectedRedirect = route('guest-report.checkout.success', [
        'order_id' => $purchase->provider_order_id,
        'guest_token' => $guestToken,
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'success',
            'redirect' => $expectedRedirect,
        ]);
});

test('paypal capture endpoint does not capture an already completed order again', function () {
    [$purchase, $report] = createPayPalPurchaseForCaptureControllerTest();

    $completedOrder = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'COMPLETED',
    );

    $payPalService = Mockery::mock(PayPalService::class);

    $payPalService->shouldReceive('getOrder')
        ->once()
        ->with($purchase->provider_order_id)
        ->andReturn($completedOrder);

    $payPalService->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->with(
            Mockery::on(
                fn ($actualPurchase) => $actualPurchase instanceof GuestReportPurchase
                    && $actualPurchase->id === $purchase->id
            ),
            $completedOrder,
        )
        ->andReturn($completedOrder);

    $payPalService->shouldNotReceive('captureOrder');

    $paymentService = Mockery::mock(GuestReportPaymentService::class);

    $paymentService->shouldReceive('handlePayPalGuestOrder')
        ->once()
        ->with($completedOrder)
        ->andReturn(true);

    $checkoutService = Mockery::mock(GuestReportCheckoutService::class);

    $checkoutService->shouldReceive('guestAccessToken')
        ->once()
        ->andReturn(str_repeat('b', 64));

    $this->instance(PayPalService::class, $payPalService);
    $this->instance(GuestReportPaymentService::class, $paymentService);
    $this->instance(GuestReportCheckoutService::class, $checkoutService);

    $this->postJson(
        route('guest-report.paypal.capture', ['report' => $report->id]),
        ['order_id' => $purchase->provider_order_id],
    )->assertOk()
        ->assertJsonPath('status', 'success');
});

test('paypal capture endpoint rejects an order that is not completed after capture', function () {
    [$purchase, $report] = createPayPalPurchaseForCaptureControllerTest();

    $approvedOrder = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'APPROVED',
    );

    $incompleteOrder = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'APPROVED',
    );
    // Remove the completed capture to simulate missing completed capture resource
    $incompleteOrder['purchase_units'][0]['payments']['captures'] = [];

    $payPalService = Mockery::mock(PayPalService::class);

    $payPalService->shouldReceive('getOrder')
        ->twice()
        ->with($purchase->provider_order_id)
        ->andReturn($approvedOrder, $incompleteOrder);

    $payPalService->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->with(
            Mockery::on(
                fn ($actualPurchase) => $actualPurchase instanceof GuestReportPurchase
                    && $actualPurchase->id === $purchase->id
            ),
            $approvedOrder,
        )
        ->andReturn($approvedOrder);

    $payPalService->shouldReceive('captureOrder')
        ->once()
        ->with($purchase->provider_order_id, Mockery::any())
        ->andReturn($incompleteOrder);

    $paymentService = Mockery::mock(GuestReportPaymentService::class);
    $paymentService->shouldNotReceive('handlePayPalGuestOrder');

    $checkoutService = Mockery::mock(GuestReportCheckoutService::class);
    $checkoutService->shouldNotReceive('guestAccessToken');

    $this->instance(PayPalService::class, $payPalService);
    $this->instance(GuestReportPaymentService::class, $paymentService);
    $this->instance(GuestReportCheckoutService::class, $checkoutService);

    $this->postJson(
        route('guest-report.paypal.capture', ['report' => $report->id]),
        ['order_id' => $purchase->provider_order_id],
    )->assertStatus(500)
        ->assertJson([
            'status' => 'failed',
            'message' => 'Payment verification failed.',
        ]);
});

test('paypal capture endpoint rejects an order with mismatched custom ID', function () {
    [$purchase, $report] = createPayPalPurchaseForCaptureControllerTest();

    $order = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'APPROVED',
    );

    $order['purchase_units'][0]['custom_id'] = 'guest-report|999|999';

    $payPalService = Mockery::mock(PayPalService::class);

    $payPalService->shouldReceive('getOrder')
        ->once()
        ->with($purchase->provider_order_id)
        ->andReturn($order);

    $payPalService->shouldNotReceive('captureOrder');

    $payPalService->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->with(
            Mockery::on(
                fn ($actualPurchase) => $actualPurchase instanceof GuestReportPurchase
                    && $actualPurchase->id === $purchase->id
            ),
            $order,
        )
        ->andThrow(
            new PayPalGuestOrderMismatchException(
                'PayPal order identifiers do not match the purchase.',
            ),
        );

    $paymentService = Mockery::mock(GuestReportPaymentService::class);
    $paymentService->shouldNotReceive('handlePayPalGuestOrder');

    $checkoutService = Mockery::mock(GuestReportCheckoutService::class);
    $checkoutService->shouldNotReceive('guestAccessToken');

    $this->instance(PayPalService::class, $payPalService);
    $this->instance(GuestReportPaymentService::class, $paymentService);
    $this->instance(GuestReportCheckoutService::class, $checkoutService);

    $this->postJson(
        route('guest-report.paypal.capture', ['report' => $report->id]),
        ['order_id' => $purchase->provider_order_id],
    )->assertForbidden()
        ->assertJson([
            'message' => 'PayPal order validation failed.',
        ]);
});

test('paypal capture endpoint rejects an order that has not been approved', function () {
    [$purchase, $report] = createPayPalPurchaseForCaptureControllerTest();

    $createdOrder = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'CREATED',
    );

    $payPalService = Mockery::mock(PayPalService::class);

    $payPalService->shouldReceive('getOrder')
        ->once()
        ->with($purchase->provider_order_id)
        ->andReturn($createdOrder);

    $payPalService->shouldReceive('prepareGuestReportOrderForCapture')
        ->once()
        ->with(
            Mockery::on(
                fn ($actualPurchase) => $actualPurchase instanceof GuestReportPurchase
                    && $actualPurchase->id === $purchase->id
            ),
            $createdOrder,
        )
        ->andReturn($createdOrder);

    $paymentService = Mockery::mock(GuestReportPaymentService::class);
    $paymentService->shouldNotReceive('handlePayPalGuestOrder');

    $checkoutService = Mockery::mock(GuestReportCheckoutService::class);
    $checkoutService->shouldNotReceive('guestAccessToken');

    $this->instance(PayPalService::class, $payPalService);
    $this->instance(GuestReportPaymentService::class, $paymentService);
    $this->instance(GuestReportCheckoutService::class, $checkoutService);

    $this->postJson(
        route('guest-report.paypal.capture', ['report' => $report->id]),
        ['order_id' => $purchase->provider_order_id],
    )->assertUnprocessable()
        ->assertJson([
            'status' => 'failed',
            'message' => 'PayPal order has not been approved yet.',
        ]);
});

test('paypal capture endpoint returns payer_action_required when 3DS challenge is returned', function () {
    [$purchase, $report] = createPayPalPurchaseForCaptureControllerTest();

    $approvedOrder = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'APPROVED',
    );

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
        ->with($purchase->provider_order_id, Mockery::any())
        ->andThrow(new PayPalApiException(
            errorName: 'UNPROCESSABLE_ENTITY',
            errorMessage: 'Customer authentication required.',
            details: [
                [
                    'issue' => 'PAYER_ACTION_REQUIRED',
                    'links' => [
                        [
                            'rel' => 'payer-action',
                            'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=AUTH-CHALLENGE',
                        ],
                    ],
                ],
            ],
            statusCode: 422,
        ));

    $this->instance(PayPalService::class, $payPalService);

    $response = $this->postJson(
        route('guest-report.paypal.capture', ['report' => $report->id]),
        ['order_id' => $purchase->provider_order_id],
    );

    $response->assertOk()
        ->assertJsonPath('status', 'payer_action_required')
        ->assertJsonPath('message', 'Customer authentication required.');

    expect($response->json('payerActionUrl'))->toContain('https://www.sandbox.paypal.com/checkoutnow?token=AUTH-CHALLENGE')
        ->and($response->json('payerActionUrl'))->toContain('redirect_uri=');
});

test('paypal capture endpoint returns funding_declined on payment method decline', function () {
    [$purchase, $report] = createPayPalPurchaseForCaptureControllerTest();

    $approvedOrder = createPayPalOrderForCaptureControllerTest(
        $purchase,
        'APPROVED',
    );

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
        ->with($purchase->provider_order_id, Mockery::any())
        ->andThrow(new PayPalApiException(
            errorName: 'UNPROCESSABLE_ENTITY',
            errorMessage: 'Payment method declined.',
            details: [
                [
                    'issue' => 'INSTRUMENT_DECLINED',
                ],
            ],
            statusCode: 422,
        ));

    $this->instance(PayPalService::class, $payPalService);

    $response = $this->postJson(
        route('guest-report.paypal.capture', ['report' => $report->id]),
        ['order_id' => $purchase->provider_order_id],
    );

    $response->assertStatus(422)
        ->assertJson([
            'status' => 'funding_declined',
            'message' => 'Payment method declined. Please choose a different payment method.',
        ]);
});
