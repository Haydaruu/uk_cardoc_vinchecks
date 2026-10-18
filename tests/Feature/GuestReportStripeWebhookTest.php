<?php

use App\Services\GuestReportPaymentService;
use Stripe\WebhookSignature;

function guestReportStripeWebhookPayload(
    array $session,
    string $type = 'checkout.session.completed'
): string {
    return json_encode([
        'id' => 'evt_test_guest_report',
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'data' => [
            'object' => $session,
        ],
        'livemode' => false,
        'pending_webhooks' => 1,
        'request' => null,
        'type' => $type,
    ], JSON_THROW_ON_ERROR);
}

function sendSignedGuestReportWebhook(
    $test,
    string $payload,
    string $secret,
    ?string $signature = null
) {
    $signature ??= WebhookSignature::generateSignatureHeader(
        $payload,
        $secret
    );

    return $test->call(
        'POST',
        route('stripe.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ],
        $payload
    );
}

it('routes guest report checkout events to the guest payment service', function () {
    $secret = 'whsec_test_guest_report';
    config(['services.stripe.webhook_secret' => $secret]);

    $payload = guestReportStripeWebhookPayload([
        'id' => 'cs_test_guest_report',
        'object' => 'checkout.session',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'payment_intent' => 'pi_test_guest_report',
        'metadata' => [
            'purpose' => 'guest_report_purchase',
            'guest_report_purchase_id' => '1',
            'report_id' => '1',
        ],
    ]);

    $paymentService = Mockery::mock(GuestReportPaymentService::class);

    $paymentService
        ->shouldReceive('handleCheckoutSession')
        ->once()
        ->with(
            Mockery::on(fn ($session) =>
                ($session->id ?? null) === 'cs_test_guest_report'
                && ($session->metadata->purpose ?? null)
                    === 'guest_report_purchase'
            ),
            'checkout.session.completed'
        )
        ->andReturn(true);

    $this->app->instance(
        GuestReportPaymentService::class,
        $paymentService
    );

    $response = sendSignedGuestReportWebhook(
        $this,
        $payload,
        $secret
    );

    $response
        ->assertOk()
        ->assertExactJson(['received' => true]);
});

it('rejects guest webhook events with an invalid signature', function () {
    $secret = 'whsec_test_guest_report';
    config(['services.stripe.webhook_secret' => $secret]);

    $payload = guestReportStripeWebhookPayload([
        'id' => 'cs_test_guest_report',
        'object' => 'checkout.session',
        'mode' => 'payment',
        'payment_status' => 'paid',
        'metadata' => [
            'purpose' => 'guest_report_purchase',
            'guest_report_purchase_id' => '1',
            'report_id' => '1',
        ],
    ]);

    $paymentService = Mockery::mock(GuestReportPaymentService::class);

    $paymentService
        ->shouldNotReceive('handleCheckoutSession');

    $this->app->instance(
        GuestReportPaymentService::class,
        $paymentService
    );

    $response = sendSignedGuestReportWebhook(
        $this,
        $payload,
        $secret,
        't=' . time() . ',v1=invalid_signature'
    );

    $response
        ->assertStatus(400)
        ->assertExactJson(['error' => 'invalid signature']);
});

it('does not send unrelated checkout sessions to the guest payment service', function () {
    $secret = 'whsec_test_guest_report';
    config(['services.stripe.webhook_secret' => $secret]);

    $payload = guestReportStripeWebhookPayload([
        'id' => 'cs_test_existing_checkout',
        'object' => 'checkout.session',
        'mode' => 'subscription',
        'payment_status' => 'paid',
        'subscription' => 'sub_test_existing',
        'metadata' => [],
    ]);

    $paymentService = Mockery::mock(GuestReportPaymentService::class);

    $paymentService
        ->shouldNotReceive('handleCheckoutSession');

    $this->app->instance(
        GuestReportPaymentService::class,
        $paymentService
    );

    $response = sendSignedGuestReportWebhook(
        $this,
        $payload,
        $secret
    );

    $response
        ->assertOk()
        ->assertExactJson(['received' => true]);
});
