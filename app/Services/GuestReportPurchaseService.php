<?php

namespace App\Services;

use App\Jobs\ProcessVehicleCheck;
use App\Models\GuestReportPurchase;
use App\Models\Report;
use App\Models\VinCheck;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GuestReportPurchaseService
{
    /**
     * Call only after the payment provider confirms the exact expected amount.
     * Row locks and purchase status make webhook/return-page retries idempotent.
     */
    public function markPaidAndDispatch(GuestReportPurchase $purchase): void
    {
        $job = DB::transaction(function () use ($purchase): ?array {
            $lockedPurchase = GuestReportPurchase::query()
                ->whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedPurchase->status, ['processing', 'completed'], true)) {
                return null;
            }

            if (! in_array($lockedPurchase->status, ['pending', 'failed'], true)) {
                return null;
            }

            $report = Report::query()
                ->whereKey($lockedPurchase->report_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($report->report_type === 'premium') {
                $lockedPurchase->update([
                    'status' => 'completed',
                    'paid_at' => $lockedPurchase->paid_at ?? now(),
                    'completed_at' => $lockedPurchase->completed_at ?? now(),
                ]);

                return null;
            }

            if (! $report->vin_check_id) {
                throw new RuntimeException('Guest report is missing its vehicle check.');
            }

            $vinCheck = VinCheck::query()
                ->whereKey($report->vin_check_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPurchase->update([
                'status' => 'processing',
                'paid_at' => $lockedPurchase->paid_at ?? now(),
            ]);

            $vinCheck->update([
                'check_type' => 'premium',
                'status' => 'pending',
                'stage' => 'queued',
            ]);

            return [
                'vin_check_id' => $vinCheck->id,
                'report_id' => $report->id,
            ];
        });

        if (! $job) {
            return;
        }

        try {
            ProcessVehicleCheck::dispatch(
                $job['vin_check_id'],
                $job['report_id'],
            );
        } catch (\Throwable $exception) {
            GuestReportPurchase::query()
                ->whereKey($purchase->id)
                ->where('status', 'processing')
                ->update(['status' => 'failed']);

            throw $exception;
        }
    }

    public function markCompleted(int $reportId): void
    {
        GuestReportPurchase::query()
            ->where('report_id', $reportId)
            ->where('status', 'processing')
            ->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);
    }

    public function markFailed(int $reportId): void
    {
        GuestReportPurchase::query()
            ->where('report_id', $reportId)
            ->where('status', 'processing')
            ->update(['status' => 'failed']);
    }
}
