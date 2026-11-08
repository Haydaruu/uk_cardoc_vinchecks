<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GuestReportPurchase extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const PAYMENT_STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    public const FULFILLMENT_LOCKED = 'locked';

    public const FULFILLMENT_PENDING = 'pending';

    public const FULFILLMENT_PROCESSING = 'processing';

    public const FULFILLMENT_COMPLETED = 'completed';

    public const FULFILLMENT_FAILED = 'failed';

    public const CAPTURE_CAPTURING = 'capturing';

    public const CAPTURE_COMPLETED = 'completed';

    public const CAPTURE_PENDING = 'capture_pending';

    public const CAPTURE_FUNDING_DECLINED = 'funding_declined';

    public const CAPTURE_PAYER_ACTION_REQUIRED = 'payer_action_required';

    public const CAPTURE_RECONCILIATION_REQUIRED = 'capture_reconciliation_required';

    public const CAPTURE_STATUS_FAILED = 'failed';

    public const CAPTURE_LEGACY_UNTRACKED = 'legacy_untracked';

    protected $fillable = [
        'report_id',
        'name',
        'phone',
        'access_email_sent_at',
        'email',
        'payment_status',
        'amount_minor',
        'currency',
        'checkout_reference',
        'stripe_price_id',
        'payment_provider',
        'provider_order_id',
        'provider_payment_id',
        'paid_at',
        'failed_at',
        'expired_at',
        'refunded_at',
        'fulfillment_status',
        'fulfillment_attempts',
        'fulfillment_started_at',
        'fulfilled_at',
        'capture_status',
        'capture_attempts',
        'capture_request_started_at',
        'capture_failure_reason',
        'last_reconciled_at',
        'last_reconciliation_dispatched_at',
        'active_capture_attempt_uuid',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'expired_at' => 'datetime',
        'refunded_at' => 'datetime',
        'access_email_sent_at' => 'datetime',
        'fulfillment_attempts' => 'integer',
        'fulfillment_started_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'capture_attempts' => 'integer',
        'capture_request_started_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'last_reconciliation_dispatched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (GuestReportPurchase $purchase): void {
            if (! $purchase->checkout_reference) {
                $purchase->checkout_reference = (string) Str::uuid();
            }
        });
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    /**
     * @return HasMany<GuestReportRecoveryToken, $this>
     */
    public function recoveryTokens(): HasMany
    {
        return $this->hasMany(GuestReportRecoveryToken::class);
    }

    /**
     * @return HasMany<GuestReportCaptureAttempt, $this>
     */
    public function captureAttempts(): HasMany
    {
        return $this->hasMany(GuestReportCaptureAttempt::class);
    }
}
