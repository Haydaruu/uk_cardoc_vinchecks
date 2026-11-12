<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestReportCaptureAttempt extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_CAPTURING = 'capturing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FUNDING_DECLINED = 'funding_declined';

    public const STATUS_PAYER_ACTION_REQUIRED = 'payer_action_required';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ERROR = 'error';

    protected $attributes = [
        'status' => self::STATUS_INITIATED,
        'attempt_number' => 1,
    ];

    protected $fillable = [
        'guest_report_purchase_id',
        'attempt_uuid',
        'idempotency_key',
        'provider_order_id',
        'status',
        'attempt_number',
        'failure_reason',
        'request_started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'request_started_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GuestReportPurchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(GuestReportPurchase::class, 'guest_report_purchase_id');
    }
}
