# UKCarDoc — PayPal Concurrency & Reliability Handoff

**Checkpoint date:** 9 November 2026 (Phase 3.5.4 Proactive Stale Webhook Recovery implemented & verified)
**Project:** `uk_cardoc_vinchecks`
**Workspace (Windows):** `C:\Users\Haydaru\Documents\Penting\Project 2\no_starter_kit`
**Current feature branch:** `feature/paypal-credit-checkout-idempotency`
**Last known committed baseline (HEAD):** `d61ae823ffa34b0ae504d1f0d208673e8ba56906`
**Last known commit message:** `docs: record Phase 3.5.1 completion and baseline verification`

> This handoff document is the canonical context for Antigravity, Claude, ChatGPT, and Gemini agents working on this project. Read it completely before proposing or editing code. The repository is the source of truth; verify current branch, HEAD, working tree, and diffs before performing any action.

---

## 1. Immediate Status

**Phase 3.5.4 — Proactive Stale Webhook Recovery is IMPLEMENTED & FULLY VERIFIED.**

State-Aware Domain Reconciliation service, console command, and scheduled job implemented. Atomic recovery claim with UUID fencing tokens, exponential retry backoff, and escalation to `manual_review` preventing duplicate webhook re-execution.

Working-tree has 3 modified tracked files, and untracked files for migrations, test config, commands, services, and test suites.

### Working-Tree State

```
branch:  feature/paypal-credit-checkout-idempotency
HEAD:    d61ae82 docs: record Phase 3.5.1 completion and baseline verification
status:
 M app/Models/ProcessedWebhookEvent.php
 M app/Services/Admin/AdminRefundReconciliationService.php
 M docs/ai/PROJECT_CHECKPOINT.md
 M routes/console.php
?? app/Console/Commands/ReconcileStaleWebhookEventsCommand.php
?? app/Services/WebhookRecoveryService.php
?? database/migrations/2026_11_09_150000_add_ownership_token_to_processed_webhook_events_table.php
?? database/migrations/2026_11_09_160000_add_recovery_fields_to_processed_webhook_events_table.php
?? phpunit.pgsql.xml
?? tests/Feature/PostgreSqlConcurrencyTest.php
?? tests/Feature/WebhookDeduplicationConcurrencyTest.php
?? tests/Feature/WebhookRecoveryServiceTest.php
?? tests/Support/
```

### Verification Results (Phase 3.5.4 Final)

| Verification Suite / Tool | Environment / Connection | Result |
|---|---|---:|
| **Webhook Recovery Service Suite** (`WebhookRecoveryServiceTest.php`) | SQLite `:memory:` | **12 passed, 45 assertions, ~3.2s (exit code 0)** |
| **PostgreSQL Multi-Process Concurrency** (`PostgreSqlConcurrencyTest.php`) | PostgreSQL 18.3 (`ukcardoc_concurrency_test`) | **6 passed, 53 assertions, ~33s (exit code 0)** |
| Deduplication & Fencing Concurrency (`WebhookDeduplicationConcurrencyTest.php`) | SQLite `:memory:` | **11 passed, 56 assertions, ~2.7s (exit code 0)** |
| Deduplication & Refund Suite (`WebhookDeduplicationAndRefundTest.php`) | SQLite `:memory:` | **17 passed, 91 assertions (exit code 0)** |
| Capture Suites (`PayPalGuestWebhookCaptureTest` + `GuestReportStripeWebhookTest`) | SQLite `:memory:` | **36 passed, 151 assertions (exit code 0)** |
| Laravel Pint (all modified/created files) | Clean format check | **Passed (exit code 0, clean)** |
| `git diff --check` | Clean working-tree diff | **Passed (exit code 0, clean)** |

**Current Phase Status:** Phase 3.5.4 is complete and fully passing. Ready for user final review.

### Phase 3.5.3 Test B Fix (SAVEPOINT Recovery) & Final Test-Harness Cleanup

1. **Test B (SAVEPOINT Recovery):**
   - **Root cause:** Worker `in_outer_transaction` path originally called `Transaction::create()` which requires a `user_id` foreign key to the `users` table. No user existed in `ukcardoc_concurrency_test` for Test B.
   - **Fix:** Replaced with `ProcessedWebhookEvent::create()` marker record using a test-unique `marker_provider = 'sp_marker_'.Str::random(10)` passed via worker config. No foreign key dependency. Test B asserts this specific marker and deletes only records for that unique marker provider, avoiding global deletion of `stripe_sp_marker`.
   - **Proof:** Outer transaction successfully inserts and commits its marker record after the inner `DB::transaction()` (SAVEPOINT) rolls back due to unique constraint collision.

2. **Test-Harness Cleanup Order (All 6 Tests):**
   - In all six tests (A, B, C, D1, D2, D3), `cleanupWorkers($runningWorkers, $barrier)` is invoked as the **first operation** in the `finally` block.
   - All background child processes are terminated (`stop(1.0)`), temporary config files unlinked, and barrier directories cleaned up before any database fixture cleanup queries execute.
   - Prevents open child connections or lingering transaction locks from contending with fixture deletions.

**Current Phase Status:** Phase 3.5.2 (Fencing token & dedup hardening) and Phase 3.5.3 (PostgreSQL multi-process verification) are complete and passing. Ready for user final review and commit decision.

---

## 2. Committed History (Phases 1–3.5.1)

The feature branch has completed and committed:

1. **Commit:** `d61ae823ffa34b0ae504d1f0d208673e8ba56906` (Docs update)
   - **Message:** `docs: record Phase 3.5.1 completion and baseline verification`

2. **Commit:** `4e9c08fbf420153c6819defe32899c1c16bf0920` (Phase 3.5.1)
   - **Message:** `feat(paypal): harden guest capture concurrency and settlement tracking`
   - **Contents:** 7 files, 1,538 insertions, 93 deletions.

3. **Commit:** `13e8bde39d268ca25f0e77d8d154faa7a10117c7` (Phases 1–3.4.2)
   - **Message:** `feat(paypal): implement guest capture recovery`
   - **Contents:** 28 files, 6,164 insertions, 148 deletions.
   - Covers guest capture tracking, attempts, safe capture/idempotency recovery, webhook routing, scheduled reconciliation, payer-action continuation, PayPal SDK v6 integration, and legacy tracking migrations.

### Non-Negotiable Architectural Invariants

- `GuestReportPaymentService::handlePayPalGuestOrder()` is the authoritative settlement entry point for guest PayPal payments.
- Never mark a guest purchase paid based solely on browser callbacks or unverified provider payloads.
- Preserve all amount, currency, report ID, custom ID, provider order ID, and capture ID validations.
- No guest credit grant path.
- Do not issue blind capture POSTs outside the 6-hour idempotency window; use GET-only reconciliation.
- Do not duplicate or alter `ProcessVehicleCheck` fulfillment uniqueness.
- Do not affect Stripe or authenticated credit checkout.
- Keep all external PayPal HTTP calls outside database transactions.

---

## 3. Phase 3.5.1 Implementation Details

### A. `GuestReportCaptureService.php` (Concurrency & Protected State Hardening)

1. **Protected State Recognition (`isPurchaseInProtectedState()`):**
   - Returns true if purchase `payment_status` is `paid`, `refunded`, `failed`, or `expired`.
   - Applied across all delayed Phase 2 provider responses (VOIDED, PENDING, generic exceptions, declines, malformed payloads).

2. **Protected State Result Resolution (`resolveProtectedStateResult()`):**
   - Authoritative negative terminal state precedence:
     - `STATUS_REFUNDED` → returns `failed` (HTTP 422).
     - `PAYMENT_STATUS_FAILED` or `STATUS_EXPIRED` → returns `failed` (HTTP 422).
     - Authoritative paid: `STATUS_PAID` + `CAPTURE_COMPLETED` + non-empty `provider_payment_id` → returns `completed`.
     - Inconsistent states (e.g. `STATUS_PENDING` + `CAPTURE_COMPLETED`) fail safely with HTTP 422.
   - Prevents legacy backfilled records (`payment_status = refunded` + `capture_status = completed`) from returning success.

3. **Settlement Tracking Overhaul (`settleSuccessfulCapture()`):**
   - Removed outer `try/catch (\Throwable)` that swallowed exceptions from `handlePayPalGuestOrder()`.
   - Post-settlement transactional lock: executes `DB::transaction()` with `lockForUpdate()` on purchase.
   - Strictly validates `payment_status === STATUS_PAID` before updating capture attempt tracking.
   - Validates attempt purchase/order ownership before setting `STATUS_COMPLETED`.
   - Synchronizes `active_capture_attempt_uuid` if the active attempt differs from the in-memory attempt.
   - Updates `capture_status = CAPTURE_COMPLETED` and `provider_payment_id` only after confirmed settlement.

4. **Attempt Supersession Guards:**
   - Added `isAttemptSuperseded()` and `safelyUpdateAttempt()` to prevent delayed responses from modifying superseded attempts.

---

### B. `GuestReportPaymentService.php` (Post-Settlement Dispatch & Queue Semantics)

1. **Transaction Boundary Protection:**
   - Payment validation, row locks, state transition to `STATUS_PAID`, and database commits occur inside the payment transaction.
   - Any failure during payment validation or database commit bubbles up and rolls back normally.

2. **Sync Queue Driver Policy (`QUEUE_CONNECTION=sync`):**
   - **Problem Identified:** Under the sync driver, `ProcessVehicleCheck::dispatch()` runs `handle()` synchronously in-process. Any job execution exception (e.g. vehicle API lookup failure) re-throws from `dispatch()`. If caught broadly, job execution errors are swallowed and retry semantics bypassed; if uncaught, it escapes after payment commit and prevents capture attempt tracking from completing.
   - **Architectural Solution in `enqueueVehicleCheck()`:**
     - Explicitly checks configured queue driver.
     - If driver is `sync`: deliberately **skips** dispatch and logs `Log::critical('ProcessVehicleCheck skipped: sync queue driver is not safe for post-payment dispatch', ...)`.
     - Does **not** throw. Returns normally so `settleSuccessfulCapture()` can finalize capture attempt tracking.
     - Fulfillment status remains `FULFILLMENT_PENDING` for separate recovery.

3. **Async Queue Driver Policy (`database`, `redis`, `sqs`):**
   - When an asynchronous driver is configured, `ProcessVehicleCheck::dispatch()` is called within a targeted `try/catch (\Throwable $e)`.
   - Catches queue infrastructure/transport errors (e.g. database/redis connection failure, dispatch timeout).
   - Acknowledges that dispatch raised an exception and job acceptance/processing may be unknown (due to timeouts or transport ambiguity).
   - Logs an error and absorbs the exception so the already-committed PAID purchase is never downgraded or rolled back.
   - Fulfillment status remains `FULFILLMENT_PENDING` for separate recovery.

4. **Telegram Sale Notification:**
   - `dispatchGuestReportSaleNotification()` runs after `enqueueVehicleCheck()`.
   - Uses `SendTelegramSaleNotification::dispatch()->afterCommit()` to ensure notification is dispatched only when payment is confirmed.

5. **Stripe Webhook Path Scope Review (Line ~311):**
   - Reviewed `handleCheckoutSession()` dispatch call.
   - Kept unchanged and documented: invoked solely by Stripe webhook controller (no capture attempt tracking layer). If sync dispatch fails, it returns a 5xx to Stripe, prompting webhook redelivery.

---

### C. Test Suite Hardening

1. **`GuestReportPayPalPaymentServiceTest.php`:**
   - Added test: sync driver skips `ProcessVehicleCheck` without throwing, leaves PAID + PENDING.
   - Added test: sync driver skip leaves capture attempt tracking unaffected.
   - Added test: async enqueue failure leaves payment PAID and fulfillment PENDING.
   - Configured tests asserting job pushes to explicitly set `config(['queue.default' => 'database'])` before `Queue::fake()`.

2. **`GuestReportCaptureServiceTest.php` & `ReconcilePayPalGuestReportCapturesTest.php`:**
   - Updated tests verifying `ProcessVehicleCheck` push to configure async queue before `Queue::fake()`.
   - Verified that attempt tracking and capture status complete normally even when fulfillment enqueue fails.

3. **Controller Tests (`GuestReportPayPalCaptureControllerTest.php`, `GuestReportPayPalReturnControllerTest.php`):**
   - Updated mocks to simulate genuine settlement (`payment_status = STATUS_PAID`, `capture_status = CAPTURE_COMPLETED`) satisfying `settleSuccessfulCapture()` post-conditions.

---

## 4. Tracked & Working-Tree Modified Files Summary

### Phase 3.5.1 (Committed in `4e9c08f`)
| File | Status | Description |
|---|---|---|
| `app/Services/GuestReportCaptureService.php` | Committed | Protected state precedence, lock-for-update post-settlement attempt completion, superseded attempt guards |
| `app/Services/GuestReportPaymentService.php` | Committed | Isolated `enqueueVehicleCheck()` with sync driver skip guard and async enqueue exception absorption |
| `tests/Feature/GuestReportCaptureServiceTest.php` | Committed | Concurrency, protected state precedence, settlement tracking, and post-settlement dispatch isolation tests |
| `tests/Feature/GuestReportPayPalCaptureControllerTest.php` | Committed | Mock adjusted to simulate real settlement contract |
| `tests/Feature/GuestReportPayPalPaymentServiceTest.php` | Committed | Tests for sync driver skip, async enqueue failure, and validation error propagation |
| `tests/Feature/GuestReportPayPalReturnControllerTest.php` | Committed | Mock adjusted to simulate real settlement contract |
| `tests/Feature/ReconcilePayPalGuestReportCapturesTest.php` | Committed | Queue configuration set for tests asserting `ProcessVehicleCheck` push |

### Phase 3.5.2 & 3.5.3 (Uncommitted Working Tree)
| File | Status | Description |
|---|---|---|
| `app/Models/ProcessedWebhookEvent.php` | Modified | Added `ownership_token` to `$fillable` |
| `app/Services/Admin/AdminRefundReconciliationService.php` | Modified | Implemented atomic conditional update, `Str::uuid()` ownership fencing, positive lease validation `max(1, ...)`, and fencing-token guarded completion/failure |
| `database/migrations/2026_11_09_150000_add_ownership_token_to_processed_webhook_events_table.php` | Untracked (New) | Migration adding nullable `ownership_token` (UUID) to `processed_webhook_events` (NOT run on dev/prod db) |
| `tests/Feature/WebhookDeduplicationConcurrencyTest.php` | Untracked (New) | 11 tests covering initial acquisition, in-flight rejection, completed duplicate rejection, concurrent in-flight simulation, failed retry token rotation, stale reclaim, active lease protection, stale worker completion fencing, stale worker failure fencing, exception marking, and payload summary (runs on SQLite) |
| `tests/Feature/PostgreSqlConcurrencyTest.php` | Untracked (New) | 6 integration tests running multi-process concurrency on real PostgreSQL 18.3 (`ukcardoc_concurrency_test`): collision SAVEPOINT rollback, simultaneous race gate, stale reclaim serialization, SELECT FOR UPDATE blocking, late failure terminal protection, and double-failure credit duplication prevention |
| `tests/Support/ConcurrencyBarrier.php` | Untracked (New) | File-based rendezvous barrier for deterministic synchronization across separate OS processes |
| `tests/Support/PostgreSqlConcurrencyWorker.php` | Untracked (New) | Standalone CLI worker process with safety guards restricting execution strictly to `pgsql` on `ukcardoc_concurrency_test` |
| `phpunit.pgsql.xml` | Untracked (New) | PostgreSQL test environment configuration targeting `ukcardoc_concurrency_test` |

---

## 5. Phase 3.5.2 & Phase 3.5.3 Architecture & Verified Guarantees

1. **Ownership Fencing Token (`ownership_token`):**
   - Generated as fresh UUID during initial acquisition or atomic reclaim.
   - Guarded completion: `UPDATE ... WHERE provider = ? AND event_id = ? AND ownership_token = ? AND status = 'processing'` setting status to `completed`.
   - Guarded failure: `UPDATE ... WHERE provider = ? AND event_id = ? AND ownership_token = ? AND status = 'processing'` setting status to `failed`.
   - Result: If a stale worker's lease expired and a new worker reclaimed ownership with a new token, the old worker's completion or failure update affects 0 rows, returning `ownership_lost` without corrupting the new worker's status.
   - **Crucial Boundary:** Fencing tokens prevent database event state corruption, but CANNOT retroactively cancel side effects already initiated inside the callback. Callbacks maintain application-level idempotency (e.g. `RefundRecord` lockForUpdate, `GuestReportPurchase` status check, `CreditCheckoutAttempt` terminal guards).

2. **Lease Behavior & Sync Queue Semantics:**
   - Default lease: 120 seconds (`PROCESSING_LEASE_SECONDS`), overridable via `$leaseSeconds` or config.
   - Enforced positive lease: `max(1, $leaseSeconds ?? $configuredLease)`.
   - Stale threshold: `updated_at <= now()->subSeconds($lease)`.
   - If queue driver is `sync`, dispatched jobs run inline during the callback. Sync queue skip in `GuestReportPaymentService::enqueueVehicleCheck` (Phase 3.5.1) prevents long-running vehicle lookups from exceeding lease thresholds.

3. **HTTP Response Compatibility:**
   - Both `StripeWebhookController` and `PayPalWebhookController` return HTTP 200 `{'received': true}` for `in_flight`, `already_completed`, and `ownership_lost` deduplication results to avoid provider webhook retry storms.
   - Exceptions inside callback (e.g. API connectivity failure) mark event `failed` and re-throw, returning 5xx to provider to trigger standard retry.

4. **Phase 3.5.3 Verified PostgreSQL Concurrency Findings (all 6 tests, 53 assertions):**
   - **Test A (Concurrent Initial Insert Collision + pg_blocking_pids):**
     - Proved using two independent PHP processes (different OS PIDs and PostgreSQL `pg_backend_pid()` connections).
     - Worker 1 inserts row in uncommitted transaction; Worker 2 concurrently executes `ProcessedWebhookEvent::create()`.
     - PostgreSQL engine blocks Worker 2 on unique index `processed_webhook_events_unique`. `pg_blocking_pids()` observed in `pg_stat_activity`.
     - When Worker 1 commits, PostgreSQL unblocks Worker 2 with `UniqueConstraintViolationException`.
     - Worker 2's `DB::transaction` SAVEPOINT rolls back cleanly; Worker 2 attempts conditional update (0 rows), queries fresh record, and returns `in_flight`.
     - Worker 1's callback executes exactly once; Worker 2's callback is NEVER executed. Database contains exactly 1 row with status `completed`.
   - **Test B (Nested Transaction / SAVEPOINT Recovery):**
     - Proves outer `DB::transaction()` survives inner SAVEPOINT rollback caused by unique constraint collision.
     - Worker 2 wraps `executeWithDeduplication()` in outer `DB::transaction()`. Inner `DB::transaction()` becomes SAVEPOINT. Collision rolls back only the SAVEPOINT.
     - After collision, Worker 2's outer transaction successfully inserts a `ProcessedWebhookEvent` marker record (`provider = $markerProvider` unique per test) and commits.
     - `ProcessedWebhookEvent::find($markerId)` confirms marker is persisted — proving outer transaction commit after SAVEPOINT rollback.
     - Blocking observed via `pg_blocking_pids()` before Worker 1 releases.
     - **Fix note:** Replaced `Transaction::create()` with `ProcessedWebhookEvent::create()` marker using test-unique provider — no foreign key dependency and isolated cleanup.
   - **Test C (Concurrent Stale Ownership Reclaim + pg_blocking_pids):**
     - Two independent workers race on a seeded stale event (`status = 'processing'`, `updated_at` 10 minutes ago).
     - PostgreSQL row-level lock serializes the conditional `UPDATE`. `pg_blocking_pids()` observed.
     - Winner receives `affected = 1`, acquires new ownership token, and executes callback to `completed`.
     - Loser receives `affected = 0`, returns `in_flight`, and does NOT execute callback.
     - Winning token is preserved; loser's token is never written.
   - **Tests D1–D3 (Refund Row-Lock Settlement):**
     - Proved `SELECT ... FOR UPDATE` causes real blocking on PostgreSQL (`pg_blocking_pids()` observed).
     - When Worker 1 holds row lock in `settleRefundSuccess`, Worker 2's `SELECT ... FOR UPDATE` is blocked until Worker 1 commits.
     - Worker 2 unblocks, reads committed `status = 'success'`, and returns `already_success`.
     - Late definitive failure delivery (`settleRefundDefinitiveFailure`) after success is blocked, reads `status = 'success'`, and returns `ignored_late_failure` without modifying row or restoring credits.
     - Concurrent duplicate failure settlement: Worker 1 reverses hold, restores 5 credits, creates 1 `CreditTransaction`, and commits `failed`. Worker 2 unblocks, sees `status = 'failed'`, and returns `already_failed`. User balance increments by 5 exactly once (5 -> 10, never 15). Exactly 1 `CreditTransaction` is created.

---

## 6. Remaining Roadmap & Future Phases

### Phase 3.5.3 — PostgreSQL Concurrency Verification
- **Status: COMPLETED & VERIFIED.** 6 multi-process integration tests passing against PostgreSQL 18.3 (`ukcardoc_concurrency_test`).

### Phase 3.5.4 — Webhook Stale Event Proactive Recovery Scheduler
- **Status: COMPLETED & VERIFIED.** Dedicated `WebhookRecoveryService`, `ReconcileStaleWebhookEventsCommand` (`webhooks:reconcile-stale`), and scheduled cron (`everyTenMinutes()->withoutOverlapping(15)`). 12 feature tests passing with 45 assertions.

### Future Dedicated Phase — Guest Fulfillment Recovery
- Orphaned fulfillment recovery command/worker for `PAID` purchases with `PENDING` / `FAILED` fulfillment.

---

## 7. Guardrails for Next Agent

- Do not stage, commit, push, merge, rebase, switch branches, run migrations, or alter database records without explicit user authorization.
- Always run `git status --short` and `git diff --check` before proposing changes.
- Never weaken payment validation, amount/currency matching, or capture verification invariants.
- Keep user-facing discussion in casual Indonesian unless the user switches language.
