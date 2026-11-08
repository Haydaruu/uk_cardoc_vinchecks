<?php

namespace App\Jobs;

use App\Models\GuestReportPurchase;
use App\Services\GuestCaptureResult;
use App\Services\GuestReportCaptureService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileGuestReportPurchaseJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    /**
     * Unique lock duration in seconds.
     *
     * Aligns with the scheduler cadence (15 minutes = 900 s). The service-level
     * distributed lock (85 s TTL) is the correctness guard against parallel work;
     * this unique lock is only a dispatch gate to prevent queue pile-up.
     *
     * Rationale: worst-case job execution is ≤90 s (queue retry_after). After all
     * retry attempts exhaust, the unique lock expires at 900 s, allowing the
     * scheduler's next 15-minute tick to re-select and re-dispatch the candidate.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $orderId,
    ) {}

    public function uniqueId(): string
    {
        return 'paypal-guest-reconcile:'.$this->orderId;
    }

    public function handle(GuestReportCaptureService $captureService): GuestCaptureResult
    {
        Log::info('ReconcileGuestReportPurchaseJob: reconciling purchase via GET-only', [
            'order_id' => $this->orderId,
        ]);

        $result = $captureService->reconcileGetOnly($this->orderId);

        // Update last_reconciled_at only when the service performed a meaningful,
        // successful provider check (reconciled: true). This excludes:
        //   - Lock-timeout returns (reconciled defaults to false)
        //   - Exception propagation (never reaches this line)
        // The service itself updates last_reconciled_at for all other confirmed
        // state transitions; this covers the remaining paths where the service
        // returns reconciled: true but does not write the timestamp (e.g. APPROVED
        // GET-only confirmation with no DB state change).
        if ($result->reconciled) {
            GuestReportPurchase::query()
                ->where('provider_order_id', $this->orderId)
                ->update([
                    'last_reconciled_at' => now(),
                ]);
        }

        Log::info('ReconcileGuestReportPurchaseJob: completed', [
            'order_id' => $this->orderId,
            'status' => $result->status,
            'reconciled' => $result->reconciled,
        ]);

        return $result;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ReconcileGuestReportPurchaseJob: failed to reconcile', [
            'order_id' => $this->orderId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
