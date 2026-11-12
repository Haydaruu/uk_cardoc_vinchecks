<?php

use App\Models\CreditCheckoutAttempt;
use App\Models\User;
use App\Services\CreditCheckoutService;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

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

    Cache::forget('paypal_access_token_sandbox');
});

test('idempotency: same intent returns same attempt and same order id without duplicate paypal calls', function () {
    $user = User::factory()->create();

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->with(
            Mockery::on(fn ($u) => $u->id === $user->id),
            '1-credit',
            Mockery::type(CreditCheckoutAttempt::class)
        )
        ->andReturn([
            'id' => 'ORDER-IDEMP-001',
            'status' => 'CREATED',
        ]);

    $this->instance(PayPalService::class, $mockPayPal);

    $intent = 'intent-uuid-test-1111';

    // First request
    $response1 = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => $intent,
        ]);

    $response1->assertOk()
        ->assertJson([
            'id' => 'ORDER-IDEMP-001',
        ]);

    $attemptId1 = $response1->json('attempt_id');
    expect($attemptId1)->not->toBeEmpty();

    // Second request with same intent
    $response2 = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => $intent,
        ]);

    $response2->assertOk()
        ->assertExactJson([
            'id' => 'ORDER-IDEMP-001',
            'attempt_id' => $attemptId1,
        ]);

    // DB verify only 1 attempt record
    expect(CreditCheckoutAttempt::where('user_id', $user->id)->count())->toBe(1);

    $attempt = CreditCheckoutAttempt::where('user_id', $user->id)->first();
    expect($attempt->provider_order_id)->toBe('ORDER-IDEMP-001')
        ->and($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_PROVIDER_CREATED)
        ->and($attempt->invoice_id)->toStartWith('UKC-CR-');
});

test('validation: same intent reference with different plan is rejected with 422', function () {
    $user = User::factory()->create();

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->andReturn(['id' => 'ORDER-PLAN-1', 'status' => 'CREATED']);

    $this->instance(PayPalService::class, $mockPayPal);

    $intent = 'intent-fixed-123';

    // First purchase attempt: 1-credit
    $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => $intent,
        ])
        ->assertOk();

    // Second request with same intent but 3-credits plan
    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '3-credits',
            'intent_reference' => $intent,
        ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['intent_reference']);
});

test('isolation: different intent references create distinct attempts and distinct orders', function () {
    $user = User::factory()->create();

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('createOrder')
        ->twice()
        ->andReturn(
            ['id' => 'ORDER-DISTINCT-001', 'status' => 'CREATED'],
            ['id' => 'ORDER-DISTINCT-002', 'status' => 'CREATED'],
        );

    $this->instance(PayPalService::class, $mockPayPal);

    $response1 = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-first',
        ])
        ->assertOk();

    $response2 = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-second',
        ])
        ->assertOk();

    expect($response1->json('id'))->toBe('ORDER-DISTINCT-001')
        ->and($response2->json('id'))->toBe('ORDER-DISTINCT-002')
        ->and($response1->json('attempt_id'))->not->toBe($response2->json('attempt_id'));

    expect(CreditCheckoutAttempt::where('user_id', $user->id)->count())->toBe(2);
});

test('session fallback: preserves intent across requests when frontend omits intent_reference', function () {
    $user = User::factory()->create();

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->andReturn(['id' => 'ORDER-SESSION-001', 'status' => 'CREATED']);

    $this->instance(PayPalService::class, $mockPayPal);

    // Initial request without intent_reference
    $response1 = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
        ]);

    $response1->assertOk();
    $attemptId = $response1->json('attempt_id');

    // Follow-up request in the same session without intent_reference
    $response2 = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
        ]);

    $response2->assertOk()
        ->assertExactJson([
            'id' => 'ORDER-SESSION-001',
            'attempt_id' => $attemptId,
        ]);

    expect(CreditCheckoutAttempt::where('user_id', $user->id)->count())->toBe(1);
});

test('snapshot immutability: snapshot values are preserved when credit_plans config dynamically changes', function () {
    $user = User::factory()->create();

    $capturedSnapshot = null;

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->with(
            Mockery::any(),
            '1-credit',
            Mockery::on(function ($attempt) use (&$capturedSnapshot) {
                $capturedSnapshot = [
                    'amount_minor' => $attempt->amount_minor,
                    'credits' => $attempt->credits_to_grant,
                    'currency' => $attempt->currency,
                    'invoice_id' => $attempt->invoice_id,
                ];

                return true;
            })
        )
        ->andReturn(['id' => 'ORDER-SNAPSHOT-001', 'status' => 'CREATED']);

    $this->instance(PayPalService::class, $mockPayPal);

    // Create attempt with baseline config (1999 minor, 1 credit)
    $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-snapshot',
        ])
        ->assertOk();

    expect($capturedSnapshot['amount_minor'])->toBe(1999)
        ->and($capturedSnapshot['credits'])->toBe(1)
        ->and($capturedSnapshot['currency'])->toBe('GBP')
        ->and($capturedSnapshot['invoice_id'])->toStartWith('UKC-CR-');

    // Dynamically change config
    config(['credit_plans.1-credit.amount_minor' => 99999]);
    config(['credit_plans.1-credit.credits' => 100]);

    // DB attempt record still has immutable original snapshot
    $attempt = CreditCheckoutAttempt::where('intent_reference', 'intent-snapshot')->first();
    expect($attempt->amount_minor)->toBe(1999)
        ->and($attempt->credits_to_grant)->toBe(1);
});

test('concurrency lock: returns 202 processing status when lock cannot be acquired within wait timeout', function () {
    $user = User::factory()->create();

    // Pre-create attempt to get its uuid
    $checkoutService = app(CreditCheckoutService::class);
    $attempt = $checkoutService->getOrCreateAttempt($user, '1-credit', 'intent-lock-test');

    // Hold the distributed lock externally
    $lockKey = "paypal-order:attempt:{$attempt->attempt_uuid}";
    $externalLock = Cache::lock($lockKey, 45);
    $acquired = $externalLock->acquire();
    expect($acquired)->toBeTrue();

    try {
        $response = $this->actingAs($user)
            ->postJson(route('checkout.paypal.create-order'), [
                'plan' => '1-credit',
                'intent_reference' => 'intent-lock-test',
            ]);

        $response->assertStatus(202)
            ->assertJson([
                'status' => 'processing',
                'attempt_id' => $attempt->attempt_uuid,
                'retry_after' => 2,
            ]);
    } finally {
        $externalLock->release();
    }
});

test('ambiguous recovery: retrying after failure within 6 hours reuses attempt and succeeds', function () {
    $user = User::factory()->create();

    $mockPayPal = Mockery::mock(PayPalService::class);

    // First call throws RuntimeException (e.g. PayPal network timeout)
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->andThrow(new RuntimeException('PayPal connection timed out.'));

    // Second call succeeds
    $mockPayPal->shouldReceive('createOrder')
        ->once()
        ->andReturn(['id' => 'ORDER-RECOVERED-001', 'status' => 'CREATED']);

    $this->instance(PayPalService::class, $mockPayPal);

    $intent = 'intent-ambiguous-recovery';

    // First attempt fails with 502
    $response1 = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => $intent,
        ]);

    $response1->assertStatus(502);

    $attempt = CreditCheckoutAttempt::where('user_id', $user->id)->first();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_CREATE_AMBIGUOUS)
        ->and($attempt->failure_reason)->toContain('timed out');

    // Second attempt within 6 hours succeeds
    $response2 = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => $intent,
        ]);

    $response2->assertOk()
        ->assertJson([
            'id' => 'ORDER-RECOVERED-001',
            'attempt_id' => $attempt->attempt_uuid,
        ]);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_PROVIDER_CREATED)
        ->and($attempt->provider_order_id)->toBe('ORDER-RECOVERED-001')
        ->and($attempt->failure_reason)->toBeNull();
});

test('idempotency retention: ambiguous attempt older than 6 hours is marked unreconciled_stale and rejected', function () {
    $user = User::factory()->create();

    $attempt = CreditCheckoutAttempt::create([
        'user_id' => $user->id,
        'intent_reference' => 'intent-stale-test',
        'attempt_uuid' => (string) Str::uuid(),
        'invoice_id' => 'UKC-CR-STALE12345',
        'plan_slug' => '1-credit',
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_CREATE_AMBIGUOUS,
        'expires_at' => now()->subHours(5),
    ]);

    // Force created_at in DB to 7 hours ago to bypass Eloquent freshTimestamp
    CreditCheckoutAttempt::where('id', $attempt->id)->update([
        'created_at' => now()->subHours(7),
        'updated_at' => now()->subHours(7),
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-stale-test',
        ]);

    $response->assertStatus(502);

    $attempt->refresh();
    expect($attempt->status)->toBe(CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE);
});

test('already completed attempt cannot be re-initiated', function () {
    $user = User::factory()->create();

    CreditCheckoutAttempt::create([
        'user_id' => $user->id,
        'intent_reference' => 'intent-completed-test',
        'attempt_uuid' => (string) Str::uuid(),
        'invoice_id' => 'UKC-CR-DONE12345',
        'plan_slug' => '1-credit',
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
        'provider_order_id' => 'ORDER-ALREADY-DONE',
        'provider_capture_id' => 'CAPTURE-ALREADY-DONE',
        'expires_at' => now()->addMinutes(30),
    ]);

    $response = $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => '1-credit',
            'intent_reference' => 'intent-completed-test',
        ]);

    $response->assertStatus(502);
});

test('unauthenticated user cannot create paypal credit order', function () {
    $this->postJson(route('checkout.paypal.create-order'), [
        'plan' => '1-credit',
    ])->assertUnauthorized();
});

test('invalid plan returns 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('checkout.paypal.create-order'), [
            'plan' => 'non-existent-plan',
        ])
        ->assertUnprocessable()
        ->assertJson([
            'message' => 'Invalid credit plan.',
        ]);
});

test('pure integer arithmetic formatting in PayPalService::createOrder', function () {
    $user = User::factory()->create();

    $attempt = CreditCheckoutAttempt::create([
        'user_id' => $user->id,
        'intent_reference' => 'intent-calc-test',
        'attempt_uuid' => (string) Str::uuid(),
        'invoice_id' => 'UKC-CR-CALC12345',
        'plan_slug' => '1-credit',
        'credits_to_grant' => 1,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => CreditCheckoutAttempt::STATUS_INITIATED,
        'expires_at' => now()->addMinutes(30),
    ]);

    Http::fake([
        '*/v1/oauth2/token' => Http::response([
            'access_token' => 'fake-access-token',
            'expires_in' => 3600,
        ], 200),
        '*/v2/checkout/orders' => function (Request $request) use ($attempt) {
            expect($request->header('PayPal-Request-Id'))->toBe(['credit-order-'.$attempt->attempt_uuid]);

            $payload = $request->data();
            expect($payload['purchase_units'][0]['amount']['value'])->toBe('19.99')
                ->and($payload['purchase_units'][0]['amount']['currency_code'])->toBe('GBP')
                ->and($payload['purchase_units'][0]['invoice_id'])->toBe($attempt->invoice_id);

            return Http::response([
                'id' => 'ORDER-PPL-INT-001',
                'status' => 'CREATED',
            ], 201);
        },
    ]);

    $service = app(PayPalService::class);
    $result = $service->createOrder($user, '1-credit', $attempt);

    expect($result['id'])->toBe('ORDER-PPL-INT-001')
        ->and($result['status'])->toBe('CREATED');
});
