<?php

use App\Jobs\ProcessVehicleCheck;
use App\Jobs\SendTelegramSaleNotification;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\Vehicle;
use App\Models\VinCheck;
use App\Services\GuestReportPaymentService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function createPayPalGuestPurchaseForServiceTest(): array
{
    $registration = 'PPLGST'.strtoupper(bin2hex(random_bytes(4)));

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
        'name' => 'PayPal Test Guest',
        'email' => 'paypal-guest@example.com',
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

function createPayPalGuestOrderForServiceTest(
    GuestReportPurchase $purchase,
    string $captureId = 'CAPTURE-GUEST-001',
    string $amount = '21.99',
): array {
    return [
        'id' => $purchase->provider_order_id,
        'intent' => 'CAPTURE',
        'status' => 'COMPLETED',
        'purchase_units' => [
            [
                'reference_id' => (string) $purchase->id,
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
                            'id' => $captureId,
                            'status' => 'COMPLETED',
                            'amount' => [
                                'value' => $amount,
                                'currency_code' => 'GBP',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}

test('paypal guest payment marks purchase paid and dispatches fulfillment', function () {
    // Configure an async driver so enqueueVehicleCheck() reaches ProcessVehicleCheck::dispatch().
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $order = createPayPalGuestOrderForServiceTest($purchase);

    $result = app(GuestReportPaymentService::class)
        ->handlePayPalGuestOrder($order);

    $purchase->refresh();

    expect($result)->toBeTrue()
        ->and($purchase->payment_status)
        ->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->provider_payment_id)
        ->toBe('CAPTURE-GUEST-001')
        ->and($purchase->capture_status)
        ->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->fulfillment_status)
        ->toBe(GuestReportPurchase::FULFILLMENT_PENDING)
        ->and($purchase->paid_at)
        ->not->toBeNull();

    Queue::assertPushed(ProcessVehicleCheck::class, 1);

    Queue::assertPushed(
        SendTelegramSaleNotification::class,
        fn (SendTelegramSaleNotification $job): bool => $job->saleType === 'Guest Report'
            && $job->paymentProvider === 'PayPal'
            && $job->reference === 'CAPTURE-GUEST-001'
            && $job->amount === 'GBP 21.99'
            && $job->purchaseId === $purchase->id
            && $job->customerName === $purchase->name
            && $job->customerPhone === $purchase->phone
            && $job->reportUrl === route('admin.reports.index', [
                'search' => (string) $purchase->report_id,
            ])
    );
});

test('paypal guest payment does not duplicate an already queued fulfillment', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $order = createPayPalGuestOrderForServiceTest($purchase);

    $service = app(GuestReportPaymentService::class);

    $firstResult = $service->handlePayPalGuestOrder($order);
    $secondResult = $service->handlePayPalGuestOrder($order);

    expect($firstResult)->toBeTrue()
        ->and($secondResult)->toBeFalse();

    $purchase->refresh();

    expect($purchase->payment_status)
        ->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->provider_payment_id)
        ->toBe('CAPTURE-GUEST-001');

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('paypal guest payment rejects a different capture reference', function () {
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'provider_payment_id' => 'CAPTURE-ORIGINAL-001',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
        'paid_at' => now(),
    ]);

    $order = createPayPalGuestOrderForServiceTest(
        $purchase,
        'CAPTURE-DIFFERENT-001',
    );

    expect(
        fn () => app(GuestReportPaymentService::class)
            ->handlePayPalGuestOrder($order)
    )->toThrow(
        RuntimeException::class,
        'PayPal capture reference mismatch.'
    );

    $purchase->refresh();

    expect($purchase->provider_payment_id)
        ->toBe('CAPTURE-ORIGINAL-001');

    Queue::assertNothingPushed();
});

test('paypal duplicate callback does not reprocess a completed premium report', function () {
    Queue::fake();

    [$purchase, $report] = createPayPalGuestPurchaseForServiceTest();

    $report->update([
        'report_type' => 'premium',
    ]);

    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'provider_payment_id' => 'CAPTURE-GUEST-001',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_COMPLETED,
        'paid_at' => now(),
        'fulfilled_at' => now(),
    ]);

    $order = createPayPalGuestOrderForServiceTest($purchase);

    $result = app(GuestReportPaymentService::class)
        ->handlePayPalGuestOrder($order);

    expect($result)->toBeFalse();

    $purchase->refresh();

    expect($purchase->capture_status)
        ->toBe(GuestReportPurchase::CAPTURE_COMPLETED);

    Queue::assertNothingPushed();
});

test('paypal guest payment can redispatch a failed fulfillment', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'provider_payment_id' => 'CAPTURE-GUEST-001',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_FAILED,
        'paid_at' => now(),
    ]);

    $order = createPayPalGuestOrderForServiceTest($purchase);

    $result = app(GuestReportPaymentService::class)
        ->handlePayPalGuestOrder($order);

    expect($result)->toBeFalse();

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('repeated settlement with the same capture ID repairs missing capture_status safely without updating last_reconciled_at', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'provider_payment_id' => 'CAPTURE-GUEST-001',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'last_reconciled_at' => null,
        'paid_at' => now(),
    ]);

    $order = createPayPalGuestOrderForServiceTest($purchase, 'CAPTURE-GUEST-001');

    $result = app(GuestReportPaymentService::class)
        ->handlePayPalGuestOrder($order);

    expect($result)->toBeFalse();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-GUEST-001')
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->last_reconciled_at)->toBeNull();
});

test('repeated settlement with conflicting capture ID is rejected and does not overwrite existing data', function () {
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $purchase->update([
        'payment_status' => GuestReportPurchase::STATUS_PAID,
        'provider_payment_id' => 'CAPTURE-ORIGINAL-001',
        'fulfillment_status' => GuestReportPurchase::FULFILLMENT_PENDING,
        'capture_status' => GuestReportPurchase::CAPTURE_CAPTURING,
        'paid_at' => now(),
    ]);

    $order = createPayPalGuestOrderForServiceTest($purchase, 'CAPTURE-CONFLICTING-999');

    expect(
        fn () => app(GuestReportPaymentService::class)
            ->handlePayPalGuestOrder($order)
    )->toThrow(
        RuntimeException::class,
        'PayPal capture reference mismatch.'
    );

    $purchase->refresh();

    expect($purchase->provider_payment_id)->toBe('CAPTURE-ORIGINAL-001')
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_CAPTURING)
        ->and($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID);

    Queue::assertNothingPushed();
});

test('order containing pending and completed captures selects the completed capture resource', function () {
    config(['queue.default' => 'database']);
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $order = createPayPalGuestOrderForServiceTest($purchase, 'CAPTURE-VALID-COMPLETED');

    // Prepend a PENDING capture at index 0 so COMPLETED is at index 1
    $order['purchase_units'][0]['payments']['captures'] = [
        [
            'id' => 'CAPTURE-EARLIER-PENDING',
            'status' => 'PENDING',
            'amount' => [
                'value' => '21.99',
                'currency_code' => 'GBP',
            ],
        ],
        [
            'id' => 'CAPTURE-VALID-COMPLETED',
            'status' => 'COMPLETED',
            'amount' => [
                'value' => '21.99',
                'currency_code' => 'GBP',
            ],
        ],
    ];

    $result = app(GuestReportPaymentService::class)
        ->handlePayPalGuestOrder($order);

    expect($result)->toBeTrue();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-VALID-COMPLETED')
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);

    Queue::assertPushed(ProcessVehicleCheck::class, 1);
});

test('order without a completed capture is rejected', function () {
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $order = createPayPalGuestOrderForServiceTest($purchase);

    // Only PENDING capture
    $order['purchase_units'][0]['payments']['captures'] = [
        [
            'id' => 'CAPTURE-ONLY-PENDING',
            'status' => 'PENDING',
            'amount' => [
                'value' => '21.99',
                'currency_code' => 'GBP',
            ],
        ],
    ];

    expect(
        fn () => app(GuestReportPaymentService::class)
            ->handlePayPalGuestOrder($order)
    )->toThrow(
        RuntimeException::class,
        'PayPal capture is not completed.'
    );

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING)
        ->and($purchase->capture_status)->toBeNull()
        ->and($purchase->provider_payment_id)->toBeNull();

    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------------------
// Sync-driver behaviour
// ---------------------------------------------------------------------------

test('sync queue driver skips ProcessVehicleCheck dispatch without throwing after payment is PAID', function () {
    // phpunit.xml sets QUEUE_CONNECTION=sync — do not override here.
    // Verify that enqueueVehicleCheck() silently skips dispatch rather than
    // throwing an exception that could break capture attempt tracking.

    [$purchase] = createPayPalGuestPurchaseForServiceTest();
    $order = createPayPalGuestOrderForServiceTest($purchase, 'CAPTURE-SYNC-SKIP');

    // No Queue::fake() — we want the real sync-driver guard to run.
    $result = app(GuestReportPaymentService::class)->handlePayPalGuestOrder($order);

    expect($result)->toBeTrue();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-SYNC-SKIP')
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
});

test('sync queue driver skip leaves capture attempt tracking unaffected', function () {
    // The purpose of this test is to confirm that when the sync driver guard
    // skips ProcessVehicleCheck the method returns normally, so callers such
    // as GuestReportCaptureService::settleSuccessfulCapture() can proceed to
    // update the capture attempt status.
    //
    // We verify this by confirming handlePayPalGuestOrder() returns true
    // (payment_changed) and does NOT throw, which is the minimum contract
    // that settleSuccessfulCapture() relies on.

    [$purchase] = createPayPalGuestPurchaseForServiceTest();
    $order = createPayPalGuestOrderForServiceTest($purchase, 'CAPTURE-SYNC-ATTEMPT');

    $threw = false;

    try {
        $result = app(GuestReportPaymentService::class)->handlePayPalGuestOrder($order);
    } catch (Throwable) {
        $threw = true;
        $result = false;
    }

    expect($threw)->toBeFalse('handlePayPalGuestOrder must not throw when sync driver skips dispatch')
        ->and($result)->toBeTrue();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID);
});

// ---------------------------------------------------------------------------
// Async enqueue failure (database / redis / sqs infrastructure error)
// ---------------------------------------------------------------------------

test('async queue enqueue failure after successful payment leaves payment PAID and fulfillment PENDING', function () {
    // Configure an async driver so the sync guard does not intercept.
    config(['queue.default' => 'database']);

    [$purchase] = createPayPalGuestPurchaseForServiceTest();
    $order = createPayPalGuestOrderForServiceTest($purchase, 'CAPTURE-ENQUEUE-FAIL');

    // Simulate a queue-infrastructure failure (e.g. DB connection refused
    // while inserting the job record). The job has NOT been accepted.
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->andReturnUsing(function ($job) {
            if ($job instanceof ProcessVehicleCheck) {
                throw new RuntimeException('Queue connection refused');
            }

            return null;
        });
    app()->instance(Dispatcher::class, $dispatcher);

    $result = app(GuestReportPaymentService::class)->handlePayPalGuestOrder($order);

    expect($result)->toBeTrue();

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PAID)
        ->and($purchase->capture_status)->toBe(GuestReportPurchase::CAPTURE_COMPLETED)
        ->and($purchase->provider_payment_id)->toBe('CAPTURE-ENQUEUE-FAIL')
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_PENDING);
});

test('payment validation failures propagate normally and do not modify purchase state', function () {
    Queue::fake();

    [$purchase] = createPayPalGuestPurchaseForServiceTest();

    $order = createPayPalGuestOrderForServiceTest($purchase);
    // Tamper currency to cause validation failure before transaction commits
    $order['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code'] = 'USD';

    expect(
        fn () => app(GuestReportPaymentService::class)->handlePayPalGuestOrder($order)
    )->toThrow(
        RuntimeException::class,
        'PayPal amount or currency mismatch.'
    );

    $purchase->refresh();

    expect($purchase->payment_status)->toBe(GuestReportPurchase::STATUS_PENDING)
        ->and($purchase->capture_status)->toBeNull()
        ->and($purchase->provider_payment_id)->toBeNull()
        ->and($purchase->fulfillment_status)->toBe(GuestReportPurchase::FULFILLMENT_LOCKED);

    Queue::assertNothingPushed();
});
