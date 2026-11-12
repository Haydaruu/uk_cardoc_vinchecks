<?php

namespace App\Console\Commands;

use App\Services\WebhookRecoveryService;
use Illuminate\Console\Command;

class ReconcileStaleWebhookEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:reconcile-stale
                            {--limit=50 : Maximum number of candidates to process}
                            {--stale-minutes=10 : Age in minutes for a processing event to be considered stale}
                            {--dry-run : Inspect candidates without modifying state or claiming events}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile stale in-flight, abandoned, or retry-eligible webhook events across Stripe and PayPal';

    /**
     * Execute the console command.
     */
    public function handle(WebhookRecoveryService $service): int
    {
        $limit = (int) $this->option('limit');
        $staleMinutes = (int) $this->option('stale-minutes');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Scanning for stale webhook events (limit: {$limit}, stale age: {$staleMinutes}m, dry-run: ".($dryRun ? 'YES' : 'NO').')...');

        $results = $service->recoverStaleEvents(
            limit: $limit,
            staleMinutes: $staleMinutes,
            dryRun: $dryRun,
        );

        $this->info("Found {$results['candidates_count']} candidate(s).");

        if ($dryRun) {
            $this->table(
                ['ID', 'Provider', 'Event ID', 'Event Type', 'Current Status', 'Action'],
                $results['details']
            );

            return self::SUCCESS;
        }

        $this->info("Batch recovery completed: {$results['claimed_count']} claimed, {$results['completed_count']} completed, {$results['retried_count']} scheduled for retry, {$results['manual_review_count']} escalated to manual review, {$results['skipped_count']} skipped.");

        if (! empty($results['details'])) {
            $this->table(
                ['ID', 'Provider', 'Event ID', 'Event Type', 'Action', 'Reason'],
                $results['details']
            );
        }

        return self::SUCCESS;
    }
}
