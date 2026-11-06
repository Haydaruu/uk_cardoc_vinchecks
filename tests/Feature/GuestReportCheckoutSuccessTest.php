<?php

use App\Models\GuestReportAccessToken;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\TestCase;

final class GuestReportCheckoutSuccessFakeHttpClient implements ClientInterface
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        private readonly array $response,
    ) {}

    /**
     * @var list<array{method: mixed, url: mixed}>
     */
    public array $requests = [];

    public function request(
        $method,
        $absUrl,
        $headers,
        $params,
        $hasFile,
        $apiMode = 'v1',
        $maxNetworkRetries = null,
    ): array {
        $this->requests[] = [
            'method' => $method,
            'url' => $absUrl,
        ];

        return [
            json_encode($this->response, JSON_THROW_ON_ERROR),
            200,
            [
                'Content-Type' => 'application/json',
                'request-id' => 'req_test_guest_checkout_success',
            ],
        ];
    }
}

/**
 * @return array{
 *     purchase: GuestReportPurchase,
 *     token: string
 * }
 */
function createGuestCheckoutSuccessFixture(): array
{
    $vehicle = Vehicle::create([
        'registration_number' => 'SUCCESS-TEST-001',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    $sessionId = 'cs_test_checkoutsuccess001';
    $token = bin2hex(random_bytes(32));

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'provider_order_id' => $sessionId,
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
    ]);

    GuestReportAccessToken::create([
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $token),
        'created_at' => now(),
    ]);

    return [
        'purchase' => $purchase,
        'token' => $token,
    ];
}

/**
 * @param  array<string, mixed>  $stripeResponse
 */
function requestGuestCheckoutSuccessPage(
    TestCase $test,
    GuestReportPurchase $purchase,
    string $token,
    array $stripeResponse,
): TestResponse {
    config([
        'services.stripe.secret' => 'sk_test_guest_checkout_success',
    ]);

    $previousHttpClient = ApiRequestor::httpClient();

    ApiRequestor::setHttpClient(
        new GuestReportCheckoutSuccessFakeHttpClient($stripeResponse),
    );

    try {
        return $test->get(route('guest-report.checkout.success', [
            'session_id' => $purchase->provider_order_id,
            'guest_token' => $token,
        ]));
    } finally {
        ApiRequestor::setHttpClient($previousHttpClient);
    }
}

/**
 * @return array<string, mixed>
 */
function createLegacyCheckoutSuccessSession(
    GuestReportPurchase $purchase,
): array {
    return [
        'id' => $purchase->provider_order_id,
        'object' => 'checkout.session',
        'status' => 'complete',
        'mode' => 'payment',
        'client_reference_id' => (string) $purchase->id,
        'metadata' => [
            'purpose' => 'guest_report_purchase',
            'guest_report_purchase_id' => (string) $purchase->id,
            'report_id' => (string) $purchase->report_id,
        ],
    ];
}

test('legacy Stripe numeric checkout can access the success page', function () {
    $fixture = createGuestCheckoutSuccessFixture();

    $purchase = $fixture['purchase'];

    $response = requestGuestCheckoutSuccessPage(
        $this,
        $purchase,
        $fixture['token'],
        createLegacyCheckoutSuccessSession($purchase),
    );

    $response
        ->assertOk()
        ->assertViewIs('guest-checkout-pending');
});

test('current UUID Stripe checkout can access the success page', function () {
    $fixture = createGuestCheckoutSuccessFixture();

    $purchase = $fixture['purchase'];

    $session = createLegacyCheckoutSuccessSession($purchase);

    $session['client_reference_id'] = (string) $purchase->checkout_reference;
    $session['metadata']['guest_report_reference'] =
        (string) $purchase->checkout_reference;

    $response = requestGuestCheckoutSuccessPage(
        $this,
        $purchase,
        $fixture['token'],
        $session,
    );

    $response
        ->assertOk()
        ->assertViewIs('guest-checkout-pending');
});

test('legacy success page rejects mismatched numeric purchase reference', function () {
    $fixture = createGuestCheckoutSuccessFixture();

    $purchase = $fixture['purchase'];

    $session = createLegacyCheckoutSuccessSession($purchase);

    $session['client_reference_id'] = (string) ($purchase->id + 999);

    $response = requestGuestCheckoutSuccessPage(
        $this,
        $purchase,
        $fixture['token'],
        $session,
    );

    $response->assertForbidden();
});

test('success page rejects malformed UUID instead of accepting legacy fallback', function () {
    $fixture = createGuestCheckoutSuccessFixture();

    $purchase = $fixture['purchase'];

    $session = createLegacyCheckoutSuccessSession($purchase);

    $session['metadata']['guest_report_reference'] = 'not-a-valid-uuid';

    $response = requestGuestCheckoutSuccessPage(
        $this,
        $purchase,
        $fixture['token'],
        $session,
    );

    $response->assertForbidden();
});

test('success page rejects an invalid guest access token', function () {
    $fixture = createGuestCheckoutSuccessFixture();

    $purchase = $fixture['purchase'];

    $response = requestGuestCheckoutSuccessPage(
        $this,
        $purchase,
        str_repeat('a', 64),
        createLegacyCheckoutSuccessSession($purchase),
    );

    $response->assertForbidden();
});

test('success page rejects a Stripe session ID that differs from the requested session', function () {
    $fixture = createGuestCheckoutSuccessFixture();

    $purchase = $fixture['purchase'];

    $session = createLegacyCheckoutSuccessSession($purchase);
    $session['id'] = 'cs_test_anothersession001';

    $response = requestGuestCheckoutSuccessPage(
        $this,
        $purchase,
        $fixture['token'],
        $session,
    );

    $response->assertForbidden();
});

test('legacy success page rejects mismatched report metadata', function () {
    $fixture = createGuestCheckoutSuccessFixture();

    $purchase = $fixture['purchase'];

    $session = createLegacyCheckoutSuccessSession($purchase);
    $session['metadata']['report_id'] = (string) ($purchase->report_id + 999);

    $response = requestGuestCheckoutSuccessPage(
        $this,
        $purchase,
        $fixture['token'],
        $session,
    );

    $response->assertForbidden();
});
