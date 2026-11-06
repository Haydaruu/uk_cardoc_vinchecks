
<?php

use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.paypal.mode' => 'sandbox',
        'services.paypal.client_id' => 'test-client-id',
        'services.paypal.client_secret' => 'test-client-secret',
    ]);

    Cache::forget('paypal_access_token_sandbox');
});

function makePayPalGuestOrderIdentityTestPurchase(
    ?string $orderId = null,
): GuestReportPurchase {
    $registration = 'PPLUUID'.strtoupper(bin2hex(random_bytes(4)));

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

    return GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'PayPal UUID Test',
        'email' => 'paypal-uuid@example.com',
        'phone' => '+447000000000',
        'payment_provider' => 'paypal',
        'provider_order_id' => $orderId,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2199,
        'currency' => 'gbp',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_LOCKED,
        'fulfillment_attempts' => 0,
    ]);
}

function makeLegacyPayPalGuestOrderIdentityTest(
    GuestReportPurchase $purchase,
    string $status = 'APPROVED',
    string $amount = '21.99',
): array {
    return [
        'id' => $purchase->provider_order_id,
        'intent' => 'CAPTURE',
        'status' => $status,
        'purchase_units' => [
            [
                'reference_id' => (string) $purchase->id,
                'custom_id' => sprintf(
                    'guest-report|%d|%d',
                    $purchase->id,
                    $purchase->report_id,
                ),
                'invoice_id' => 'UKC-GR-'.$purchase->id,
                'amount' => [
                    'currency_code' => 'GBP',
                    'value' => $amount,
                ],
            ],
        ],
    ];
}

test('guest report PayPal order uses UUID identifiers and stable idempotency key', function () {
    $purchase = makePayPalGuestOrderIdentityTestPurchase();

    $orderRequests = [];

    Http::fake(function (Request $request) use (&$orderRequests) {
        if (str_ends_with($request->url(), '/v1/oauth2/token')) {
            return Http::response([
                'access_token' => 'test-paypal-token',
                'expires_in' => 3600,
            ]);
        }

        if (
            $request->method() === 'POST'
            && str_ends_with($request->url(), '/v2/checkout/orders')
        ) {
            $orderRequests[] = $request;

            return Http::response([
                'id' => 'ORDER-UUID-001',
                'status' => 'CREATED',
            ], 201);
        }

        return Http::response(['message' => 'Unexpected request'], 404);
    });

    $service = app(PayPalService::class);

    $firstOrder = $service->createGuestReportOrder($purchase);
    $secondOrder = $service->createGuestReportOrder($purchase);

    $expectedReference = (string) $purchase->checkout_reference;

    $expectedCustomId = sprintf(
        'guest-report|%s|%d',
        $expectedReference,
        $purchase->report_id,
    );

    $expectedInvoiceId = 'UKC-GR-'.str_replace(
        '-',
        '',
        strtoupper($expectedReference),
    );

    $expectedRequestId = 'guest-report-order-'.$expectedReference;

    expect($firstOrder['id'])->toBe('ORDER-UUID-001')
        ->and($secondOrder['id'])->toBe('ORDER-UUID-001')
        ->and($orderRequests)->toHaveCount(2);

    foreach ($orderRequests as $request) {
        $payload = $request->data();

        expect($request->hasHeader(
            'PayPal-Request-Id',
            $expectedRequestId,
        ))->toBeTrue();

        expect($payload['purchase_units'][0]['reference_id'])
            ->toBe((string) $purchase->id);

        expect($payload['purchase_units'][0]['custom_id'])
            ->toBe($expectedCustomId);

        expect($payload['purchase_units'][0]['invoice_id'])
            ->toBe($expectedInvoiceId);

        expect($payload['purchase_units'][0]['amount'])
            ->toBe([
                'currency_code' => 'GBP',
                'value' => '21.99',
            ]);
    }
});

test('legacy approved PayPal order is migrated and verified before capture', function () {
    $orderId = 'ORDER-LEGACY-UUID-001';

    $purchase = makePayPalGuestOrderIdentityTestPurchase($orderId);

    $legacyOrder = makeLegacyPayPalGuestOrderIdentityTest($purchase);

    $expectedCustomId = sprintf(
        'guest-report|%s|%d',
        $purchase->checkout_reference,
        $purchase->report_id,
    );

    $expectedInvoiceId = 'UKC-GR-'.str_replace(
        '-',
        '',
        strtoupper((string) $purchase->checkout_reference),
    );

    $updatedOrder = $legacyOrder;

    $updatedOrder['purchase_units'][0]['custom_id'] = $expectedCustomId;
    $updatedOrder['purchase_units'][0]['invoice_id'] = $expectedInvoiceId;

    Http::fake(function (Request $request) use ($orderId, $updatedOrder) {
        if (str_ends_with($request->url(), '/v1/oauth2/token')) {
            return Http::response([
                'access_token' => 'test-paypal-token',
                'expires_in' => 3600,
            ]);
        }

        if (
            $request->method() === 'PATCH'
            && str_ends_with($request->url(), "/v2/checkout/orders/{$orderId}")
        ) {
            return Http::response([], 204);
        }

        if (
            $request->method() === 'GET'
            && str_ends_with($request->url(), "/v2/checkout/orders/{$orderId}")
        ) {
            return Http::response($updatedOrder, 200);
        }

        return Http::response(['message' => 'Unexpected request'], 404);
    });

    $result = app(PayPalService::class)
        ->prepareGuestReportOrderForCapture($purchase, $legacyOrder);

    expect(data_get($result, 'purchase_units.0.custom_id'))
        ->toBe($expectedCustomId)
        ->and(data_get($result, 'purchase_units.0.invoice_id'))
        ->toBe($expectedInvoiceId)
        ->and($result['status'])->toBe('APPROVED');

    $expectedOperations = [
        [
            'op' => 'replace',
            'path' => "/purchase_units/@reference_id=='{$purchase->id}'/custom_id",
            'value' => $expectedCustomId,
        ],
        [
            'op' => 'replace',
            'path' => "/purchase_units/@reference_id=='{$purchase->id}'/invoice_id",
            'value' => $expectedInvoiceId,
        ],
    ];

    Http::assertSent(function (Request $request) use ($orderId, $expectedOperations) {
        return $request->method() === 'PATCH'
            && str_ends_with($request->url(), "/v2/checkout/orders/{$orderId}")
            && $request->data() === $expectedOperations;
    });

    Http::assertSent(function (Request $request) use ($orderId) {
        return $request->method() === 'GET'
            && str_ends_with($request->url(), "/v2/checkout/orders/{$orderId}");
    });
});

test('already migrated PayPal order does not send an unnecessary PATCH', function () {
    $purchase = makePayPalGuestOrderIdentityTestPurchase('ORDER-UUID-READY');

    $reference = (string) $purchase->checkout_reference;

    $order = [
        'id' => $purchase->provider_order_id,
        'status' => 'APPROVED',
        'purchase_units' => [
            [
                'reference_id' => (string) $purchase->id,
                'custom_id' => sprintf(
                    'guest-report|%s|%d',
                    $reference,
                    $purchase->report_id,
                ),
                'invoice_id' => 'UKC-GR-'.str_replace(
                    '-',
                    '',
                    strtoupper($reference),
                ),
                'amount' => [
                    'currency_code' => 'GBP',
                    'value' => '21.99',
                ],
            ],
        ],
    ];

    Http::fake();

    $result = app(PayPalService::class)
        ->prepareGuestReportOrderForCapture($purchase, $order);

    expect($result)->toBe($order);

    Http::assertNothingSent();
});

test('completed legacy PayPal order is preserved without PATCH', function () {
    $purchase = makePayPalGuestOrderIdentityTestPurchase('ORDER-LEGACY-COMPLETED');

    $order = makeLegacyPayPalGuestOrderIdentityTest(
        $purchase,
        'COMPLETED',
    );

    Http::fake();

    $result = app(PayPalService::class)
        ->prepareGuestReportOrderForCapture($purchase, $order);

    expect($result)->toBe($order);

    Http::assertNothingSent();
});

test('PayPal legacy order with mismatched amount is rejected before PATCH', function () {
    $purchase = makePayPalGuestOrderIdentityTestPurchase(
        'ORDER-LEGACY-WRONG-AMOUNT',
    );

    $order = makeLegacyPayPalGuestOrderIdentityTest(
        $purchase,
        'APPROVED',
        '99.99',
    );

    Http::fake();

    expect(fn () => app(PayPalService::class)
        ->prepareGuestReportOrderForCapture($purchase, $order))
        ->toThrow(
            RuntimeException::class,
            'PayPal order amount or currency does not match the purchase.',
        );

    Http::assertNothingSent();
});
