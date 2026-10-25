<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestReportPurchase extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REFUNDED = 'refunded';

    public const FULFILLMENT_LOCKED = 'locked';

    public const FULFILLMENT_PENDING = 'pending';

    public const FULFILLMENT_PROCESSING = 'processing';

    public const FULFILLMENT_COMPLETED = 'completed';

    public const FULFILLMENT_FAILED = 'failed';

    protected $fillable = [
        'report_id',
        'name',
        'phone',
        'access_email_sent_at',
        'email',
        'payment_status',
        'amount_minor',
        'currency',
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
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function recoveryTokens(): HasMany
    {
        return $this->hasMany(GuestReportRecoveryToken::class);
    }
}
