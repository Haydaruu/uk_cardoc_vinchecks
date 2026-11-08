<?php

namespace App\Jobs;

use App\Models\GuestReportCaptureAttempt;
use App\Models\GuestReportPurchase;
use App\Services\GuestReportCaptureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPayPalGuestCapture implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    /** @var array<int, int> */
    public array $backoff = [10, 20, 30];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $orderId,
        public readonly ?string $eventId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'paypal-guest-capture:'.$this->orderId;
    }

    /**
     * Bounded retry deadline (180s / 3m).
     * Lock TTL is 85s; 180s provides ample buffer for queue latency and retries,
     * while guaranteeing that the job stops retrying after a finite deadline.
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addSeconds(180);
    }

    public function handle(GuestReportCaptureService $captureService): void
    {
        Log::info('ProcessPayPalGuestCapture: executing queued webhook capture', [
            'order_id' => $this->orderId,
            'event_id' => $this->eventId,
        ]);

        $result = $captureService->captureFromWebhook($this->orderId);

        // If another worker/process currently holds the distributed lock,
        // release the job with a delay so it retries after lock expiry or after the other worker finishes.
        if ($result->isProcessing()) {
            Log::info('ProcessPayPalGuestCapture: capture lock busy, releasing for delayed retry', [
                'order_id' => $this->orderId,
                'event_id' => $this->eventId,
            ]);

            $this->release(20);

            return;
        }

        Log::info('ProcessPayPalGuestCapture: capture processed', [
            'order_id' => $this->orderId,
            'event_id' => $this->eventId,
            'status' => $result->status,
            'http_status' => $result->httpStatus,
            'reconciled' => $result->reconciled,
        ]);
    }

    /**
     * Handle permanent job failure when all retries are exhausted.
     * Transitions purchase and active attempt to reconciliation_required / error
     * inside an atomic database transaction.
     * Preserves protected states (paid, completed, refunded, failed, expired)
     * and correctly handles capture_status = NULL.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessPayPalGuestCapture: job exhausted all retries. Transitioning to capture_reconciliation_required.', [
            'order_id' => $this->orderId,
            'event_id' => $this->eventId,
            'error' => $exception?->getMessage(),
        ]);

        $failureReason = 'Queue capture job exhausted max retries: '.substr((string) $exception?->getMessage(), 0, 400);

        DB::transaction(function () use ($failureReason) {
            $purchase = GuestReportPurchase::query()
                ->where('provider_order_id', $this->orderId)
                ->whereNotIn('payment_status', [
                    GuestReportPurchase::STATUS_PAID,
                    GuestReportPurchase::STATUS_REFUNDED,
                    GuestReportPurchase::PAYMENT_STATUS_FAILED,
                    GuestReportPurchase::STATUS_EXPIRED,
                ])
                ->where(function ($query) {
                    $query->whereNull('capture_status')
                        ->orWhereNotIn('capture_status', [
                            GuestReportPurchase::CAPTURE_COMPLETED,
                            GuestReportPurchase::CAPTURE_STATUS_FAILED,
                        ]);
                })
                ->lockForUpdate()
                ->first();

            if (! $purchase) {
                return;
            }

            $purchase->update([
                'capture_status' => GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
                'capture_failure_reason' => $failureReason,
            ]);

            if ($purchase->active_capture_attempt_uuid) {
                GuestReportCaptureAttempt::query()
                    ->where('guest_report_purchase_id', $purchase->id)
                    ->where('attempt_uuid', $purchase->active_capture_attempt_uuid)
                    ->whereNotIn('status', [
                        GuestReportCaptureAttempt::STATUS_COMPLETED,
                        GuestReportCaptureAttempt::STATUS_FAILED,
                    ])
                    ->update([
                        'status' => GuestReportCaptureAttempt::STATUS_ERROR,
                        'failure_reason' => $failureReason,
                    ]);
            }
        });
    }
}
