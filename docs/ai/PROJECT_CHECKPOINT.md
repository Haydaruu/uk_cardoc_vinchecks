# UKCarDoc — PayPal Concurrency & Reliability Handoff

**Checkpoint date:** 9 November 2026 (Phase 3.5.1 committed)
**Project:** `uk_cardoc_vinchecks`
**Workspace (Windows):** `C:\Users\Haydaru\Documents\Penting\Project 2\no_starter_kit`
**Current feature branch:** `feature/paypal-credit-checkout-idempotency`
**Last known committed baseline (HEAD):** `4e9c08fbf420153c6819defe32899c1c16bf0920`
**Last known commit message:** `feat(paypal): harden guest capture concurrency and settlement tracking`

> This handoff document is the canonical context for Antigravity, Claude, ChatGPT, and Gemini agents working on this project. Read it completely before proposing or editing code. The repository is the source of truth; verify current branch, HEAD, working tree, and diffs before performing any action.

---

## 1. Immediate Status

**Phase 3.5.1 — Capture Concurrency & Settlement Tracking Hardening is COMMITTED.**

Working tree is clean for tracked files. `docs/` remains untracked.

### Working-Tree State

```
branch:  feature/paypal-credit-checkout-idempotency
HEAD:    4e9c08f feat(paypal): harden guest capture concurrency and settlement tracking
status:
?? docs/
```

### Full Verification Results (Independently Rerun & Confirmed Prior to Commit)

| Verification Suite / Tool | Result |
|---|---:|
| Full Test Suite (`php artisan test`) | **408 passed, 2,207 assertions, 49.31s (exit code 0)** |
| Targeted PayPal Suites | **82 passed, 459 assertions (exit code 0)** |
| Laravel Pint (`vendor/bin/pint --test` on 7 modified files) | **Passed (exit code 0, clean)** |
| `git diff --check` | **Passed (exit code 0, clean)** |

**Current Phase Status:** Phase 3.5.1 committed. Ready to proceed to Phase 3.5.2 (Webhook deduplication race hardening).

---

## 2. Committed History (Phases 1–3.5.1)

The feature branch has completed and committed:

1. **Commit:** `4e9c08fbf420153c6819defe32899c1c16bf0920` (Phase 3.5.1)
   - **Message:** `feat(paypal): harden guest capture concurrency and settlement tracking`
   - **Contents:** 7 files, 1,538 insertions, 93 deletions.
   - Protected state precedence, lock-for-update post-settlement attempt completion, superseded attempt guards, sync queue driver skip guard, async enqueue exception absorption, and regression tests.

2. **Commit:** `13e8bde39d268ca25f0e77d8d154faa7a10117c7` (Phases 1–3.4.2)
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

## 4. Tracked Modified Files Summary

| File | Status | Description |
|---|---|---|
| `app/Services/GuestReportCaptureService.php` | Modified | Protected state precedence, lock-for-update post-settlement attempt completion, superseded attempt guards |
| `app/Services/GuestReportPaymentService.php` | Modified | Isolated `enqueueVehicleCheck()` with sync driver skip guard and async enqueue exception absorption |
| `tests/Feature/GuestReportCaptureServiceTest.php` | Modified | Concurrency, protected state precedence, settlement tracking, and post-settlement dispatch isolation tests |
| `tests/Feature/GuestReportPayPalCaptureControllerTest.php` | Modified | Mock adjusted to simulate real settlement contract |
| `tests/Feature/GuestReportPayPalPaymentServiceTest.php` | Modified | Tests for sync driver skip, async enqueue failure, and validation error propagation |
| `tests/Feature/GuestReportPayPalReturnControllerTest.php` | Modified | Mock adjusted to simulate real settlement contract |
| `tests/Feature/ReconcilePayPalGuestReportCapturesTest.php` | Modified | Queue configuration set for tests asserting `ProcessVehicleCheck` push |

---

## 5. Remaining Roadmap & Future Phases

### Phase 3.5.2 — Webhook Deduplication Race Hardening
- **Target:** `AdminRefundReconciliationService::executeWithDeduplication()`.
- **Issue:** Concurrent duplicate webhook deliveries can fall through after unique constraint collisions if records are reloaded while still in `processing` status.
- **Scope:** Narrowly address lease/heartbeat logic and race handling without touching capture services.

### Phase 3.5.3 — PostgreSQL Concurrency Verification
- Automated CI currently runs on SQLite `:memory:`.
- Need verification under real PostgreSQL with concurrent transactions to confirm `SELECT ... FOR UPDATE` behavior, lock wait timeouts, and transaction isolation.

### Future Dedicated Phase — Guest Fulfillment Recovery
- **Issue:** Purchases with `payment_status = PAID`, `capture_status = CAPTURE_COMPLETED`, and `fulfillment_status = PENDING` (or eligible `FAILED`) are **not** captured by existing capture reconciliation (`ReconcilePayPalGuestReportCaptures`).
- **Solution:** Dedicated scheduled command / worker for orphaned fulfillment recovery:
  - Candidates: PAID + completed capture with `fulfillment_status IN (PENDING, FAILED)`.
  - Stale `PROCESSING` lease threshold (e.g. > 15 minutes).
  - Uniqueness and idempotency guarantees (no duplicate report generations or credit deductions).
  - Explicit backoff and bounded attempts.

---

## 6. Guardrails for Next Agent

- Do not stage, commit, push, merge, rebase, switch branches, run migrations, or alter database records without explicit user authorization.
- Always run `git status --short` and `git diff --check` before proposing changes.
- Never weaken payment validation, amount/currency matching, or capture verification invariants.
- Keep user-facing discussion in casual Indonesian unless the user switches language.
