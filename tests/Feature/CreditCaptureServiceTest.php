<?php

use App\Jobs\ProcessPayPalOrderCapture;
use App\Jobs\SendTelegramSaleNotification;
use App\Models\CreditCheckoutAttempt;
use App\Models\CreditTransaction;
use App\Models\ProcessedWebhookEvent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CreditCaptureService;
use App\Services\PayPalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function makeUser(int $credits = 0): User
{
    $user = User::factory()->create(['credits' => $credits]);

    return $user;
}

function makeAttempt(User $user, array $overrides = []): CreditCheckoutAttempt
{
    return CreditCheckoutAttempt::create(array_merge([
        'user_id' => $user->id,
        'intent_reference' => (string) Str::uuid(),
        'attempt_uuid' => (string) Str::uuid(),
        'invoice_id' => 'UKC-CR-' . strtoupper(Str::random(16)),
        'plan_slug' => '1-credit',
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-' . strtoupper(Str::random(8)),
        'expires_at' => now()->addMinutes(30),
    ], $overrides));
}

/**
 * Build a fake PayPal captured-order response for a given attempt and capture ID.
 *
 * @param  array<string, mixed>  $captureOverrides
 * @return array<string, mixed>
 */
function makeCapturedOrder(
    User $user,
    CreditCheckoutAttempt $attempt,
    string $captureId,
    array $captureOverrides = [],
): array {
    $capture = array_merge([
        'id' => $captureId,
        'status' => 'COMPLETED',
        'amount' => [
            'currency_code' => 'GBP',
            'value' => '19.99',
        ],
        'create_time' => '2026-01-01T12:00:00Z',
    ], $captureOverrides);

    return [
        'id' => $attempt->provider_order_id,
        'status' => 'COMPLETED',
        'purchase_units' => [
            [
                'reference_id' => '1-credit',
                'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
                'payments' => [
                    'captures' => [$capture],
                ],
            ],
        ],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Setup
// ─────────────────────────────────────────────────────────────────────────────

beforeEach(function () {
    config([
        'services.paypal.mode' => 'sandbox',
        'services.paypal.client_id' => 'test-client-id',
        'services.paypal.client_secret' => 'test-client-secret',
        'credit_plans.1-credit' => [
            'type' => 'one_time',
            'credits' => 1,
            'label' => '1 Credit',
            'amount_minor' => 1999,
            'currency' => 'GBP',
            'amount_display' => '£19.99',
        ],
        'credit_plans.3-credits' => [
            'type' => 'one_time',
            'credits' => 3,
            'label' => '3 Credits',
            'amount_minor' => 5399,
            'currency' => 'GBP',
            'amount_display' => '£53.99',
        ],
    ]);

    Queue::fake([SendTelegramSaleNotification::class]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase A prerequisite: calling_provider quarantine after 6 hours
// ─────────────────────────────────────────────────────────────────────────────

test('Phase A: calling_provider attempt within 6 hours can retry and succeed', function () {
    $user = makeUser();

    $attempt = CreditCheckoutAttempt::create([
        'user_id' => $user->id,
        'intent_reference' => 'intent-calling-within-window',
        'attempt_uuid' => (string) Str::uuid(),
        'invoice_id' => 'UKC-CR-CALLWITHIN1',
        'plan_slug' => '1-credit',
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_CALLING_PROVIDER,
        'provider_request_started_at' => now()->subMinutes(30), // Within 6-hour window
        'expires_at' => now()->addMinutes(30),
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->andReturn(['id' => 'ORDER-CALL-WITHIN-001', 'status' => 'CREATED']);

    $this->instance(PayPalService::class, $mockPayPal);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-calling-within-window',
        ]);

    $response->assertOk()
        ->assertJson(['id' => 'ORDER-CALL-WITHIN-001']);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_PROVIDER_CREATED)
        ->and($attempt->provider_order_id)->toBe('ORDER-CALL-WITHIN-001')
        ->and($attempt->provider_request_started_at)->not->toBeNull();
});

test('Phase A: calling_provider attempt older than 6 hours is quarantined and rejected', function () {
    $user = makeUser();

    $uuid = (string) Str::uuid();
    $attempt = CreditCheckoutAttempt::create([
        'user_id' => $user->id,
        'intent_reference' => 'intent-calling-past-window',
        'attempt_uuid' => $uuid,
        'invoice_id' => 'UKC-CR-CALLPAST0001',
        'plan_slug' => '1-credit',
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_CALLING_PROVIDER,
        'expires_at' => now()->subHours(5),
    ]);

    // Set provider_request_started_at to 7 hours ago via direct DB update
    CreditCheckoutAttempt::where('id', $attempt->id)->update([
        'provider_request_started_at' => now()->subHours(7),
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldNotReceive('createOrder');

    $this->instance(PayPalService::class, $mockPayPal);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-calling-past-window',
        ]);

    $response->assertStatus(502);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE);
});

test('Phase A: provider_request_started_at is stamped before PayPal HTTP call', function () {
    $user = makeUser();

    $stampedAt = null;

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->andReturnUsing(function () use (&$stampedAt, $user) {
            // At the time createOrder is called, the attempt must already have the timestamp
            $attempt = CreditCheckoutAttempt::where('user_id', $user->id)->first();
            $stampedAt = $attempt?->provider_request_started_at;

            return ['id' => 'ORDER-STAMP-001', 'status' => 'CREATED'];
        });

    $this->instance(PayPalService::class, $mockPayPal);

    $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-stamp-test',
        ])
        ->assertOk();

    expect($stampedAt)->not->toBeNull();
});

test('Phase A: create_ambiguous attempt older than 6 hours falls back to created_at anchor when provider_request_started_at is null', function () {
    $user = makeUser();

    $attempt = CreditCheckoutAttempt::create([
        'user_id' => $user->id,
        'intent_reference' => 'intent-fallback-anchor',
        'attempt_uuid' => (string) Str::uuid(),
        'invoice_id' => 'UKC-CR-FALLBACK001',
        'plan_slug' => '1-credit',
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_CREATE_AMBIGUOUS,
        'provider_request_started_at' => null, // No timestamp set (old format)
        'expires_at' => now()->subHours(5),
    ]);

    // Force created_at to 7 hours ago so the fallback anchor triggers
    CreditCheckoutAttempt::where('id', $attempt->id)->update([
        'created_at' => now()->subHours(7),
        'updated_at' => now()->subHours(7),
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldNotReceive('createOrder');

    $this->instance(PayPalService::class, $mockPayPal);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-fallback-anchor',
        ]);

    $response->assertStatus(502);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 2: CreditCaptureService — attempt-based path
// ─────────────────────────────────────────────────────────────────────────────

test('Phase 2: attempt-based capture grants credits from snapshot, not config', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-SNAP-001',
    ]);

    $captureId = 'CAPTURE-SNAP-001';
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    // Dynamically change config — capture should still use the snapshot
    config(['credit_plans.1-credit.amount_minor' => 99999]);
    config(['credit_plans.1-credit.credits' => 100]);

    $service = app(CreditCaptureService::class);
    $result = $service->fulfill(
        order: $capturedOrder,
        capture: $capturedOrder['purchase_units'][0]['payments']['captures'][0],
        captureId: $captureId,
        orderId: 'ORDER-SNAP-001',
    );

    // Should use snapshot: 1 credit, not config: 100 credits
    expect($result['credits_granted'])->toBe(1);
    expect($user->fresh()->credits)->toBe(1);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED)
        ->and($attempt->provider_capture_id)->toBe($captureId);

    expect(Transaction::where('payment_gateway_ref', $captureId)->exists())->toBeTrue();
});

test('Phase 2: legacy path uses config-based amount and credits', function () {
    $user = makeUser();

    $captureId = 'CAPTURE-LEG-001';
    $orderId = 'ORDER-LEG-001';

    // Legacy custom_id: only 2 parts (user_id|plan_slug), no attempt_uuid
    $capturedOrder = [
        'id' => $orderId,
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit",
            'payments' => [
                'captures' => [[
                    'id' => $captureId,
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
                    'create_time' => '2026-01-01T12:00:00Z',
                ]],
            ],
        ]],
    ];

    $service = app(CreditCaptureService::class);
    $result = $service->fulfill(
        order: $capturedOrder,
        capture: $capturedOrder['purchase_units'][0]['payments']['captures'][0],
        captureId: $captureId,
        orderId: $orderId,
    );

    expect($result['credits_granted'])->toBe(1);
    expect($user->fresh()->credits)->toBe(1);
    expect(Transaction::where('payment_gateway_ref', $captureId)->exists())->toBeTrue();

    // No attempt record should exist for legacy path
    expect(CreditCheckoutAttempt::where('provider_order_id', $orderId)->exists())->toBeFalse();
});

test('Phase 2: PENDING capture does NOT grant credits', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-PENDING-001',
    ]);

    $captureId = 'CAPTURE-PENDING-001';
    $capturedOrder = makeCapturedOrder(
        $user, $attempt, $captureId,
        captureOverrides: ['status' => 'PENDING'],
    );

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->andReturn($capturedOrder);
    $this->instance(PayPalService::class, $mockPayPal);

    $webhookPayload = [
        'id' => 'EVT-PENDING-001',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => $captureId,
            'status' => 'PENDING', // PENDING — must NOT grant
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
            'supplementary_data' => ['related_ids' => ['order_id' => $attempt->provider_order_id]],
        ],
    ];

    $this->postJson(route('paypal.webhook'), $webhookPayload)
        ->assertOk();

    expect($user->fresh()->credits)->toBe(0);
    expect(CreditTransaction::where('reference_id', $captureId)->exists())->toBeFalse();
});

test('Phase 2: duplicate webhook does NOT double-grant credits', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-DEDUP-001',
    ]);

    $captureId = 'CAPTURE-DEDUP-001';
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->andReturn($capturedOrder);
    $this->instance(PayPalService::class, $mockPayPal);

    $webhookPayload = [
        'id' => 'EVT-DEDUP-001',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => $captureId,
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
            'create_time' => '2026-01-01T12:00:00Z',
            'supplementary_data' => ['related_ids' => ['order_id' => $attempt->provider_order_id]],
        ],
    ];

    // First webhook
    $this->postJson(route('paypal.webhook'), $webhookPayload)->assertOk();

    // Second webhook — same event_id triggers deduplication
    $this->postJson(route('paypal.webhook'), $webhookPayload)->assertOk();

    // Credits granted only once
    expect($user->fresh()->credits)->toBe(1);
    expect(CreditTransaction::where('reference_id', $captureId)->count())->toBe(1);
});

test('Phase 2: simultaneous frontend capture and webhook both succeed idempotently without double-grant', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-RACE-001',
    ]);

    $captureId = 'CAPTURE-RACE-001';
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    $service = app(CreditCaptureService::class);
    $capture = $capturedOrder['purchase_units'][0]['payments']['captures'][0];

    // Both calls succeed via idempotency key on credit_transactions
    $service->fulfill(order: $capturedOrder, capture: $capture, captureId: $captureId, orderId: 'ORDER-RACE-001');
    $service->fulfill(order: $capturedOrder, capture: $capture, captureId: $captureId, orderId: 'ORDER-RACE-001');

    expect($user->fresh()->credits)->toBe(1);
    expect(CreditTransaction::where('reference_id', $captureId)->count())->toBe(1);
});

test('Phase 2: attempt already captured returns existing transaction without re-granting', function () {
    $user = makeUser(1); // already has 1 credit (from prior capture)

    $captureId = 'CAPTURE-ALREADY-001';

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
        'provider_order_id' => 'ORDER-ALREADY-001',
        'provider_capture_id' => $captureId,
    ]);

    // Pre-create the transaction record (as it should exist for a captured attempt)
    Transaction::create([
        'user_id' => $user->id,
        'invoice_id' => 'UKC-CR-ALREADY-001',
        'payment_gateway_ref' => $captureId,
        'amount' => '19.99',
        'currency' => 'GBP',
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'paypal',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);
    $service = app(CreditCaptureService::class);
    $capture = $capturedOrder['purchase_units'][0]['payments']['captures'][0];

    $result = $service->fulfill(order: $capturedOrder, capture: $capture, captureId: $captureId, orderId: 'ORDER-ALREADY-001');

    // No additional credits granted
    expect($user->fresh()->credits)->toBe(1); // unchanged
    expect($result['credits_granted'])->toBe(1); // from attempt
});

test('Phase 2: amount mismatch in attempt-based path throws and does not grant credits', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'amount_minor' => 1999, // £19.99
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-MISMATCH-001',
    ]);

    $captureId = 'CAPTURE-MISMATCH-001';
    // Tampered amount in capture resource
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId, captureOverrides: [
        'amount' => ['currency_code' => 'GBP', 'value' => '0.01'],
    ]);

    $service = app(CreditCaptureService::class);
    $capture = $capturedOrder['purchase_units'][0]['payments']['captures'][0];

    expect(fn () => $service->fulfill(order: $capturedOrder, capture: $capture, captureId: $captureId, orderId: 'ORDER-MISMATCH-001'))
        ->toThrow(RuntimeException::class, 'amount mismatch');

    expect($user->fresh()->credits)->toBe(0);
});

test('Phase 2: webhook capture routes to CreditCaptureService and updates attempt to CAPTURE_COMPLETED', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-WH-ROUTE-001',
    ]);

    $captureId = 'CAPTURE-WH-ROUTE-001';
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->once()->andReturn($capturedOrder);
    $this->instance(PayPalService::class, $mockPayPal);

    $webhookPayload = [
        'id' => 'EVT-WH-ROUTE-001',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => $captureId,
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
            'create_time' => '2026-01-01T12:00:00Z',
            'supplementary_data' => ['related_ids' => ['order_id' => $attempt->provider_order_id]],
        ],
    ];

    $this->postJson(route('paypal.webhook'), $webhookPayload)->assertOk();

    expect($user->fresh()->credits)->toBe(1);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED)
        ->and($attempt->provider_capture_id)->toBe($captureId);

    expect(Transaction::where('payment_gateway_ref', $captureId)->exists())->toBeTrue();
});

test('Phase 2: frontend capture uses CreditCaptureService via controller', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-CTRL-001',
    ]);

    $captureId = 'CAPTURE-CTRL-001';
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')
        ->once()
        ->with('ORDER-CTRL-001')
        ->andReturn($capturedOrder);
    // Status is already COMPLETED, so captureOrder should NOT be called
    $mockPayPal->shouldNotReceive('captureOrder');
    $this->instance(PayPalService::class, $mockPayPal);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.capture'), [
            'order_id' => 'ORDER-CTRL-001',
        ]);

    $response->assertOk()
        ->assertJson(['status' => 'success']);

    expect($user->fresh()->credits)->toBe(1);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED);
});

test('Phase 2: approval reversed webhook logs and resets attempt to provider_created without downgrading captured', function () {
    $user = makeUser();

    // Attempt in PROVIDER_CREATED (not yet captured)
    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-REVERSED-001',
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $this->instance(PayPalService::class, $mockPayPal);

    $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-REVERSED-001',
        'event_type' => 'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
        'resource' => [
            'id' => 'ORDER-REVERSED-001',
        ],
    ])->assertOk();

    // Attempt stays at provider_created (not downgraded below)
    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_PROVIDER_CREATED);
    expect($user->fresh()->credits)->toBe(0);
});

test('Phase 2: approval reversed does NOT downgrade a CAPTURE_COMPLETED attempt', function () {
    $user = makeUser(1);

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
        'provider_order_id' => 'ORDER-SAFE-REVERSED-001',
        'provider_capture_id' => 'CAPTURE-SAFE-001',
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $this->instance(PayPalService::class, $mockPayPal);

    $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-SAFE-REVERSED-001',
        'event_type' => 'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
        'resource' => [
            'id' => 'ORDER-SAFE-REVERSED-001',
        ],
    ])->assertOk();

    // CAPTURE_COMPLETED must not be downgraded
    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED);
    expect($user->fresh()->credits)->toBe(1); // unchanged
});

test('Phase 2: plan config change after attempt creation does not affect legacy path credits', function () {
    $user = makeUser();

    // Legacy order (no attempt record)
    $captureId = 'CAPTURE-PLAN-CHANGE-001';
    $orderId = 'ORDER-PLAN-CHANGE-001';

    $capturedOrder = [
        'id' => $orderId,
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit",
            'payments' => [
                'captures' => [[
                    'id' => $captureId,
                    'status' => 'COMPLETED',
                    'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
                    'create_time' => '2026-01-01T12:00:00Z',
                ]],
            ],
        ]],
    ];

    // Note: legacy path uses config AT CALL TIME, so capture should match current config
    $service = app(CreditCaptureService::class);
    $capture = $capturedOrder['purchase_units'][0]['payments']['captures'][0];

    $result = $service->fulfill(order: $capturedOrder, capture: $capture, captureId: $captureId, orderId: $orderId);

    expect($result['credits_granted'])->toBe(1); // current config: 1 credit
    expect($user->fresh()->credits)->toBe(1);
});

test('Phase 2: invoice_id from attempt snapshot is used in transaction record', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'invoice_id' => 'UKC-CR-INVTEST1234',
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-INV-001',
    ]);

    $captureId = 'CAPTURE-INV-001';
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    $service = app(CreditCaptureService::class);
    $capture = $capturedOrder['purchase_units'][0]['payments']['captures'][0];

    $service->fulfill(order: $capturedOrder, capture: $capture, captureId: $captureId, orderId: 'ORDER-INV-001');

    $transaction = Transaction::where('payment_gateway_ref', $captureId)->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->invoice_id)->toBe('UKC-CR-INVTEST1234'); // from attempt snapshot
});

test('Phase 2: guest report payment webhook is not affected by CreditCaptureService changes', function () {
    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')
        ->once()
        ->with('ORDER-GUEST-001')
        ->andReturn([
            'id' => 'ORDER-GUEST-001',
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => '99',
                'custom_id' => 'guest-report|dummy-uuid|99',
                'payments' => [
                    'captures' => [[
                        'id' => 'CAPTURE-GUEST-001',
                        'status' => 'COMPLETED',
                    ]],
                ],
            ]],
        ]);

    $this->instance(PayPalService::class, $mockPayPal);

    $mockGuestService = Mockery::mock(\App\Services\GuestReportPaymentService::class);
    $mockGuestService->shouldReceive('handlePayPalGuestOrder')->once();
    $this->instance(\App\Services\GuestReportPaymentService::class, $mockGuestService);

    $mockCaptureService = Mockery::mock(CreditCaptureService::class);
    $mockCaptureService->shouldNotReceive('fulfill');
    $this->instance(CreditCaptureService::class, $mockCaptureService);

    // Guest custom_id format starts with 'guest-report|'
    // The webhook controller should intercept this and NOT call CreditCaptureService
    $response = $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-GUEST-001',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => 'CAPTURE-GUEST-001',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'GBP', 'value' => '10.99'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-GUEST-001']],
        ],
    ]);

    $response->assertOk();
    expect(CreditTransaction::count())->toBe(0);
});

test('Phase A: provider_request_started_at is NOT reset on retry', function () {
    $user = makeUser();

    $mockPayPal = Mockery::mock(PayPalService::class);

    // First call fails
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->andThrow(new RuntimeException('Network timeout.'));

    // Second call succeeds
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->andReturn(['id' => 'ORDER-RETRY-STAMP-001', 'status' => 'CREATED']);

    $this->instance(PayPalService::class, $mockPayPal);

    $intent = 'intent-retry-timestamp-check';

    // First attempt fails
    $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => $intent,
        ])
        ->assertStatus(502);

    $attempt = CreditCheckoutAttempt::where('user_id', $user->id)->first();
    $initialTimestamp = $attempt->provider_request_started_at;
    expect($initialTimestamp)->not->toBeNull();

    // Fast-forward 10 minutes
    Carbon::setTestNow(now()->addMinutes(10));

    // Second attempt (retry)
    $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => $intent,
        ])
        ->assertOk();

    $attempt->refresh();
    // The timestamp must NOT be reset to the new now() — it must equal initialTimestamp!
    expect($attempt->provider_request_started_at->toIso8601String())->toBe($initialTimestamp->toIso8601String());

    Carbon::setTestNow(); // Reset test clock
});

test('Phase 2: crash after credit grant but before attempt completion can be recovered without double grant', function () {
    $user = makeUser(0);

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURING, // Simulated mid-flight crash state
        'provider_order_id' => 'ORDER-CRASH-RECOVER-001',
    ]);

    $captureId = 'CAPTURE-CRASH-001';
    $orderId = 'ORDER-CRASH-RECOVER-001';
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    // Simulate that creditService->grantCredits WAS already called before the crash:
    $creditService = app(App\Services\CreditService::class);
    $creditService->grantCredits(
        user: $user,
        amount: 1,
        type: 'purchase',
        referenceId: $captureId,
        idempotencyKey: "capture-{$orderId}",
        description: "PayPal purchase: {$captureId}",
    );

    expect($user->fresh()->credits)->toBe(1);
    expect(CreditTransaction::where('reference_id', $captureId)->count())->toBe(1);

    // Now recovery happens: the capture endpoint is called again
    $service = app(CreditCaptureService::class);
    $capture = $capturedOrder['purchase_units'][0]['payments']['captures'][0];

    $result = $service->fulfill(
        order: $capturedOrder,
        capture: $capture,
        captureId: $captureId,
        orderId: $orderId,
    );

    // Assert: credits remain 1 (no double grant)
    expect($user->fresh()->credits)->toBe(1);
    expect(CreditTransaction::where('reference_id', $captureId)->count())->toBe(1);

    // Assert: attempt has recovered to completed status
    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED)
        ->and($attempt->provider_capture_id)->toBe($captureId);

    // Assert: transaction record exists
    expect(Transaction::where('payment_gateway_ref', $captureId)->exists())->toBeTrue();
});

test('Phase 2: webhook CHECKOUT.ORDER.APPROVED returns 2xx after durable job dispatch', function () {
    Queue::fake([ProcessPayPalOrderCapture::class]);

    $user = makeUser(0);
    $orderId = 'ORDER-DURABLE-DISPATCH-001';

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => $orderId,
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $this->instance(PayPalService::class, $mockPayPal);

    $response = $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-DURABLE-DISPATCH-001',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => $orderId],
    ]);

    $response->assertOk();

    // 1. Durable job was pushed with the correct order ID
    Queue::assertPushed(ProcessPayPalOrderCapture::class, function ($job) use ($orderId) {
        return $job->orderId === $orderId && $job->uniqueId() === 'paypal-order-capture:'.$orderId;
    });

    // 2. Intermediate attempt status was updated to provider_approved
    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_PROVIDER_APPROVED);

    // 3. ProcessedWebhookEvent is marked completed
    $webhookEvent = ProcessedWebhookEvent::where('event_id', 'EVT-DURABLE-DISPATCH-001')->first();
    expect($webhookEvent)->not->toBeNull()
        ->and($webhookEvent->isCompleted())->toBeTrue();
});

test('Phase 2: queued ProcessPayPalOrderCapture executes unified capture pipeline and grants credits', function () {
    $user = makeUser(0);
    $orderId = 'ORDER-JOB-EXEC-001';
    $captureId = 'CAPTURE-JOB-EXEC-001';

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => $orderId,
    ]);

    $approvedOrder = [
        'id' => $orderId,
        'status' => 'APPROVED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
        ]],
    ];
    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $mockPayPal->shouldReceive('captureOrder')->once()->with($orderId)->andReturn($capturedOrder);
    $this->instance(PayPalService::class, $mockPayPal);

    // Run the job directly via handle()
    $job = new ProcessPayPalOrderCapture($orderId, 'EVT-JOB-EXEC-001');
    $job->handle(app(CreditCaptureService::class));

    // Credits granted, attempt completed
    expect($user->fresh()->credits)->toBe(1);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED)
        ->and($attempt->provider_capture_id)->toBe($captureId);

    expect(Transaction::where('payment_gateway_ref', $captureId)->exists())->toBeTrue();
});

test('Phase 2: webhook dispatch failure does NOT mark event processed and marks it failed', function () {
    $user = makeUser(0);
    $orderId = 'ORDER-FAIL-DISPATCH-001';

    makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => $orderId,
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $this->instance(PayPalService::class, $mockPayPal);

    // Mock Bus to simulate failure when dispatching background job
    Bus::shouldReceive('dispatch')
        ->once()
        ->with(Mockery::type(ProcessPayPalOrderCapture::class))
        ->andThrow(new RuntimeException('Queue connection lost.'));

    $response = $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-FAIL-DISPATCH-001',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => $orderId],
    ]);

    $response->assertStatus(500);

    // Verify ProcessedWebhookEvent is failed, NOT completed
    $webhookEvent = ProcessedWebhookEvent::where('event_id', 'EVT-FAIL-DISPATCH-001')->first();
    expect($webhookEvent)->not->toBeNull()
        ->and($webhookEvent->status)->toBe(ProcessedWebhookEvent::STATUS_FAILED)
        ->and($webhookEvent->error_message)->toContain('Queue connection lost');
});

test('Phase 2: duplicate approval webhook does NOT push duplicate job unnecessarily', function () {
    Queue::fake([ProcessPayPalOrderCapture::class]);

    $user = makeUser(0);
    $orderId = 'ORDER-DUP-ASYNC-001';

    makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => $orderId,
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $this->instance(PayPalService::class, $mockPayPal);

    // First delivery
    $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-DUP-ASYNC-001',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => $orderId],
    ])->assertOk();

    // Duplicate delivery with same event ID
    $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-DUP-ASYNC-001',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => ['id' => $orderId],
    ])->assertOk();

    // Job must be pushed exactly once
    Queue::assertPushed(ProcessPayPalOrderCapture::class, 1);
});

test('Phase 2: capture DECLINED is handled properly with official PayPal schema', function () {
    $user = makeUser(0);
    $orderId = 'ORDER-DECLINED-OFFICIAL-001';
    $captureId = 'CAPTURE-DECLINED-001';

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => $orderId,
    ]);

    $approvedOrder = [
        'id' => $orderId,
        'status' => 'APPROVED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
        ]],
    ];

    // Official PayPal Capture schema for DECLINED
    $declinedCapturedOrder = [
        'id' => $orderId,
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            'payments' => [
                'captures' => [[
                    'id' => $captureId,
                    'status' => 'DECLINED',
                    'status_details' => [
                        'reason' => 'PAYER_ACTION_REQUIRED',
                    ],
                    'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
                ]],
            ],
        ]],
    ];

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $mockPayPal->shouldReceive('captureOrder')->once()->with($orderId)->andReturn($declinedCapturedOrder);
    $this->instance(PayPalService::class, $mockPayPal);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.capture'), [
            'order_id' => $orderId,
        ]);

    $response->assertStatus(422)
        ->assertJson([
            'status' => 'failed',
            'capture_status' => 'DECLINED',
        ]);

    expect($user->fresh()->credits)->toBe(0);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_TERMINAL_FAILED)
        ->and($attempt->failure_reason)->toContain('DECLINED');
});

test('Phase 2: capture FAILED is handled properly with official PayPal schema', function () {
    $user = makeUser(0);
    $orderId = 'ORDER-FAILED-OFFICIAL-001';
    $captureId = 'CAPTURE-FAILED-001';

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => $orderId,
    ]);

    $approvedOrder = [
        'id' => $orderId,
        'status' => 'APPROVED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
        ]],
    ];

    // Official PayPal Capture schema for FAILED
    $failedCapturedOrder = [
        'id' => $orderId,
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            'payments' => [
                'captures' => [[
                    'id' => $captureId,
                    'status' => 'FAILED',
                    'status_details' => [
                        'reason' => 'TRANSACTION_REFUSED',
                    ],
                    'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
                ]],
            ],
        ]],
    ];

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $mockPayPal->shouldReceive('captureOrder')->once()->with($orderId)->andReturn($failedCapturedOrder);
    $this->instance(PayPalService::class, $mockPayPal);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.capture'), [
            'order_id' => $orderId,
        ]);

    $response->assertStatus(422)
        ->assertJson([
            'status' => 'failed',
            'capture_status' => 'FAILED',
        ]);

    expect($user->fresh()->credits)->toBe(0);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_TERMINAL_FAILED)
        ->and($attempt->failure_reason)->toContain('FAILED');
});

test('Phase 2: capture PENDING returns 202 without granting credits using official schema', function () {
    $user = makeUser(0);
    $orderId = 'ORDER-PENDING-OFFICIAL-001';
    $captureId = 'CAPTURE-PENDING-001';

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => $orderId,
    ]);

    $approvedOrder = [
        'id' => $orderId,
        'status' => 'APPROVED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
        ]],
    ];

    // Official PayPal Capture schema for PENDING
    $pendingCapturedOrder = [
        'id' => $orderId,
        'status' => 'COMPLETED',
        'purchase_units' => [[
            'reference_id' => '1-credit',
            'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            'payments' => [
                'captures' => [[
                    'id' => $captureId,
                    'status' => 'PENDING',
                    'status_details' => [
                        'reason' => 'PENDING_REVIEW',
                    ],
                    'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
                ]],
            ],
        ]],
    ];

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($approvedOrder);
    $mockPayPal->shouldReceive('captureOrder')->once()->with($orderId)->andReturn($pendingCapturedOrder);
    $this->instance(PayPalService::class, $mockPayPal);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.capture'), [
            'order_id' => $orderId,
        ]);

    $response->assertStatus(202)
        ->assertJson([
            'status' => 'pending',
            'capture_status' => 'PENDING',
        ]);

    expect($user->fresh()->credits)->toBe(0);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_PENDING);
});

test('Phase 2: capture completed webhook does NOT send POST capture request', function () {
    $user = makeUser(0);

    $orderId = 'ORDER-NO-POST-001';
    $captureId = 'CAPTURE-NO-POST-001';

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => $orderId,
    ]);

    $capturedOrder = makeCapturedOrder($user, $attempt, $captureId);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $mockPayPal->shouldReceive('getOrder')->once()->with($orderId)->andReturn($capturedOrder);
    // MUST NOT call captureOrder
    $mockPayPal->shouldNotReceive('captureOrder');
    $this->instance(PayPalService::class, $mockPayPal);

    $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-COMPLETED-NO-POST-001',
        'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
        'resource' => [
            'id' => $captureId,
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
            'create_time' => '2026-01-01T12:00:00Z',
            'supplementary_data' => ['related_ids' => ['order_id' => $orderId]],
        ],
    ])->assertOk();

    expect($user->fresh()->credits)->toBe(1);
});

test('Phase 2: webhook PAYMENT.CAPTURE.PENDING transitions attempt to capture_pending and does NOT grant credits', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-WH-PENDING-001',
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $this->instance(PayPalService::class, $mockPayPal);

    $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-WH-PENDING-001',
        'event_type' => 'PAYMENT.CAPTURE.PENDING',
        'resource' => [
            'id' => 'CAPTURE-WH-PENDING-001',
            'status' => 'PENDING',
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-WH-PENDING-001']],
        ],
    ])->assertOk();

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_PENDING);
    expect($user->fresh()->credits)->toBe(0);
    expect(CreditTransaction::where('reference_id', 'CAPTURE-WH-PENDING-001')->exists())->toBeFalse();
});

test('Phase 2: webhook PAYMENT.CAPTURE.DENIED transitions attempt to terminal_failed and does NOT grant credits', function () {
    $user = makeUser();

    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-WH-DENIED-001',
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $this->instance(PayPalService::class, $mockPayPal);

    $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-WH-DENIED-001',
        'event_type' => 'PAYMENT.CAPTURE.DENIED',
        'resource' => [
            'id' => 'CAPTURE-WH-DENIED-001',
            'status' => 'DENIED',
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
            'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-WH-DENIED-001']],
        ],
    ])->assertOk();

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_TERMINAL_FAILED)
        ->and($attempt->failure_reason)->toContain('denied');
    expect($user->fresh()->credits)->toBe(0);
    expect(CreditTransaction::where('reference_id', 'CAPTURE-WH-DENIED-001')->exists())->toBeFalse();
});

test('Phase 2: CHECKOUT.ORDER.APPROVED webhook persists ProcessPayPalOrderCapture job in database queue', function () {
    config(['queue.default' => 'database']);

    $user = makeUser();
    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_PROVIDER_CREATED,
        'provider_order_id' => 'ORDER-DB-QUEUE-001',
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('verifyWebhook')->andReturn(true);
    $this->instance(PayPalService::class, $mockPayPal);

    $this->postJson(route('paypal.webhook'), [
        'id' => 'EVT-DB-QUEUE-001',
        'event_type' => 'CHECKOUT.ORDER.APPROVED',
        'resource' => [
            'id' => 'ORDER-DB-QUEUE-001',
            'status' => 'APPROVED',
        ],
    ])->assertOk();

    // Verify job was persisted in database 'jobs' table
    // Payload is JSON-encoded so backslashes are escaped (e.g. App\\Jobs\\ProcessPayPalOrderCapture)
    $persistedJob = DB::table('jobs')->where('queue', 'default')->first();
    expect($persistedJob)->not->toBeNull();
    expect($persistedJob->payload)->toContain('ProcessPayPalOrderCapture');
    expect($persistedJob->payload)->toContain('ORDER-DB-QUEUE-001');

    // Verify webhook event was recorded as processed
    expect(ProcessedWebhookEvent::where('event_id', 'EVT-DB-QUEUE-001')->exists())->toBeTrue();
});

test('Phase 2: ProcessPayPalOrderCapture::failed() transitions attempt to capture_reconciliation_required', function () {
    $user = makeUser();
    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURING,
        'provider_order_id' => 'ORDER-FAILED-JOB-001',
    ]);

    $job = new ProcessPayPalOrderCapture($attempt->provider_order_id, 'EVT-FAILED-001');
    $job->failed(new RuntimeException('Network connection timeout after 3 retries.'));

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_RECONCILIATION_REQUIRED);
    expect($attempt->failure_reason)->toContain('Network connection timeout after 3 retries.');
});

test('Phase 2: ProcessPayPalOrderCapture::failed() never overwrites completed or terminal attempts', function () {
    $user = makeUser();
    $completedAttempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
        'provider_order_id' => 'ORDER-COMPLETED-JOB-001',
    ]);

    $job = new ProcessPayPalOrderCapture($completedAttempt->provider_order_id, 'EVT-FAILED-002');
    $job->failed(new RuntimeException('Late retry failure'));

    $completedAttempt->refresh();
    expect($completedAttempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED);
});

test('Phase 2: Artisan paypal:reconcile-credit-captures fulfills COMPLETED order at PayPal without sending POST capture', function () {
    $user = makeUser();
    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURE_RECONCILIATION_REQUIRED,
        'provider_order_id' => 'ORDER-RECON-COMPLETED-001',
    ]);

    $capturedOrder = makeCapturedOrder($user, $attempt, 'CAPTURE-RECON-001');

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->with('ORDER-RECON-COMPLETED-001')->once()->andReturn($capturedOrder);
    $mockPayPal->shouldNotReceive('captureOrder');
    $this->instance(PayPalService::class, $mockPayPal);

    $this->artisan('paypal:reconcile-credit-captures')
        ->expectsOutputToContain('Checked: 1, Reconciled: 1, Stale: 0, Failed: 0')
        ->assertSuccessful();

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED);
    expect($attempt->provider_capture_id)->toBe('CAPTURE-RECON-001');
    expect($user->fresh()->credits)->toBe(1);

    // Running reconcile a second time is completely idempotent
    $mockPayPalSecond = Mockery::mock(PayPalService::class);
    $mockPayPalSecond->shouldNotReceive('captureOrder');
    $this->instance(PayPalService::class, $mockPayPalSecond);

    $this->artisan('paypal:reconcile-credit-captures', ['--uuid' => $attempt->attempt_uuid])
        ->assertSuccessful();

    expect($user->fresh()->credits)->toBe(1);
});

test('Phase 2: Artisan paypal:reconcile-credit-captures captures APPROVED order within 6 hours and grants credits', function () {
    $user = makeUser();
    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURE_RECONCILIATION_REQUIRED,
        'provider_order_id' => 'ORDER-RECON-APPROVED-001',
        'provider_request_started_at' => now()->subHours(1),
        'created_at' => now()->subHours(1),
    ]);

    $approvedOrder = [
        'id' => 'ORDER-RECON-APPROVED-001',
        'status' => 'APPROVED',
        'purchase_units' => [
            [
                'reference_id' => '1-credit',
                'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            ],
        ],
    ];
    $capturedOrder = makeCapturedOrder($user, $attempt, 'CAPTURE-RECON-APPROVED-001');

    $mockPayPal = Mockery::mock(PayPalService::class);
    // reconcileAttempt calls getOrder; captureOrderAndFulfill calls getOrder again then captureOrder.
    // Use ordered() to ensure Mockery handles two distinct expectations on the same method.
    $mockPayPal->shouldReceive('getOrder')->with('ORDER-RECON-APPROVED-001')->twice()->andReturn($approvedOrder);
    $mockPayPal->shouldReceive('captureOrder')->with('ORDER-RECON-APPROVED-001')->once()->andReturn($capturedOrder);
    $this->instance(PayPalService::class, $mockPayPal);

    $this->artisan('paypal:reconcile-credit-captures')
        ->expectsOutputToContain('Checked: 1, Reconciled: 1, Stale: 0, Failed: 0')
        ->assertSuccessful();

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED);
    expect($user->fresh()->credits)->toBe(1);
});

test('Phase 2: Artisan paypal:reconcile-credit-captures marks APPROVED order older than 6 hours as unreconciled_stale without calling captureOrder', function () {
    $user = makeUser();
    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURE_RECONCILIATION_REQUIRED,
        'provider_order_id' => 'ORDER-RECON-STALE-001',
        'provider_request_started_at' => now()->subHours(7),
        'created_at' => now()->subHours(7),
    ]);

    $approvedOrder = [
        'id' => 'ORDER-RECON-STALE-001',
        'status' => 'APPROVED',
        'purchase_units' => [
            [
                'reference_id' => '1-credit',
                'custom_id' => "{$user->id}|1-credit|{$attempt->attempt_uuid}",
            ],
        ],
    ];

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->with('ORDER-RECON-STALE-001')->once()->andReturn($approvedOrder);
    $mockPayPal->shouldNotReceive('captureOrder');
    $this->instance(PayPalService::class, $mockPayPal);

    $this->artisan('paypal:reconcile-credit-captures')
        ->expectsOutputToContain('Checked: 1, Reconciled: 0, Stale: 1, Failed: 0')
        ->assertSuccessful();

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE);
    expect($attempt->failure_reason)->toContain('idempotency retention window');
    expect($user->fresh()->credits)->toBe(0);
});

test('Phase 2: Artisan paypal:reconcile-credit-captures marks VOIDED order as terminal_failed', function () {
    $user = makeUser();
    $attempt = makeAttempt($user, [
        'status' => CreditCheckoutAttempt::STATUS_CAPTURE_RECONCILIATION_REQUIRED,
        'provider_order_id' => 'ORDER-RECON-VOIDED-001',
    ]);

    $voidedOrder = [
        'id' => 'ORDER-RECON-VOIDED-001',
        'status' => 'VOIDED',
    ];

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->with('ORDER-RECON-VOIDED-001')->once()->andReturn($voidedOrder);
    $mockPayPal->shouldNotReceive('captureOrder');
    $this->instance(PayPalService::class, $mockPayPal);

    $this->artisan('paypal:reconcile-credit-captures')
        ->assertSuccessful();

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_TERMINAL_FAILED);
    expect($attempt->failure_reason)->toContain('VOIDED');
    expect($user->fresh()->credits)->toBe(0);
});

test('Phase 2: CreditCaptureService enforces correlation between attempt and provider_order_id', function () {
    $user = makeUser();
    $attempt = makeAttempt($user, [
        'provider_order_id' => 'ORDER-REGISTERED-001',
    ]);

    $service = app(CreditCaptureService::class);

    expect(fn () => $service->fulfillWithAttempt(
        user: $user,
        attempt: $attempt,
        capture: [
            'id' => 'CAPTURE-MISMATCH-001',
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
        ],
        captureId: 'CAPTURE-MISMATCH-001',
        orderId: 'ORDER-DIFFERENT-002',
    ))->toThrow(RuntimeException::class, 'Attempt order ID mismatch.');
});

test('Phase 2: PayPalController::success handles legacy 2-part custom_id', function () {
    $user = makeUser();

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->with('ORDER-SUCCESS-LEGACY-001')->once()->andReturn([
        'id' => 'ORDER-SUCCESS-LEGACY-001',
        'status' => 'COMPLETED',
        'purchase_units' => [
            [
                'reference_id' => '1-credit',
                'custom_id' => "{$user->id}|1-credit",
                'payments' => [
                    'captures' => [
                        [
                            'id' => 'CAPTURE-SUCCESS-LEGACY-001',
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
                            'create_time' => '2026-01-01T12:00:00Z',
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $this->instance(PayPalService::class, $mockPayPal);

    $this->actingAs($user)
        ->get(route('checkout.paypal.success', ['order_id' => 'ORDER-SUCCESS-LEGACY-001']))
        ->assertOk();
});

test('Phase 2: PayPalController::success handles 3-part custom_id with attempt_uuid', function () {
    $user = makeUser();
    $attemptUuid = (string) Str::uuid();

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('getOrder')->with('ORDER-SUCCESS-3PART-001')->once()->andReturn([
        'id' => 'ORDER-SUCCESS-3PART-001',
        'status' => 'COMPLETED',
        'purchase_units' => [
            [
                'reference_id' => '1-credit',
                'custom_id' => "{$user->id}|1-credit|{$attemptUuid}",
                'payments' => [
                    'captures' => [
                        [
                            'id' => 'CAPTURE-SUCCESS-3PART-001',
                            'status' => 'COMPLETED',
                            'amount' => ['currency_code' => 'GBP', 'value' => '19.99'],
                            'create_time' => '2026-01-01T12:00:00Z',
                        ],
                    ],
                ],
            ],
        ],
    ]);
    $this->instance(PayPalService::class, $mockPayPal);

    $this->actingAs($user)
        ->get(route('checkout.paypal.success', ['order_id' => 'ORDER-SUCCESS-3PART-001']))
        ->assertOk();
});
