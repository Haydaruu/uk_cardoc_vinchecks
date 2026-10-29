<?php

namespace App\Console\Commands;

use App\Models\RefundRecord;
use App\Services\Admin\AdminRefundReconciliationService;
use Illuminate\Console\Command;

class ReconcileRefundsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'refunds:reconcile 
                            {--id= : Reconcile a specific RefundRecord by ID}
                            {--limit=50 : Maximum number of unknown records to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile unknown or processing refund records against payment gateway status';

    /**
     * Execute the console command.
     */
    public function handle(AdminRefundReconciliationService $service): int
    {
        $id = $this->option('id');

        if ($id) {
            $record = RefundRecord::find($id);

            if (! $record) {
                $this->error("RefundRecord #{$id} not found.");

                return self::FAILURE;
            }

            $this->info("Reconciling RefundRecord #{$record->id} ({$record->gateway}, status: {$record->status})...");
            $reconciled = $service->reconcileUnknownRecord($record);
            $this->info("Completed: status is now {$reconciled->status}");

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $records = RefundRecord::query()
            ->where('status', RefundRecord::STATUS_UNKNOWN)
            ->limit($limit)
            ->get();

        $this->info("Found {$records->count()} unknown refund records to reconcile.");

        $settled = 0;
        $failed = 0;
        $remained = 0;

        foreach ($records as $record) {
            $reconciled = $service->reconcileUnknownRecord($record);

            if ($reconciled->status === RefundRecord::STATUS_SUCCESS) {
                $settled++;
            } elseif ($reconciled->status === RefundRecord::STATUS_FAILED) {
                $failed++;
            } else {
                $remained++;
            }
        }

        $this->info("Reconciliation batch complete: {$settled} settled success, {$failed} settled failed, {$remained} remained unknown/ambiguous.");

        return self::SUCCESS;
    }
}
