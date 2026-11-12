<?php

namespace App\Jobs;

use App\Exceptions\PayPalCaptureDeniedException;
use App\Exceptions\PayPalCapturePendingException;
use App\Models\CreditCheckoutAttempt;
use App\Services\CreditCaptureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPayPalOrderCapture implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $uniqueFor = 3600;

    public function __construct(
        public string $orderId,
        public ?string $eventId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'paypal-order-capture:'.$this->orderId;
    }

    public function handle(CreditCaptureService $captureService): void
    {
        Log::info('ProcessPayPalOrderCapture: executing queued capture', [
            'order_id' => $this->orderId,
            'event_id' => $this->eventId,
        ]);

        try {
            $captureService->captureOrderAndFulfill(
                orderId: $this->orderId,
                user: null,
            );
        } catch (PayPalCapturePendingException $e) {
            Log::info('ProcessPayPalOrderCapture: capture is pending', [
                'order_id' => $this->orderId,
                'message' => $e->getMessage(),
            ]);
            // Pending is a valid provider status; do not re-throw to prevent unnecessary retry churn.
        } catch (PayPalCaptureDeniedException $e) {
            Log::warning('ProcessPayPalOrderCapture: capture was declined or failed', [
                'order_id' => $this->orderId,
                'capture_status' => $e->getCaptureStatus(),
                'message' => $e->getMessage(),
            ]);
            // Terminal failure is persisted on attempt record; do not retry.
        }
    }

    /**
     * Handle job permanent failure when all retries are exhausted.
     * Transitions attempt to a recoverable reconciliation status.
     * Never marks terminal_failed for transient queue/network failures.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessPayPalOrderCapture: job exhausted all retries. Transitioning attempt to capture_reconciliation_required.', [
            'order_id' => $this->orderId,
            'event_id' => $this->eventId,
            'error' => $exception?->getMessage(),
        ]);

        CreditCheckoutAttempt::query()
            ->where('provider_order_id', $this->orderId)
            ->whereNotIn('status', [
                CreditCheckoutAttempt::STATUS_CAPTURE_COMPLETED,
                CreditCheckoutAttempt::STATUS_TERMINAL_FAILED,
                CreditCheckoutAttempt::STATUS_UNRECONCILED_STALE,
            ])
            ->update([
                'status' => CreditCheckoutAttempt::STATUS_CAPTURE_RECONCILIATION_REQUIRED,
                'failure_reason' => 'Queue capture job exhausted max retries: '.substr((string) $exception?->getMessage(), 0, 400),
            ]);
    }
}
