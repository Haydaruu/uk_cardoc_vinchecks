<?php

use App\Models\CreditTransaction;
use App\Models\GuestReportPurchase;
use App\Models\RefundRecord;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Admin\AdminRefundService;
use App\Services\AdminAuditService;
use App\Services\PayPalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Stripe\Exception\InvalidRequestException;
use Stripe\Refund;
use Stripe\Service\RefundService as StripeRefundApi;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'is_suspended' => false,
    ]);

    $this->customer = User::factory()->create([
        'role' => 'user',
        'credits' => 5,
        'is_suspended' => false,
    ]);
});

test('credit purchase eligibility: fail-closed if usage exists after purchase', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-TEST-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_test_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    // Grant ledger
    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_test_001',
        'created_at' => now()->subDay(),
    ]);

    $refundService = app(AdminRefundService::class);

    // Initial check: eligible
    $eligibility = $refundService->checkEligibility($tx);
    expect($eligibility['eligible'])->toBeTrue()
        ->and($eligibility['credits_to_deduct'])->toBe(5)
        ->and($eligibility['amount_minor'])->toBe(1999);

    // Now record a usage transaction after the purchase
    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'usage',
        'amount' => -1,
        'balance_after' => 4,
        'created_at' => now()->subHours(2),
    ]);
    $this->customer->update(['credits' => 4]);

    // Re-check: must be rejected fail-closed!
    $eligibilityAfterUsage = $refundService->checkEligibility($tx);
    expect($eligibilityAfterUsage['eligible'])->toBeFalse()
        ->and($eligibilityAfterUsage['reason'])->toContain('Credits from this purchase have already been utilized');
});

test('guest report eligibility: only paid and permanently failed fulfillment is eligible', function () {
    $refundService = app(AdminRefundService::class);

    $vehicle = Vehicle::create([
        'registration_number' => 'REFUND-TEST',
    ]);

    $report = Report::create([
        'vehicle_id' => $vehicle->id,
        'report_type' => 'basic',
    ]);

    // 1. Completed report -> ineligible
    $completedPurchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Guest User',
        'email' => 'guest@example.com',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'payment_provider' => 'stripe',
        'provider_payment_id' => 'pi_guest_001',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
        'fulfilled_at' => now(),
    ]);

    expect($refundService->checkEligibility($completedPurchase)['eligible'])->toBeFalse();

    // 2. Processing report -> ineligible
    $processingPurchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Guest User 2',
        'email' => 'guest2@example.com',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'payment_provider' => 'stripe',
        'provider_payment_id' => 'pi_guest_002',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PROCESSING,
        'fulfilled_at' => null,
    ]);

    expect($refundService->checkEligibility($processingPurchase)['eligible'])->toBeFalse();

    // 3. Failed report, never fulfilled -> ELIGIBLE
    $failedPurchase = GuestReportPurchase::create([
        'report_id' => $report->id,
        'name' => 'Guest User 3',
        'email' => 'guest3@example.com',
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'payment_provider' => 'stripe',
        'provider_payment_id' => 'pi_guest_003',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
        'fulfilled_at' => null,
    ]);

    $result = $refundService->checkEligibility($failedPurchase);
    expect($result['eligible'])->toBeTrue()
        ->and($result['amount_minor'])->toBe(1999)
        ->and($result['credits_to_deduct'])->toBe(0);
});

test('successful refund: two-phase hold is settled, immutable hold row, source marked refunded', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-TEST-002',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_test_002',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_test_002',
        'created_at' => now(),
    ]);

    // Mock StripeClient
    $mockStripe = Mockery::mock(StripeClient::class);
    $mockRefunds = Mockery::mock(StripeRefundApi::class);
    $mockStripe->refunds = $mockRefunds;

    $mockRefundObj = new Refund('re_test_999');
    $mockRefunds->shouldReceive('create')
        ->once()
        ->withArgs(function ($params, $opts) {
            return $params['payment_intent'] === 'pi_test_002'
                && isset($opts['idempotency_key']);
        })
        ->andReturn($mockRefundObj);

    $refundService = new AdminRefundService(
        auditService: app(AdminAuditService::class),
        payPalService: app(PayPalService::class),
        stripeClient: $mockStripe,
    );

    $idempotencyKey = (string) Str::uuid();

    $record = $refundService->executeRefund(
        admin: $this->admin,
        source: $tx,
        reason: 'Customer requested cancellation before usage.',
        idempotencyKey: $idempotencyKey,
    );

    // Verify RefundRecord
    expect($record->status)->toBe(RefundRecord::STATUS_SUCCESS)
        ->and($record->refund_gateway_ref)->toBe('re_test_999')
        ->and($record->amount_minor)->toBe(1999);

    // Verify source transaction updated
    $tx->refresh();
    expect($tx->status)->toBe('refunded')
        ->and($tx->refunded_at)->not->toBeNull();

    // Verify customer credits deducted
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(0);

    // Verify immutable hold ledger row
    $holdTx = CreditTransaction::where('reference_id', "refund_hold:{$record->id}")->first();
    expect($holdTx)->not->toBeNull()
        ->and($holdTx->type)->toBe('refund')
        ->and($holdTx->amount)->toBe(-5)
        ->and($holdTx->balance_after)->toBe(0);

    // Verify NO reversal row created on success
    $reversalCount = CreditTransaction::where('reference_id', "refund_reversal:{$record->id}")->count();
    expect($reversalCount)->toBe(0);
});

test('definitive gateway failure: hold is reversed with separate immutable reversal row and credits restored', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-TEST-003',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_test_003',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_test_003',
        'created_at' => now(),
    ]);

    // Mock StripeClient throwing a 400 Bad Request (definitive rejection)
    $mockStripe = Mockery::mock(StripeClient::class);
    $mockRefunds = Mockery::mock(StripeRefundApi::class);
    $mockStripe->refunds = $mockRefunds;

    $mockRefunds->shouldReceive('create')
        ->once()
        ->andThrow(InvalidRequestException::factory('Charge already refunded.', 400));

    $refundService = new AdminRefundService(
        auditService: app(AdminAuditService::class),
        payPalService: app(PayPalService::class),
        stripeClient: $mockStripe,
    );

    $idempotencyKey = (string) Str::uuid();

    $record = $refundService->executeRefund(
        admin: $this->admin,
        source: $tx,
        reason: 'Refund attempt.',
        idempotencyKey: $idempotencyKey,
    );

    // Verify RefundRecord is failed
    expect($record->status)->toBe(RefundRecord::STATUS_FAILED)
        ->and($record->error_message)->toContain('Charge already refunded');

    // Verify source transaction remains success (NOT marked refunded)
    $tx->refresh();
    expect($tx->status)->toBe('success')
        ->and($tx->refunded_at)->toBeNull();

    // Verify customer credits restored back to 5
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(5);

    // Verify hold row remains untouched (immutable)
    $holdTx = CreditTransaction::where('reference_id', "refund_hold:{$record->id}")->first();
    expect($holdTx)->not->toBeNull()
        ->and($holdTx->amount)->toBe(-5);

    // Verify separate reversal row exists with type 'refund' and amount +5
    $reversalTx = CreditTransaction::where('reference_id', "refund_reversal:{$record->id}")->first();
    expect($reversalTx)->not->toBeNull()
        ->and($reversalTx->type)->toBe('refund')
        ->and($reversalTx->amount)->toBe(5)
        ->and($reversalTx->balance_after)->toBe(5);
});

test('gateway timeout: status becomes unknown, credit hold is MAINTAINED (not restored)', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-TEST-004',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_test_004',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_test_004',
        'created_at' => now(),
    ]);

    // Mock StripeClient throwing a runtime timeout (NOT 4xx)
    $mockStripe = Mockery::mock(StripeClient::class);
    $mockRefunds = Mockery::mock(StripeRefundApi::class);
    $mockStripe->refunds = $mockRefunds;

    $mockRefunds->shouldReceive('create')
        ->once()
        ->andThrow(new RuntimeException('Connection timed out after 30000 milliseconds'));

    $refundService = new AdminRefundService(
        auditService: app(AdminAuditService::class),
        payPalService: app(PayPalService::class),
        stripeClient: $mockStripe,
    );

    $idempotencyKey = (string) Str::uuid();

    $record = $refundService->executeRefund(
        admin: $this->admin,
        source: $tx,
        reason: 'Refund attempt with network lag.',
        idempotencyKey: $idempotencyKey,
    );

    // Status must be unknown
    expect($record->status)->toBe(RefundRecord::STATUS_UNKNOWN);

    // CRITICAL DECISION 2: Credits MUST REMAIN HELD (NOT restored)
    $this->customer->refresh();
    expect($this->customer->credits)->toBe(0);

    // Reversal row MUST NOT exist
    $reversalCount = CreditTransaction::where('reference_id', "refund_reversal:{$record->id}")->count();
    expect($reversalCount)->toBe(0);
});

test('single active refund concurrency: second request with different idempotency key rejected with 409', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-TEST-005',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_test_005',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'pi_test_005',
        'created_at' => now(),
    ]);

    // Simulate an existing in-flight workflow with Key A
    $keyA = (string) Str::uuid();
    RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_test_005',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_PROCESSING,
        'idempotency_key' => $keyA,
        'reason' => 'First admin initiated refund.',
    ]);

    $refundService = app(AdminRefundService::class);

    // Admin B attempts with different Key B
    $keyB = (string) Str::uuid();

    expect(fn () => $refundService->executeRefund(
        admin: $this->admin,
        source: $tx,
        reason: 'Second admin parallel attempt.',
        idempotencyKey: $keyB,
    ))->toThrow(HttpException::class, 'A refund workflow (processing) is already active or completed for this transaction.');
});

test('idempotent replay: same idempotency key returns existing record without duplicate execution', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-TEST-006',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'pi_test_006',
        'status' => 'refunded',
        'paid_at' => now(),
        'refunded_at' => now(),
    ]);

    $key = (string) Str::uuid();
    $existing = RefundRecord::create([
        'refundable_type' => Transaction::class,
        'refundable_id' => $tx->id,
        'admin_id' => $this->admin->id,
        'gateway' => 'stripe',
        'payment_reference' => 'pi_test_006',
        'refund_gateway_ref' => 're_existing_123',
        'amount_minor' => 1999,
        'currency' => 'GBP',
        'status' => RefundRecord::STATUS_SUCCESS,
        'idempotency_key' => $key,
        'reason' => 'Already refunded earlier.',
    ]);

    $refundService = app(AdminRefundService::class);

    $result = $refundService->executeRefund(
        admin: $this->admin,
        source: $tx,
        reason: 'Already refunded earlier.',
        idempotencyKey: $key,
    );

    expect($result->id)->toBe($existing->id)
        ->and($result->status)->toBe(RefundRecord::STATUS_SUCCESS);
});

test('subscription charge eligibility: fail-closed if usage exists after invoice', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-SUB-001',
        'currency' => 'GBP',
        'amount' => 39.99,
        'type' => 'payment',
        'category' => 'subscription',
        'payment_method' => 'stripe',
        'payment_gateway_ref' => 'in_test_sub_001',
        'status' => 'success',
        'paid_at' => now()->subDay(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'subscription',
        'amount' => 15,
        'balance_after' => 15,
        'reference_id' => 'in_test_sub_001',
        'created_at' => now()->subDay(),
    ]);
    $this->customer->update(['credits' => 15]);

    $refundService = app(AdminRefundService::class);

    // Initial check: eligible
    $result = $refundService->checkEligibility($tx);
    expect($result['eligible'])->toBeTrue()
        ->and($result['credits_to_deduct'])->toBe(15)
        ->and($result['amount_minor'])->toBe(3999);

    // Now record usage after the invoice
    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'usage',
        'amount' => -1,
        'balance_after' => 14,
        'created_at' => now()->subHours(1),
    ]);
    $this->customer->update(['credits' => 14]);

    // Re-check: must fail-closed
    $afterUsage = $refundService->checkEligibility($tx);
    expect($afterUsage['eligible'])->toBeFalse()
        ->and($afterUsage['reason'])->toContain('Subscription benefits have already been utilized');
});

test('paypal refund execution: calls paypalService refundCapture with 2-decimal string format', function () {
    $tx = Transaction::create([
        'user_id' => $this->customer->id,
        'invoice_id' => 'UKC-PPL-001',
        'currency' => 'GBP',
        'amount' => 19.99,
        'type' => 'payment',
        'category' => 'credit_purchase',
        'payment_method' => 'paypal',
        'payment_gateway_ref' => 'capture_ppl_123',
        'status' => 'success',
        'paid_at' => now(),
    ]);

    CreditTransaction::create([
        'user_id' => $this->customer->id,
        'type' => 'purchase',
        'amount' => 5,
        'balance_after' => 5,
        'reference_id' => 'capture_ppl_123',
        'created_at' => now(),
    ]);

    $mockPayPal = Mockery::mock(PayPalService::class);
    $mockPayPal->shouldReceive('refundCapture')
        ->once()
        ->withArgs(function ($captureId, $amount, $currency, $reason, $key) {
            return $captureId === 'capture_ppl_123'
                && $amount === '19.99'
                && $currency === 'GBP'
                && ! empty($key);
        })
        ->andReturn(['id' => 'ppl_refund_999', 'status' => 'COMPLETED']);

    $refundService = new AdminRefundService(
        auditService: app(AdminAuditService::class),
        payPalService: $mockPayPal,
    );

    $key = (string) Str::uuid();

    $record = $refundService->executeRefund(
        admin: $this->admin,
        source: $tx,
        reason: 'PayPal customer requested full refund.',
        idempotencyKey: $key,
    );

    expect($record->status)->toBe(RefundRecord::STATUS_SUCCESS)
        ->and($record->refund_gateway_ref)->toBe('ppl_refund_999')
        ->and($record->amount_minor)->toBe(1999);

    $tx->refresh();
    expect($tx->status)->toBe('refunded');
});
