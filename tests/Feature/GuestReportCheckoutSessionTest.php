<?php

use App\Models\GuestReportAccessToken;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\GuestReportCheckoutService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

function guestCheckoutSessionToken(string $url): string
{
    $query = parse_url($url, PHP_URL_QUERY);

    parse_str(
        is_string($query) ? $query : '',
        $params,
    );

    $token = $params['guest_token'] ?? null;

    if (
        ! is_string($token)
        || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
    ) {
        throw new RuntimeException(
            'The Checkout success URL does not contain a valid guest token.'
        );
    }

    return $token;
}

test('guest checkout creates a Stripe session and persists the matching access token', function () {
    config([
        'services.stripe.secret' => 'sk_test_fake_key',
        'services.stripe.guest_report_price_id' => 'price_guest_test_001',
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => 'GUEST-STRIPE-TEST-001',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-STRIPE-TEST-001',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    $fakePrice = [
        'id' => 'price_guest_test_001',
        'object' => 'price',
        'active' => true,
        'currency' => 'gbp',
        'livemode' => false,
        'product' => 'prod_guest_test_001',
        'type' => 'one_time',
        'unit_amount' => 2000,
    ];

    $fakeSession = [
        'id' => 'cs_test_guest_001',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_guest_001',
        'status' => 'open',
    ];

    $responses = [
        json_encode($fakePrice),
        json_encode($fakeSession),
    ];

    $capturedRequests = [];

    $httpClient = Mockery::mock(ClientInterface::class);

    $httpClient->shouldReceive('request')
        ->twice()
        ->andReturnUsing(function (
            $method,
            $absUrl,
            $headers,
            $params,
            $hasFile,
            $apiMode = 'v1',
            $maxNetworkRetries = null,
        ) use (&$responses, &$capturedRequests) {
            $capturedRequests[] = [
                'method' => $method,
                'url' => $absUrl,
                'params' => $params,
            ];

            return [
                array_shift($responses),
                200,
                [
                    'Request-Id' => 'req_test_checkout',
                    'Content-Type' => 'application/json',
                ],
            ];
        });

    ApiRequestor::setHttpClient($httpClient);

    try {
        $result = app(GuestReportCheckoutService::class)->startCheckout(
            report: $report,
            email: 'guest@example.com',
            successUrl: "https://example.com/reports/{$report->id}?checkout=return",
            cancelUrl: "https://example.com/reports/{$report->id}?checkout=cancelled",
        );
    } catch (Throwable $exception) {
        $previous = $exception->getPrevious();

        $details = [
            'Caught: '.$exception::class.': '.$exception->getMessage(),
            'Previous: '.(
                $previous
                    ? $previous::class.': '.$previous->getMessage()
                    : '(none)'
            ),
        ];

        if ($previous?->getPrevious()) {
            $root = $previous->getPrevious();

            $details[] = 'Root previous: '
                .$root::class.': '.$root->getMessage();
        }

        throw new RuntimeException(
            implode(' | ', $details),
            0,
            $exception,
        );
    } finally {
        ApiRequestor::setHttpClient(null);
    }

    expect($result['checkout_url'])
        ->toBe('https://checkout.stripe.com/c/pay/cs_test_guest_001');

    expect($result['purchase'])
        ->toBeInstanceOf(GuestReportPurchase::class);

    $purchase = $result['purchase']->fresh();

    expect($purchase->provider_order_id)
        ->toBe('cs_test_guest_001');

    $token = guestCheckoutSessionToken(
        $capturedRequests[1]['params']['success_url'],
    );

    expect($token)
        ->toBeString()
        ->toMatch('/\A[a-f0-9]{64}\z/');

    $this->assertDatabaseHas('guest_report_access_tokens', [
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $token),
        'revoked_at' => null,
    ]);

    expect($capturedRequests)->toHaveCount(2);
    expect($capturedRequests[1]['method'])->toBe('post');
    expect($capturedRequests[1]['url'])
        ->toContain('/v1/checkout/sessions');

    expect($capturedRequests[1]['params']['success_url'])
        ->toContain('guest_token='.rawurlencode($token));
});

test('guest checkout reuses an open session with its existing valid access token', function () {
    config([
        'services.stripe.secret' => 'sk_test_fake_key',
        'services.stripe.guest_report_price_id' => 'price_guest_test_001',
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => 'GUEST-STRIPE-RETRY-001',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-STRIPE-RETRY-001',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    // Siapkan token yang sudah dimiliki purchase sebelumnya.
    $token = bin2hex(random_bytes(32));

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'stripe_price_id' => 'price_guest_test_001',
        'provider_order_id' => 'cs_test_guest_existing',
    ]);

    GuestReportAccessToken::create([
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $token),
        'created_at' => now(),
    ]);

    $fakePrice = [
        'id' => 'price_guest_test_001',
        'object' => 'price',
        'active' => true,
        'currency' => 'gbp',
        'livemode' => false,
        'product' => 'prod_guest_test_001',
        'type' => 'one_time',
        'unit_amount' => 2000,
    ];

    $fakeSession = [
        'id' => 'cs_test_guest_existing',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_guest_existing',
        'status' => 'open',
        'success_url' => "https://example.com/reports/{$report->id}?checkout=return&guest_token={$token}",
    ];

    $responses = [
        json_encode($fakePrice),
        json_encode($fakeSession),
    ];

    $capturedRequests = [];

    $httpClient = Mockery::mock(ClientInterface::class);

    $httpClient->shouldReceive('request')
        ->twice()
        ->andReturnUsing(function (
            $method,
            $absUrl,
            $headers,
            $params,
            $hasFile,
            $apiMode = 'v1',
            $maxNetworkRetries = null,
        ) use (&$responses, &$capturedRequests) {
            $capturedRequests[] = [
                'method' => $method,
                'url' => $absUrl,
                'params' => $params,
            ];

            return [
                array_shift($responses),
                200,
                [
                    'Request-Id' => 'req_test_checkout_retry',
                    'Content-Type' => 'application/json',
                ],
            ];
        });

    ApiRequestor::setHttpClient($httpClient);

    try {
        $result = app(GuestReportCheckoutService::class)->startCheckout(
            report: $report,
            email: 'guest@example.com',
            successUrl: "https://example.com/reports/{$report->id}?checkout=return",
            cancelUrl: "https://example.com/reports/{$report->id}?checkout=cancelled",
        );
    } finally {
        ApiRequestor::setHttpClient(null);
    }

    expect($result['checkout_url'])
        ->toBe('https://checkout.stripe.com/c/pay/cs_test_guest_existing');

    expect($result['purchase']->id)->toBe($purchase->id);

    expect($result['purchase']->provider_order_id)
        ->toBe('cs_test_guest_existing');

    expect(
        guestCheckoutSessionToken($fakeSession['success_url'])
    )->toBe($token);

    expect($capturedRequests)->toHaveCount(2);

    expect($capturedRequests[1]['method'])->toBe('get');

    expect($capturedRequests[1]['url'])
        ->toContain('/v1/checkout/sessions/cs_test_guest_existing');

    $this->assertDatabaseCount('guest_report_purchases', 1);
    $this->assertDatabaseCount('guest_report_access_tokens', 1);

    $this->assertDatabaseHas('guest_report_access_tokens', [
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $token),
        'revoked_at' => null,
    ]);
});

test('guest checkout creates a new purchase and rotates the access token when the old session is expired', function () {
    config([
        'services.stripe.secret' => 'sk_test_fake_key',
        'services.stripe.guest_report_price_id' => 'price_guest_test_001',
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => 'GUEST-STRIPE-EXPIRED-001',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-STRIPE-EXPIRED-001',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    // Purchase lama yang sesi Stripe-nya sudah expired.
    $oldToken = bin2hex(random_bytes(32));

    $oldPurchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'stripe_price_id' => 'price_guest_test_001',
        'provider_order_id' => 'cs_test_guest_expired',
    ]);

    GuestReportAccessToken::create([
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $oldToken),
        'created_at' => now(),
    ]);

    $fakePrice = [
        'id' => 'price_guest_test_001',
        'object' => 'price',
        'active' => true,
        'currency' => 'gbp',
        'livemode' => false,
        'product' => 'prod_guest_test_001',
        'type' => 'one_time',
        'unit_amount' => 2000,
    ];

    $expiredSession = [
        'id' => 'cs_test_guest_expired',
        'object' => 'checkout.session',
        'url' => null,
        'status' => 'expired',
    ];

    $newSession = [
        'id' => 'cs_test_guest_replacement',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_guest_replacement',
        'status' => 'open',
    ];

    $responses = [
        json_encode($fakePrice),
        json_encode($expiredSession),
        json_encode($newSession),
    ];

    $capturedRequests = [];

    $httpClient = Mockery::mock(ClientInterface::class);

    $httpClient->shouldReceive('request')
        ->times(3)
        ->andReturnUsing(function (
            $method,
            $absUrl,
            $headers,
            $params,
            $hasFile,
            $apiMode = 'v1',
            $maxNetworkRetries = null,
        ) use (&$responses, &$capturedRequests) {
            $capturedRequests[] = [
                'method' => $method,
                'url' => $absUrl,
                'params' => $params,
            ];

            return [
                array_shift($responses),
                200,
                [
                    'Request-Id' => 'req_test_checkout_expired',
                    'Content-Type' => 'application/json',
                ],
            ];
        });

    ApiRequestor::setHttpClient($httpClient);

    try {
        $result = app(GuestReportCheckoutService::class)->startCheckout(
            report: $report,
            email: 'guest@example.com',
            successUrl: "https://example.com/reports/{$report->id}?checkout=return",
            cancelUrl: "https://example.com/reports/{$report->id}?checkout=cancelled",
        );
    } finally {
        ApiRequestor::setHttpClient(null);
    }

    // Checkout harus memakai sesi baru.
    expect($result['checkout_url'])
        ->toBe('https://checkout.stripe.com/c/pay/cs_test_guest_replacement');

    $newPurchase = $result['purchase']->fresh();

    expect($newPurchase->id)->not->toBe($oldPurchase->id);

    expect($newPurchase->provider_order_id)
        ->toBe('cs_test_guest_replacement');

    expect($newPurchase->payment_status)
        ->toBe(GuestReportPurchase::STATUS_PENDING);

    // Purchase lama tetap tercatat, tetapi statusnya expired.
    expect($oldPurchase->fresh()->payment_status)
        ->toBe(GuestReportPurchase::STATUS_EXPIRED);

    expect($oldPurchase->fresh()->expired_at)->not->toBeNull();

    // Purchase baru harus menggunakan token berbeda.
    $newToken = guestCheckoutSessionToken(
        $capturedRequests[2]['params']['success_url'],
    );
    expect($newToken)
        ->toBeString()
        ->toMatch('/\A[a-f0-9]{64}\z/');

    expect($newToken)->not->toBe($oldToken);

    // Token lama dicabut dan token baru aktif.
    $this->assertDatabaseHas('guest_report_access_tokens', [
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $oldToken),
    ]);

    $this->assertDatabaseMissing('guest_report_access_tokens', [
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $oldToken),
        'revoked_at' => null,
    ]);

    $this->assertDatabaseHas('guest_report_access_tokens', [
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $newToken),
        'revoked_at' => null,
    ]);

    // Dua purchase tersimpan: lama dan penggantinya.
    $this->assertDatabaseCount('guest_report_purchases', 2);

    // Dua rekaman token tetap ada, tetapi hanya token baru yang aktif.
    $this->assertDatabaseCount('guest_report_access_tokens', 2);

    // Permintaan Stripe: retrieve price, retrieve sesi lama, create sesi baru.
    expect($capturedRequests)->toHaveCount(3);

    expect($capturedRequests[1]['url'])
        ->toContain('/v1/checkout/sessions/cs_test_guest_expired');

    expect($capturedRequests[2]['method'])->toBe('post');

    expect($capturedRequests[2]['url'])
        ->toContain('/v1/checkout/sessions');

    // Sesi pengganti harus menunjuk purchase dan token baru.
    expect($capturedRequests[2]['params']['client_reference_id'])
        ->toBe((string) $newPurchase->id);

    expect($capturedRequests[2]['params']['metadata']['guest_report_purchase_id'])
        ->toBe((string) $newPurchase->id);

    expect($capturedRequests[2]['params']['success_url'])
        ->toContain('guest_token='.rawurlencode($newToken));
});

test('guest checkout replaces an open session when its access token is invalid', function () {
    config([
        'services.stripe.secret' => 'sk_test_fake_key',
        'services.stripe.guest_report_price_id' => 'price_guest_test_001',
    ]);

    $vinCheck = VinCheck::create([
        'registration_number' => 'GUEST-STRIPE-INVALID-001',
        'check_type' => 'free',
        'status' => 'success',
        'stage' => 'completed',
    ]);

    $vehicle = Vehicle::create([
        'registration_number' => 'GUEST-STRIPE-INVALID-001',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'vin_check_id' => $vinCheck->id,
        'report_type' => 'basic',
    ]);

    $oldToken = bin2hex(random_bytes(32));
    $invalidSessionToken = str_repeat('b', 64);

    $oldPurchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'email' => 'guest@example.com',
        'payment_provider' => 'stripe',
        'payment_status' => GuestReportPurchase::STATUS_PENDING,
        'amount_minor' => 2000,
        'currency' => 'gbp',
        'stripe_price_id' => 'price_guest_test_001',
        'provider_order_id' => 'cs_test_guest_open_invalid',
    ]);

    GuestReportAccessToken::create([
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $oldToken),
        'created_at' => now(),
    ]);

    $fakePrice = [
        'id' => 'price_guest_test_001',
        'object' => 'price',
        'active' => true,
        'currency' => 'gbp',
        'livemode' => false,
        'product' => 'prod_guest_test_001',
        'type' => 'one_time',
        'unit_amount' => 2000,
    ];

    // Sesi lama masih open, tetapi token pada URL-nya tidak terdaftar.
    $oldSession = [
        'id' => 'cs_test_guest_open_invalid',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_guest_open_invalid',
        'status' => 'open',
        'success_url' => "https://example.com/reports/{$report->id}?checkout=return&guest_token={$invalidSessionToken}",
    ];

    $expiredSession = [
        'id' => 'cs_test_guest_open_invalid',
        'object' => 'checkout.session',
        'status' => 'expired',
    ];

    $newSession = [
        'id' => 'cs_test_guest_open_replacement',
        'object' => 'checkout.session',
        'url' => 'https://checkout.stripe.com/c/pay/cs_test_guest_open_replacement',
        'status' => 'open',
    ];

    $responses = [
        json_encode($fakePrice),
        json_encode($oldSession),
        json_encode($expiredSession),
        json_encode($newSession),
    ];

    $capturedRequests = [];

    $httpClient = Mockery::mock(ClientInterface::class);

    $httpClient->shouldReceive('request')
        ->times(4)
        ->andReturnUsing(function (
            $method,
            $absUrl,
            $headers,
            $params,
            $hasFile,
            $apiMode = 'v1',
            $maxNetworkRetries = null,
        ) use (&$responses, &$capturedRequests) {
            $capturedRequests[] = [
                'method' => $method,
                'url' => $absUrl,
                'params' => $params,
            ];

            return [
                array_shift($responses),
                200,
                [
                    'Request-Id' => 'req_test_checkout_invalid_token',
                    'Content-Type' => 'application/json',
                ],
            ];
        });

    ApiRequestor::setHttpClient($httpClient);

    try {
        $result = app(GuestReportCheckoutService::class)->startCheckout(
            report: $report,
            email: 'guest@example.com',
            successUrl: "https://example.com/reports/{$report->id}?checkout=return",
            cancelUrl: "https://example.com/reports/{$report->id}?checkout=cancelled",
        );
    } finally {
        ApiRequestor::setHttpClient(null);
    }

    // Checkout harus menghasilkan URL sesi baru.
    expect($result['checkout_url'])
        ->toBe('https://checkout.stripe.com/c/pay/cs_test_guest_open_replacement');

    $newPurchase = $result['purchase']->fresh();

    expect($newPurchase->id)->not->toBe($oldPurchase->id);

    expect($newPurchase->provider_order_id)
        ->toBe('cs_test_guest_open_replacement');

    expect($newPurchase->payment_status)
        ->toBe(GuestReportPurchase::STATUS_PENDING);

    // Purchase lama dan sesi lamanya sudah ditandai expired.
    expect($oldPurchase->fresh()->payment_status)
        ->toBe(GuestReportPurchase::STATUS_EXPIRED);

    expect($oldPurchase->fresh()->expired_at)->not->toBeNull();

    // Purchase baru harus memiliki token baru.
    $newToken = guestCheckoutSessionToken(
        $capturedRequests[3]['params']['success_url'],
    );

    expect($newToken)
        ->toBeString()
        ->toMatch('/\A[a-f0-9]{64}\z/');

    expect($newToken)->not->toBe($oldToken);
    expect($newToken)->not->toBe($invalidSessionToken);

    // Token lama dicabut; token yang baru tetap aktif.
    $this->assertDatabaseMissing('guest_report_access_tokens', [
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $oldToken),
        'revoked_at' => null,
    ]);

    $this->assertDatabaseHas('guest_report_access_tokens', [
        'guest_report_id' => $report->id,
        'token_hash' => hash('sha256', $newToken),
        'revoked_at' => null,
    ]);

    $this->assertDatabaseCount('guest_report_purchases', 2);
    $this->assertDatabaseCount('guest_report_access_tokens', 2);

    // Urutan permintaan: retrieve price, retrieve sesi,
    // expire sesi lama, kemudian create sesi baru.
    expect($capturedRequests)->toHaveCount(4);

    expect($capturedRequests[1]['method'])->toBe('get');

    expect($capturedRequests[1]['url'])
        ->toContain('/v1/checkout/sessions/cs_test_guest_open_invalid');

    expect($capturedRequests[2]['method'])->toBe('post');

    expect($capturedRequests[2]['url'])
        ->toContain('/v1/checkout/sessions/cs_test_guest_open_invalid/expire');

    expect($capturedRequests[3]['method'])->toBe('post');

    expect($capturedRequests[3]['url'])
        ->toContain('/v1/checkout/sessions');

    expect($capturedRequests[3]['params']['client_reference_id'])
        ->toBe((string) $newPurchase->id);

    expect($capturedRequests[3]['params']['metadata']['guest_report_purchase_id'])
        ->toBe((string) $newPurchase->id);

    expect($capturedRequests[3]['params']['success_url'])
        ->toContain('guest_token='.rawurlencode($newToken));
});
