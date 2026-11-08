<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCheckoutAttempt extends Model
{
    use HasFactory;

    public const STATUS_INITIATED = 'initiated';
    public const STATUS_CALLING_PROVIDER = 'calling_provider';
    public const STATUS_PROVIDER_CREATED = 'provider_created';
    public const STATUS_CREATE_AMBIGUOUS = 'create_ambiguous';
    public const STATUS_PROVIDER_APPROVED = 'provider_approved';
    public const STATUS_CAPTURING = 'capturing';
    public const STATUS_CAPTURE_COMPLETED = 'capture_completed';
    public const STATUS_CAPTURE_PENDING = 'capture_pending';
    public const STATUS_CAPTURE_RECONCILIATION_REQUIRED = 'capture_reconciliation_required';
    public const STATUS_TERMINAL_FAILED = 'terminal_failed';
    public const STATUS_UNRECONCILED_STALE = 'unreconciled_stale';
    public const STATUS_ABANDONED_CREATED = 'abandoned_created';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'user_id',
        'intent_reference',
        'attempt_uuid',
        'invoice_id',
        'plan_slug',
        'credits_to_grant',
        'amount_minor',
        'currency',
        'provider_order_id',
        'provider_capture_id',
        'status',
        'failure_reason',
        'provider_request_started_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credits_to_grant' => 'integer',
            'amount_minor' => 'integer',
            'expires_at' => 'datetime',
            'provider_request_started_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return now()->greaterThan($this->expires_at);
    }

    /**
     * Returns true if the 6-hour PayPal Orders v2 idempotency retention window has elapsed.
     *
     * Uses provider_request_started_at when available (set immediately before the first
     * HTTP call to PayPal) so the window is accurate even if DB write timing differs
     * from attempt.created_at. Falls back to created_at for safety.
     */
    public function isIdempotencyWindowExceeded(): bool
    {
        $anchor = $this->provider_request_started_at ?? $this->created_at;

        return $anchor !== null && $anchor->lte(now()->subHours(6));
    }

    /**
     * Returns true if this attempt is in an ambiguous or stale state that has exceeded
     * the idempotency window (covers create_ambiguous, calling_provider, and capture_reconciliation_required).
     * These attempts must NOT send an unverified new POST request to PayPal.
     */
    public function isQuarantined(): bool
    {
        return in_array($this->status, [
            self::STATUS_CREATE_AMBIGUOUS,
            self::STATUS_CALLING_PROVIDER,
            self::STATUS_CAPTURE_RECONCILIATION_REQUIRED,
        ], true) && $this->isIdempotencyWindowExceeded();
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_CAPTURE_COMPLETED;
    }
}
