# UKCarDoc — Backend Conventions

These rules are derived from the actual codebase (commit `4dbc51f`, branch `main`).
Do not invent conventions; verify any addition against the repository before writing it here.

---

## Stack

- **Framework:** Laravel 13, PHP 8.3+
- **Queue:** Database queue (`QUEUE_CONNECTION=database`) — `sync` for tests
- **Database:** PostgreSQL for local/staging/production; SQLite `:memory:` for tests (phpunit.xml)
- **Auth:** Laravel Fortify (headless) + Inertia/React frontend; Google + Microsoft OAuth via Socialite
- **PDF:** `spatie/laravel-pdf` with Browsershot driver (Chrome-PHP supported); see `config/laravel-pdf.php`

---

## Directory Layout (app/)

```
app/
├── Actions/Fortify/           # Fortify action overrides only
├── Console/Commands/          # Artisan commands
├── Contracts/                 # Single interface: VehicleDataProviderInterface
├── Exceptions/                # Custom exception classes
├── Http/
│   ├── Controllers/
│   │   ├── Admin/             # Admin-only controllers (requires role:admin,super_admin)
│   │   ├── Auth/              # OAuth callback controllers
│   │   ├── Payments/          # Checkout, PayPal, Stripe, Subscription controllers
│   │   ├── Settings/          # Profile/security/connected-accounts controllers
│   │   ├── SuperAdmin/        # Super-admin-only controllers (requires role:super_admin)
│   │   └── Webhook/           # PayPalWebhookController, StripeWebhookController
│   ├── Middleware/
│   └── Requests/              # Form request classes (Admin/ and root-level)
├── Jobs/                      # Queued jobs only
├── Mail/                      # Mailable classes
├── Models/
├── Providers/
└── Services/
    ├── Admin/                 # Admin-domain services
    ├── Reports/               # Report builders and normalizers
    └── VehicleProviders/      # Vehicle data provider implementations
```

---

## Routes

| File | Middleware | Purpose |
|---|---|---|
| `routes/web.php` | varies | Public pages, webhooks (Stripe/PayPal), guest checkout, vehicle-check |
| `routes/user.php` | `auth`, `verified`, `not.suspended` | Authenticated user pages, checkout, settings |
| `routes/admin.php` | `auth`, `verified`, `role:admin,super_admin` | Admin panel; super_admin-only sub-group |
| `routes/console.php` | — | Scheduled console commands |

Webhook routes (`/stripe/webhook`, `/paypal/webhook`) are excluded from CSRF via
`$middleware->validateCsrfTokens(except: [...])` in `bootstrap/app.php`.
They are protected by provider signature verification in their respective controllers.

---

## Payments

### PayPal (`app/Services/PayPalService.php`)
- Base URL switches automatically between Sandbox and Live based on `PAYPAL_MODE` env.
- Access token is cached per mode (`paypal_access_token_{mode}`) — Sandbox tokens never mix with Live.
- Order creation for credit purchases uses `custom_id = "{userId}|{planSlug}"` and `reference_id = {planSlug}`.
- Guest report orders use `custom_id = "guest-report|{checkoutReference}|{reportId}"` with a stable idempotency key (`"guest-report-order-{checkoutReference}"`).
- Capture idempotency key: `"capture-{orderId}"`.
- **Never** switch `PAYPAL_MODE` to `live` or change production webhook registration without owner approval.

### Stripe (`app/Http/Controllers/Webhook/StripeWebhookController.php`)
- Webhook signature verified using `Stripe\Webhook::constructEvent()` with `STRIPE_WEBHOOK_SECRET`.
- Guest report purchases are identified by `metadata.purpose = "guest_report_purchase"` on the checkout session.

### Webhook Deduplication
Both Stripe and PayPal webhooks use `AdminRefundReconciliationService::executeWithDeduplication()`,
which records each processed event in the `processed_webhook_events` table.
Credits are protected by an `idempotency_key` column in `credit_transactions`.

---

## Telegram Notifications

Two completely separate bot/chat pairs — never merge them:

| Destination | Service | Job | Config keys |
|---|---|---|---|
| **Sales** | `TelegramNotificationService` | `SendTelegramSaleNotification` | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_CHAT_ID` |
| **Activity** | `TelegramActivityNotificationService` | `SendTelegramGuestActivityNotification` | `TELEGRAM_ACTIVITY_BOT_TOKEN`, `TELEGRAM_ACTIVITY_CHAT_ID` |

**Activity notification events (queued, non-blocking):**
- `guest.preview.viewed` — dispatched in `ReportController::show()` when a guest first views their basic report preview
- `guest.vehicle_check.failed` — dispatched in `ProcessVehicleCheck::handle()` catch block for terminal guest free-check failures
- `auth.preview.viewed` — dispatched in `ReportController::show()` when an authenticated user first views their report preview
- `auth.vehicle_check.failed` — dispatched in `ProcessVehicleCheck::handle()` catch block for terminal authenticated premium-check failures

**Deduplication:** `SendTelegramGuestActivityNotification` implements `ShouldBeUnique`.
`uniqueId()` returns `"{eventType}:{dedupeKey}"` with `uniqueFor = 3600` seconds.

**Safety rules:**
- Telegram failures must never break a successful vehicle-check response or payment fulfillment.
- Wrap dispatch in a try/catch that only logs on failure (see `GuestReportPaymentService::dispatchGuestReportSaleNotification()`).
- Never log bot tokens — the URL `https://api.telegram.org/bot{token}/sendMessage` contains the token.
- Do not send raw exception traces, provider credentials, or customer payment data to Telegram.

---

## Credit System (`app/Services/CreditService.php`)

- `grantCredits()`: uses `idempotency_key` on `credit_transactions` to prevent double-grants; pessimistic row-lock on the User.
- `consume()`: pessimistic row-lock; throws `RuntimeException` if balance ≤ 0.
- `adjustCredits()`: admin-only; two-phase idempotency check (pre-transaction + within-transaction lock) + `UniqueConstraintViolationException` race handling + audit log.

---

## Vehicle Check Flow

```
VehicleCheckController::store()
  → VinCheck::create(status=pending, stage=queued)
  → ProcessVehicleCheck::dispatch(vinCheckId)
  → redirect to vehicle-check.loading

VehicleCheckController::loading()       # polling page
  → if status=success → redirect to report or guest-lead capture

ProcessVehicleCheck::handle()           # queued job
  → fetch VinCheck, determine isPremium
  → call provider(s)
  → Vehicle::updateOrCreate()
  → DB::transaction { Report::create/update, CreditService::consume (premium+auth), mark VinCheck success }
  → on failure: mark failed, dispatch Activity notification for terminal failures
```

---

## Security Conventions

- **Ownership checks:** Report access requires `$report->user_id === $user->id` (or guest token for paid guest reports).
- **Mass assignment:** All models use `$fillable`; never use `$guarded = []`.
- **Validation:** Use Form Request classes for all mutating endpoints.
- **Rate limiting:** Applied to `vehicle-check.store`, `guest-lead.store`, and `page.support.submit`.
- **Proxy trust:** `trustProxies(at: '*')` in `bootstrap/app.php` — set for Ngrok/tunnel; review before production deployment with a real proxy list.
- **CSRF exceptions:** Only webhook routes — always protected by provider signature verification instead.
- **Secrets:** Never log PayPal/Stripe response bodies, Telegram URLs, or raw provider payloads.

---

## Migrations

- 42 migration files — do not squash, consolidate, or edit historical migrations.
- New schema changes: focused, reversible forward-only migrations.
- Test DB: SQLite `:memory:` (set in `phpunit.xml`) — be careful with PostgreSQL-specific operations (e.g. enum changes).

---

## Tests

- Test framework: **Pest 4** with Laravel plugin
- Test directory: `tests/Feature/` and `tests/Unit/`
- Run: `php artisan test` or `vendor/bin/pest`
- Tests must not make real API calls to PayPal, Stripe, Telegram, or vehicle data providers — use `Http::fake()` or dedicated fakes.
- Do not use `migrate:fresh`, `db:wipe`, or destructive seeders in tests.
