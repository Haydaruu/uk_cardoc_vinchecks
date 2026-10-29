<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RefundRecord extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'refundable_type',
        'refundable_id',
        'admin_id',
        'gateway',
        'payment_reference',
        'refund_gateway_ref',
        'amount_minor',
        'currency',
        'status',
        'idempotency_key',
        'reason',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'amount_minor' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function refundable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isPendingOrProcessing(): bool
    {
        return in_array($this->status, [self::STATUS_PROCESSING, self::STATUS_UNKNOWN], true);
    }
}
