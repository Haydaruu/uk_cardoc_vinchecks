<?php

use App\Jobs\SendTelegramSaleNotification;
use App\Models\CreditTransaction;
use App\Models\GuestReportPurchase;
use App\Models\ProcessedWebhookEvent;
use App\Models\RefundRecord;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Stripe\WebhookSignature;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);

    $this->customer = User::factory()->create([
        'role' => 'user',
        'credits' => 5,
        'is_suspended' => false,
        'email_verified_at' => now(),
    ]);

    $this->secret = 'whsec_test_secret_123';
    config(['services.stripe.webhook_secret' => $this->secret]);
});

function createSignedStripeRequest($test, array $eventPayload, string $secret)
{
    $json = json_encode($eventPayload, JSON_THROW_ON_ERROR);
    $sig = WebhookSignature::generateSignatureHeader($json, $secret);

    return $test->call(
        'POST',
        route('stripe.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sig,
        ],
        $json
    );
}

test('stripe webhook rejects request with invalid signature', function () {
    $response = $this->call(
        'POST',
        route('stripe.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=123,v1=invalid_sig',
        ],
        json_encode(['id' => 'evt_test', 'type' => 'charge.refunded'])
    );

    $response->assertStatus(400);
    $response->assertJson(['error' => 'invalid signature']);
});

test('stripe webhook deduplicates identical event id on retry', function () {
    $eventId = 'evt_stripe_dedup_001';

    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'charge.refunded',
        'data' => [
            'object' => [
                'id' => 'ch_dedup_test',
                'payment_intent' => 'pi_unmatched_test',
                'amount' => 1999,
                'amount_refunded' => 1999,
                'currency' => 'gbp',
                'refunds' => ['data' => []],
            ],
        ],
    ];

    // First delivery
    $res1 = createSignedStripeRequest($this, $payload, $this->secret);
    $res1->assertOk()->assertJson(['received' => true]);

    expect(ProcessedWebhookEvent::where('event_id', $eventId)->count())->toBe(1);
    expect(ProcessedWebhookEvent::where('event_id', $eventId)->first()->status)->toBe('completed');

    // Second delivery (duplicate delivery from provider retry)
    $res2 = createSignedStripeRequest($this, $payload, $this->secret);
    $res2->assertOk()->assertJson(['received' => true]);

    // Should still only have 1 record and not fail
    expect(ProcessedWebhookEvent::where('event_id', $eventId)->count())->toBe(1);
});

test('stripe webhook charge.refunded reconciles unknown refund record to success', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-REC-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_rec_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    // Hold already placed
    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_rec_001',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Customer requested refund',
        'metadata' => ['credits_to_deduct' => 5],
    ]);

    $eventId = 'evt_stripe_reconcile_001';
    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'charge.refunded',
        'data' => [
            'object' => [
                'id' => 'ch_rec_001',
                'payment_intent' => 'pi_rec_001',
                'amount' => 1999,
                'amount_refunded' => 1999,
                'currency' => 'gbp',
                'refunds' => [
                    'data' => [
                        [
                            'id' => 're_confirmed_001',
                            'amount' => 1999,
                            'status' => 'succeeded',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk()->assertJson(['received' => true]);

    $record->refresh();
    $tx->refresh();

    // Reconciled to success
    expect($record->status)->toBe('success');
    expect($record->refund_gateway_ref)->toBe('re_confirmed_001');
    expect($tx->status)->toBe('refunded');
    expect($tx->refunded_at)->not->toBeNull();

    // Customer balance unchanged (hold remains committed, no double deduction)
    expect($this->customer->refresh()->credits)->toBe(5);

    // No duplicate reversal row created
    expect(CreditTransaction::where('reference_id', 'like', 'refund_reversal:%')->count())->toBe(0);
});

test('stripe webhook refund.updated with failed status reverses credit hold', function () {
    $this->customer->update(['credits' => 0]); // Balance decremented during hold

    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-FAIL-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_fail_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_fail_001',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Customer requested refund',
        'metadata' => ['credits_to_deduct' => 5],
    ]);

    $eventId = 'evt_stripe_fail_001';
    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'refund.updated',
        'data' => [
            'object' => [
                'id' => 're_failed_001',
                'charge' => 'ch_fail_001',
                'payment_intent' => 'pi_fail_001',
                'amount' => 1999,
                'currency' => 'gbp',
                'status' => 'failed',
                'failure_reason' => 'declined_by_bank',
            ],
        ],
    ];

    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk();

    $record->refresh();
    $tx->refresh();
    $this->customer->refresh();

    // Reconciled to failed
    expect($record->status)->toBe('failed');
    expect($tx->status)->toBe('success'); // Remains success (payment not returned)
    expect($this->customer->credits)->toBe(5); // Credits restored!

    // Reversal ledger row created
    $reversal = CreditTransaction::where('reference_id', "refund_reversal:{$record->id}")->first();
    expect($reversal)->not->toBeNull();
    expect($reversal->amount)->toBe(5);
});

test('stripe webhook ignores partial refund and logs warning without altering full status', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-PARTIAL-001',
        'currency' => 'GBP',
        'amount' => 20.00,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_partial_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_partial_001',
        'amount_minor' => 2000,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Customer requested refund',
        'metadata' => ['credits_to_deduct' => 5],
    ]);

    $eventId = 'evt_stripe_partial_001';
    // Event reports partial refund (£10 out of £20)
    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'charge.refunded',
        'data' => [
            'object' => [
                'id' => 'ch_partial_001',
                'payment_intent' => 'pi_partial_001',
                'amount' => 2000,
                'amount_refunded' => 1000, // Partial!
                'currency' => 'gbp',
                'refunds' => [
                    'data' => [
                        [
                            'id' => 're_partial_001',
                            'amount' => 1000,
                            'status' => 'succeeded',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk();

    $record->refresh();
    $tx->refresh();

    // Partial refund does not change full-refund status
    expect($record->status)->toBe('unknown');
    expect($tx->status)->toBe('success');
});

test('out-of-order failure event cannot reverse already-succeeded refund record', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-ORDER-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_order_001',
        'status' => 'refunded',
        'paid_at' => now()->subDay(),
        'refunded_at' => now(),
    ]);

    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_order_001',
        'refund_gateway_ref' => 're_order_001',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_SUCCESS, // Terminal SUCCESS
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Customer requested refund',
        'metadata' => ['credits_to_deduct' => 5],
    ]);

    $eventId = 'evt_stripe_late_failure_001';
    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'refund.updated',
        'data' => [
            'object' => [
                'id' => 're_order_001',
                'charge' => 'ch_order_001',
                'payment_intent' => 'pi_order_001',
                'amount' => 1999,
                'currency' => 'gbp',
                'status' => 'failed',
                'failure_reason' => 'late_arriving_error',
            ],
        ],
    ];

    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk();

    // Success MUST remain terminal!
    $record->refresh();
    expect($record->status)->toBe('success');
    expect(CreditTransaction::where('reference_id', 'like', 'refund_reversal:%')->count())->toBe(0);
});

test('stripe webhook charge.refunded reconciles guest report purchase', function () {
    $vehicle = Vehicle::create(['registration_number' => 'GUEST-REC-01']);
    $report = Report::create(['vehicle_id' => $vehicle->id, 'report_type' => 'basic']);

    $purchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Guest User',
        'email' => 'guest@example.com',
        'amount_minor' => 1499,
        'currency' => 'gbp',
        'payment_provider' => 'stripe',
        'provider_payment_id' => 'pi_guest_rec_001',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
        'paid_at' => now()->subDay(),
    ]);

    $record = RefundRecord::create([
        'refundable_type' => GuestReportPurchase::class,
        'refundable_id' => $purchase->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_guest_rec_001',
        'amount_minor' => 1499,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Guest report fulfillment permanently failed',
        'metadata' => ['credits_to_deduct' => 0],
    ]);

    $eventId = 'evt_guest_refund_001';
    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'charge.refunded',
        'data' => [
            'object' => [
                'id' => 'ch_guest_001',
                'payment_intent' => 'pi_guest_rec_001',
                'amount' => 1499,
                'amount_refunded' => 1499,
                'currency' => 'gbp',
                'refunds' => [
                    'data' => [
                        [
                            'id' => 're_guest_001',
                            'amount' => 1499,
                            'status' => 'succeeded',
                        ],
                    ],
                ],
            ],
        ],
    ];

    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk();

    $record->refresh();
    $purchase->refresh();

    expect($record->status)->toBe('success');
    expect($record->refund_gateway_ref)->toBe('re_guest_001');
    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_REFUNDED);
    expect($purchase->refunded_at)->not->toBeNull();
});

test('paypal webhook PAYMENT.CAPTURE.REFUNDED reconciles unknown refund record', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-PPL-REC-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'paypal',
        'payment_gateway_ref' => '2GG279541U471931P',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'paypal',
        'payment_reference' => '2GG279541U471931P',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'PayPal customer refund',
        'metadata' => ['credits_to_deduct' => 5],
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->once()->andReturn(true);
    $this->app->instance(PayPalService::class, $mockPayPal);

    $eventId = 'WH-PPL-REFUND-001';
    $payload = [
        'id' => $eventId,
        'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
        'resource' => [
            'id' => '9XY1234567890PPL',
            'status' => 'COMPLETED',
            'amount' => [
                'value' => '19.99',
                'currency_code' => 'GBP',
            ],
            'links' => [
                [
                    'href' => 'https://api-m.paypal.com/v2/payments/captures/2GG279541U471931P',
                    'rel' => 'up',
                    'method' => 'GET',
                ],
            ],
        ],
    ];

    $response = $this->postJson(route('paypal.webhook'), $payload);
    $response->assertOk()->assertJson(['received' => true]);

    $record->refresh();
    $tx->refresh();

    expect($record->status)->toBe('success');
    expect($record->refund_gateway_ref)->toBe('9XY1234567890PPL');
    expect($tx->status)->toBe('refunded');

    // Deduplication check: second delivery should be ignored immediately
    $mockPayPal2 = Mockery::mock(PayPalService::class);
    $mockPayPal2->shouldReceive('verifyWebhook')->once()->andReturn(true);
    $this->app->instance(PayPalService::class, $mockPayPal2);

    $response2 = $this->postJson(route('paypal.webhook'), $payload);
    $response2->assertOk()->assertJson(['received' => true]);
});

test('paypal webhook PAYMENT.SALE.COMPLETED does not wipe out scheduled cancellation state', function () {
    Queue::fake([SendTelegramSaleNotification::class]);
    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'starter',
        'price' => 19.99,
        'payment_method' => 'paypal',
        'paypal_subscription_id' => 'I-PPL-CANCEL-001',
        'paypal_plan_id' => 'P-STARTER-001',
        'status' => 'active',
        'monthly_credits' => 5,
        'cancel_at_period_end' => true,
        'cancelled_at' => now()->subDay(),
        'end_date' => now()->addDays(20),
        'current_period_end' => now()->addDays(20),
    ]);

    $originalCancelledAt = $sub->cancelled_at->toIso8601String();
    $originalEndDate = $sub->end_date->toIso8601String();

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->once()->andReturn(true);
    $mockPayPal->shouldReceive('getSubscription')->once()->with('I-PPL-CANCEL-001')->andReturn([
        'id' => 'I-PPL-CANCEL-001',
        'plan_id' => 'P-STARTER-001',
        'custom_id' => $this->customer->id.'|starter',
        'billing_info' => [
            'next_billing_time' => now()->addMonth()->toIso8601String(),
        ],
    ]);
    $this->app->instance(PayPalService::class, $mockPayPal);

    config(['credit_plans.starter' => [
        'type' => 'subscription',
        'credits' => 5,
        'amount_minor' => 1999,
        'currency' => 'gbp',
        'paypal_plan_id' => 'P-STARTER-001',
        'label' => 'Starter Plan',
    ]]);

    $payload = [
        'id' => 'WH-SALE-COMPLETED-001',
        'event_type' => 'PAYMENT.SALE.COMPLETED',
        'resource' => [
            'id' => 'SALE-12345',
            'billing_agreement_id' => 'I-PPL-CANCEL-001',
            'amount' => [
                'total' => '19.99',
                'currency' => 'GBP',
            ],
            'create_time' => now()->toIso8601String(),
        ],
    ];

    $response = $this->postJson(route('paypal.webhook'), $payload);
    $response->assertOk();

    Queue::assertPushed(
        SendTelegramSaleNotification::class,
        fn (SendTelegramSaleNotification $job): bool => $job->saleType === 'Subscription payment'
            && $job->paymentProvider === 'PayPal'
            && $job->reference === 'SALE-12345'
    );

    $sub->refresh();

    // Cancellation state MUST NOT be wiped out!
    expect($sub->cancel_at_period_end)->toBeTrue();
    expect($sub->cancelled_at->toIso8601String())->toBe($originalCancelledAt);
    expect($sub->end_date->toIso8601String())->toBe($originalEndDate);
});

test('stripe webhook customer.subscription.deleted preserves original cancellation timestamp', function () {
    $cancelledAt = now()->subDays(5);

    $sub = Subscription::create([
        'user_id' => $this->customer->id,
        'plan_name' => 'starter',
        'price' => 19.99,
        'payment_method' => 'stripe',
        'stripe_subscription_id' => 'sub_preserve_cancel_001',
        'status' => 'active',
        'monthly_credits' => 5,
        'cancel_at_period_end' => true,
        'cancelled_at' => $cancelledAt,
        'end_date' => now(),
    ]);

    $eventId = 'evt_stripe_sub_deleted_001';
    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'customer.subscription.deleted',
        'data' => [
            'object' => [
                'id' => 'sub_preserve_cancel_001',
            ],
        ],
    ];

    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk();

    $sub->refresh();
    expect($sub->status)->toBe('cancelled');
    expect($sub->cancel_at_period_end)->toBeFalse();
    // Original timestamp preserved
    expect($sub->cancelled_at->toIso8601String())->toBe($cancelledAt->toIso8601String());
});

test('artisan refunds:reconcile command successfully reconciles unknown records', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-CMD-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_cmd_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_cmd_001',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'CLI reconciliation test',
        'metadata' => ['credits_to_deduct' => 5],
    ]);

    $mockRefundObj = (object) [
        'id' => 're_cmd_confirmed_001',
        'amount' => 1999,
        'currency' => 'gbp',
        'status' => 'succeeded',
    ];

    $mockRefunds = Mockery::mock();
    $mockRefunds->shouldReceive('all')->once()->with(['payment_intent' => 'pi_cmd_001'])->andReturn((object) [
        'data' => [$mockRefundObj],
    ]);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->refunds = $mockRefunds;
    $this->app->instance(StripeClient::class, $mockStripe);

    $this->artisan("refunds:reconcile --id={$record->id}")
        ->assertSuccessful();

    $record->refresh();
    $tx->refresh();

    expect($record->status)->toBe('success');
    expect($record->refund_gateway_ref)->toBe('re_cmd_confirmed_001');
    expect($tx->status)->toBe('refunded');
});

test('webhook retries after previous processing failure and succeeds', function () {
    $eventId = 'evt_retry_failure_001';

    // Simulate an earlier failed attempt stored in DB
    ProcessedWebhookEvent::create([
        'provider' => 'stripe',
        'event_id' => $eventId,
        'event_type' => 'charge.refunded',
        'status' => ProcessedWebhookEvent::STATUS_FAILED,
        'error_message' => 'Previous transient database deadlock',
    ]);

    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-RETRY-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_retry_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_retry_001',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Retry after failure test',
        'metadata' => ['credits_to_deduct' => 5],
    ]);

    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'charge.refunded',
        'data' => [
            'object' => [
                'id' => 'ch_retry_001',
                'payment_intent' => 'pi_retry_001',
                'amount' => 1999,
                'amount_refunded' => 1999,
                'currency' => 'gbp',
                'refunds' => [
                    'data' => [
                        [
                            'id' => 're_retry_001',
                            'amount' => 1999,
                            'status' => 'succeeded',
                        ],
                    ],
                ],
            ],
        ],
    ];

    // Retry should now proceed and succeed!
    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk();

    $record->refresh();
    $tx->refresh();
    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();

    expect($event->status)->toBe('completed');
    expect($record->status)->toBe('success');
    expect($record->refund_gateway_ref)->toBe('re_retry_001');
    expect($tx->status)->toBe('refunded');
});

test('ambiguous refund webhook status maintains unknown status and credit hold', function () {
    $this->customer->update(['credits' => 0]); // Credits currently held

    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-AMBIG-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_ambig_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    $record = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_ambig_001',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_UNKNOWN,
        'idempotency_key' => (string) Str::uuid(),
        'reason' => 'Ambiguous status test',
        'metadata' => ['credits_to_deduct' => 5],
    ]);

    $eventId = 'evt_stripe_ambig_001';
    // Event reports pending status
    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'refund.updated',
        'data' => [
            'object' => [
                'id' => 're_ambig_001',
                'charge' => 'ch_ambig_001',
                'payment_intent' => 'pi_ambig_001',
                'amount' => 1999,
                'currency' => 'gbp',
                'status' => 'pending', // Pending / Ambiguous!
            ],
        ],
    ];

    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk();

    $record->refresh();
    $tx->refresh();
    $this->customer->refresh();

    // Status MUST remain unknown!
    expect($record->status)->toBe('unknown');
    expect($tx->status)->toBe('success');
    // Credit hold MUST be maintained (not restored prematurely!)
    expect($this->customer->credits)->toBe(0);
    expect(CreditTransaction::where('reference_id', 'like', 'refund_reversal:%')->count())->toBe(0);
});

test('unmatched refund webhook returns success and does not mutate any records', function () {
    $eventId = 'evt_unmatched_001';
    $payload = [
        'id' => $eventId,
        'object' => 'event',
        'api_version' => '2025-03-31.basil',
        'created' => time(),
        'type' => 'refund.updated',
        'data' => [
            'object' => [
                'id' => 're_nonexistent_999',
                'charge' => 'ch_nonexistent_999',
                'payment_intent' => 'pi_nonexistent_999',
                'amount' => 5000,
                'currency' => 'gbp',
                'status' => 'succeeded',
            ],
        ],
    ];

    $response = createSignedStripeRequest($this, $payload, $this->secret);
    $response->assertOk()->assertJson(['received' => true]);

    // Event marked completed
    $event = ProcessedWebhookEvent::where('event_id', $eventId)->first();
    expect($event)->not->toBeNull();
    expect($event->status)->toBe('completed');

    // No refund records created or mutated
    expect(RefundRecord::count())->toBe(0);
});
