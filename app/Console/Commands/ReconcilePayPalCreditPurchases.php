<?php

namespace App\Console\Commands;

use App\Models\CreditCheckoutAttempt;
use App\Services\CreditCaptureService;
use Illuminate\Console\Command;

class ReconcilePayPalCreditPurchases extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paypal:reconcile-credit-captures
                            {--uuid= : Reconcile a specific CreditCheckoutAttempt by UUID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile stuck or pending PayPal credit checkout capture attempts';

    /**
     * Execute the console command.
     */
    public function handle(CreditCaptureService $captureService): int
    {
        $uuid = $this->option('uuid');

        if ($uuid) {
            $attempt = CreditCheckoutAttempt::where('attempt_uuid', $uuid)->first();

            if (! $attempt) {
                $this->error("CreditCheckoutAttempt [{$uuid}] not found.");

                return self::FAILURE;
            }

            $this->info("Reconciling attempt [{$attempt->attempt_uuid}] (status: {$attempt->status}, order: {$attempt->provider_order_id})...");
            $result = $captureService->reconcileAttempt($attempt);
            $this->info("Result: " . json_encode($result));

            return $result['reconciled'] ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Starting PayPal credit checkout reconciliation...');
        $stats = $captureService->reconcileStuckAttempts();

        $this->info("Reconciliation complete. Checked: {$stats['total_checked']}, Reconciled: {$stats['reconciled']}, Stale: {$stats['stale']}, Failed: {$stats['failed']}");

        return self::SUCCESS;
    }
}
