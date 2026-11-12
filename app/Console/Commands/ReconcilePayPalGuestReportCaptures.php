<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileGuestReportPurchaseJob;
use App\Models\GuestReportPurchase;
use App\Services\GuestReportCaptureService;
use Carbon\CarbonInterface;
use Illuminate\Bus\UniqueLock;
use Illuminate\Cache\Lock;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePayPalGuestReportCaptures extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paypal:reconcile-guest-captures
                            {--limit=50 : Maximum number of purchases to reconcile per run}
                            {--order-id= : Reconcile a specific purchase by PayPal Order ID}
                            {--queue : In --order-id mode, dispatch to queue instead of executing synchronously}
                            {--dry-run : Display candidates without claiming or dispatching (batch mode only)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reconcile stuck, pending, or proof-incomplete PayPal guest report purchases via GET-only';

    /**
     * Execute the console command.
     */
    public function handle(GuestReportCaptureService $captureService): int
    {
        $specificOrderId = $this->option('order-id');

        if ($specificOrderId) {
            return $this->reconcileSingleOrder($specificOrderId, $captureService);
        }

        return $this->reconcileBatchCandidates();
    }

    /**
     * Reconcile a targeted single order via immediate GET-only inspection or queue.
     */
    protected function reconcileSingleOrder(string $orderId, GuestReportCaptureService $captureService): int
    {
        $purchase = GuestReportPurchase::query()
            ->where('payment_provider', 'paypal')
            ->where('provider_order_id', $orderId)
            ->first();

        if (! $purchase) {
            $this->error("GuestReportPurchase with PayPal Order ID [{$orderId}] not found.");

            return self::FAILURE;
        }

        // --queue flag: dispatch to queue instead of executing synchronously.
        // Useful when the operator prefers async execution but needs to target
        // a specific order (e.g. after a support escalation).
        if ($this->option('queue')) {
            ReconcileGuestReportPurchaseJob::dispatch($orderId);
            $this->info("Dispatched order [{$orderId}] to reconciliation queue.");

            return self::SUCCESS;
        }

        $this->info("Reconciling single purchase #{$purchase->id} (Order: {$orderId}, capture_status: {$purchase->capture_status}, payment_status: {$purchase->payment_status})...");

        // Mark dispatch timestamp so the batch cooldown window applies
        $purchase->update([
            'last_reconciliation_dispatched_at' => now(),
        ]);

        $result = $captureService->reconcileGetOnly($orderId);

        // Update last_reconciled_at only when provider returned a meaningful
        // confirmation (reconciled: true). Lock-timeout, network error, and
        // invalid correlation/proof paths must not falsely advance the timestamp.
        if ($result->reconciled) {
            $purchase->update([
                'last_reconciled_at' => now(),
            ]);
        }

        $this->info("Reconciliation complete. Status: {$result->status}, Reconciled: ".($result->reconciled ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    /**
     * Reconcile batch candidates with atomic claiming and dispatch isolation.
     */
    protected function reconcileBatchCandidates(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $fifteenMinutesAgo = now()->subMinutes(15);
        $isDryRun = (bool) $this->option('dry-run');

        $this->info("Scanning for eligible guest report purchases (limit: {$limit}, cooldown: 15m".($isDryRun ? ', dry-run' : '').')...');

        // ── Eligibility Policy ──────────────────────────────────────────────────
        //
        //  INCLUDED statuses (GET-only safe, meaningful recovery value):
        //   - capturing            : capture in-flight; GET confirms completion or failure
        //   - capture_pending      : bank clearance in progress; worth checking
        //   - funding_declined     : buyer may have switched funding source; GET detects resolution
        //   - reconciliation_req   : explicitly flagged for re-check
        //   - legacy_untracked     : pre-tracking records; always eligible
        //   - NULL (pending, age >15 min) : newly created but unattempted; allow
        //                                   15-minute grace period for in-flight checkout sessions.
        //                                   Boundary: created_at strictly < fifteenMinutesAgo.
        //
        //  EXCLUDED statuses:
        //   - payer_action_required: Buyer must complete payer-action URL. The reconciler
        //                            cannot advance this state (allow_post=false, order is
        //                            APPROVED, GET confirms APPROVED → returns `processing`
        //                            with no side effects). Including it generates 15-minute
        //                            noise with zero recovery value and risks interfering
        //                            with the signed payer-action callback flow.
        // ───────────────────────────────────────────────────────────────────────

        // Observability counts across candidate universe
        $scanned = $this->buildStatusQuery($fifteenMinutesAgo)->count();
        $skipped = $this->buildStatusQuery($fifteenMinutesAgo)
            ->where('last_reconciliation_dispatched_at', '>=', $fifteenMinutesAgo)
            ->count();
        $eligible = max(0, $scanned - $skipped);

        if ($isDryRun) {
            return $this->reconcileDryRun($limit, $fifteenMinutesAgo, $scanned, $eligible, $skipped);
        }

        $nowClaim = now();

        // Atomic claim: select candidates and mark last_reconciliation_dispatched_at
        // in a short transaction to prevent concurrent runners from double-dispatching.
        $candidates = DB::transaction(function () use ($limit, $fifteenMinutesAgo, $nowClaim) {
            $records = $this->buildEligibilityQuery($fifteenMinutesAgo)
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            if ($records->isNotEmpty()) {
                GuestReportPurchase::query()
                    ->whereIn('id', $records->pluck('id'))
                    ->update(['last_reconciliation_dispatched_at' => $nowClaim]);
            }

            return $records;
        });

        if ($candidates->isEmpty()) {
            $this->info('No eligible purchases require reconciliation at this time.');
            $this->line("Summary — Scanned: {$scanned} | Eligible: {$eligible} | Dispatched: 0 | Skipped: {$skipped} | Already queued: 0 | Dispatch failed: 0");

            return self::SUCCESS;
        }

        $dispatched = 0;
        $alreadyQueued = 0;
        $dispatchFailed = 0;

        // Dispatch each candidate individually with per-candidate failure isolation.
        foreach ($candidates as $candidate) {
            $job = new ReconcileGuestReportPurchaseJob($candidate->provider_order_id);
            $uniqueKey = UniqueLock::getKey($job);
            $isAlreadyQueued = false;

            try {
                $lock = Cache::lock($uniqueKey, $job->uniqueFor);
                $isAlreadyQueued = $lock instanceof Lock && $lock->isLocked();
            } catch (Throwable) {
                // If lock driver does not support isLocked(), proceed with standard dispatch
            }

            if ($isAlreadyQueued) {
                // ShouldBeUnique lock is already held: dispatch would be suppressed.
                // Accurately track as already_queued rather than counting as dispatched.
                $alreadyQueued++;

                continue;
            }

            try {
                // Note: A benign TOCTOU window exists between the isLocked() check above and
                // PendingDispatch::__destruct() acquiring the ShouldBeUnique lock. During concurrent
                // scheduler runs, shouldDispatch() may suppress queue push while $dispatched increments,
                // slightly overstating the counter. Underlying atomic claims (lockForUpdate) and
                // ShouldBeUnique still strictly guarantee that duplicate queue jobs are never pushed.
                ReconcileGuestReportPurchaseJob::dispatch($candidate->provider_order_id);
                $dispatched++;
            } catch (Throwable $e) {
                $dispatchFailed++;

                // Safely release only this candidate's claim using exact timestamp matching.
                // If another concurrent runner or manual action updated the timestamp,
                // the WHERE clause prevents clobbering the newer claim.
                GuestReportPurchase::query()
                    ->where('id', $candidate->id)
                    ->where('last_reconciliation_dispatched_at', $nowClaim)
                    ->update(['last_reconciliation_dispatched_at' => null]);

                Log::error('ReconcilePayPalGuestReportCaptures: dispatch failed for candidate', [
                    'order_id' => $candidate->provider_order_id,
                    'purchase_id' => $candidate->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Dispatched {$dispatched} guest report purchase(s) for asynchronous reconciliation.");

        if ($alreadyQueued > 0) {
            $this->comment("Skipped {$alreadyQueued} candidate(s) already active in queue.");
        }

        if ($dispatchFailed > 0) {
            $this->warn("Dispatch failed for {$dispatchFailed} candidate(s); claims safely released.");
        }

        $this->line("Summary — Scanned: {$scanned} | Eligible: {$eligible} | Dispatched: {$dispatched} | Skipped: {$skipped} | Already queued: {$alreadyQueued} | Dispatch failed: {$dispatchFailed}");

        return self::SUCCESS;
    }

    /**
     * Dry-run mode: show eligible candidates without claiming or dispatching.
     */
    protected function reconcileDryRun(int $limit, CarbonInterface $fifteenMinutesAgo, int $scanned, int $eligible, int $skipped): int
    {
        // No lockForUpdate — read-only inspection
        $candidates = $this->buildEligibilityQuery($fifteenMinutesAgo)
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No eligible purchases require reconciliation at this time.');
            $this->line("Summary — Scanned: {$scanned} | Eligible: {$eligible} | Dispatched: 0 | Skipped: {$skipped} | Already queued: 0 | Dispatch failed: 0");

            return self::SUCCESS;
        }

        $this->info("Dry-run: {$candidates->count()} candidate(s) would be dispatched:");

        $rows = $candidates->map(fn ($p) => [
            $p->id,
            $p->provider_order_id,
            $p->capture_status ?? 'NULL',
            $p->payment_status,
            $p->last_reconciliation_dispatched_at?->toDateTimeString() ?? 'never',
        ])->toArray();

        $this->table(
            ['ID', 'Provider Order ID', 'Capture Status', 'Payment Status', 'Last Dispatched'],
            $rows,
        );

        $this->line("Summary — Scanned: {$scanned} | Eligible: {$eligible} | Dispatched: 0 | Skipped: {$skipped} | Already queued: 0 | Dispatch failed: 0");

        return self::SUCCESS;
    }

    /**
     * Build query of purchases matching the PayPal guest status recovery criteria.
     *
     * @return Builder<GuestReportPurchase>
     */
    protected function buildStatusQuery(CarbonInterface $fifteenMinutesAgo): Builder
    {
        return GuestReportPurchase::query()
            ->where('payment_provider', 'paypal')
            ->whereNotNull('provider_order_id')
            ->where(function ($statusQuery) use ($fifteenMinutesAgo) {
                $statusQuery->where(function ($pendingQuery) use ($fifteenMinutesAgo) {
                    $pendingQuery->where('payment_status', GuestReportPurchase::STATUS_PENDING)
                        ->where(function ($captureSub) use ($fifteenMinutesAgo) {
                            $captureSub->whereIn('capture_status', [
                                GuestReportPurchase::CAPTURE_CAPTURING,
                                GuestReportPurchase::CAPTURE_PENDING,
                                GuestReportPurchase::CAPTURE_FUNDING_DECLINED,
                                // payer_action_required is intentionally excluded: the reconciler
                                // cannot advance this state (GET-only, APPROVED order returns
                                // 'processing' with no side effect). Re-including would generate
                                // noise every 15 minutes with zero recovery value.
                                GuestReportPurchase::CAPTURE_RECONCILIATION_REQUIRED,
                                GuestReportPurchase::CAPTURE_LEGACY_UNTRACKED,
                            ])
                            // NULL capture_status: newly created purchases awaiting first capture.
                            // A 15-minute age filter prevents the reconciler from racing against
                            // in-flight checkout sessions that created the order moments ago.
                                ->orWhere(function ($nullSub) use ($fifteenMinutesAgo) {
                                    $nullSub->whereNull('capture_status')
                                        ->where('created_at', '<', $fifteenMinutesAgo);
                                });
                        });
                })->orWhere(function ($paidQuery) {
                    $paidQuery->where('payment_status', GuestReportPurchase::STATUS_PAID)
                        ->where(function ($proofSub) {
                            $proofSub->where('capture_status', '!=', GuestReportPurchase::CAPTURE_COMPLETED)
                                ->orWhereNull('provider_payment_id');
                        });
                });
            });
    }

    /**
     * Build the full eligibility query including dispatch cooldown and priority ordering.
     *
     * @return Builder<GuestReportPurchase>
     */
    protected function buildEligibilityQuery(CarbonInterface $fifteenMinutesAgo): Builder
    {
        return $this->buildStatusQuery($fifteenMinutesAgo)
            ->where(function ($dispatchQuery) use ($fifteenMinutesAgo) {
                $dispatchQuery->whereNull('last_reconciliation_dispatched_at')
                    ->orWhere('last_reconciliation_dispatched_at', '<', $fifteenMinutesAgo);
            })
            ->orderByRaw('CASE WHEN last_reconciled_at IS NULL THEN 0 ELSE 1 END, last_reconciled_at ASC')
            ->orderByRaw('CASE WHEN last_reconciliation_dispatched_at IS NULL THEN 0 ELSE 1 END, last_reconciliation_dispatched_at ASC')
            ->orderBy('id', 'asc');
    }
}
