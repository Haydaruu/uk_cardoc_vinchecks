<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger row for a single fulfillment claim or pre-claim failure.
 *
 * attempt_key is a SHA-256 hex digest built from the attempt identity:
 * - claim: hash('sha256', "claim|{$purchaseId}|{$claimUuid}")
 * - pre_claim_failed: hash('sha256', "pre_claim_failed|{$purchaseId}|{$dispatchUuid}")
 * - recovery_abandoned: hash('sha256', "recovery_abandoned|{$purchaseId}|{$dispatchUuid}")
 *
 * A claim row is updated through release, completion, failure, and abandonment.
 * It is not duplicated for each lifecycle transition.
 */
class GuestReportFulfillmentAttempt extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    public const KIND_CLAIM = 'claim';

    public const KIND_PRE_CLAIM_FAILED = 'pre_claim_failed';

    public const KIND_RECOVERY_ABANDONED = 'recovery_abandoned';

    public const ORIGIN_NORMAL = 'normal';

    public const ORIGIN_RECOVERY = 'recovery';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RELEASED = 'released';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    protected $table = 'guest_report_fulfillment_attempts';

    protected $fillable = [
        'guest_report_purchase_id',
        'attempt_key',
        'kind',
        'origin',
        'status',
        'generation',
        'dispatch_uuid',
        'claim_uuid',
        'claimed_at',
        'released_at',
        'completed_at',
        'failed_at',
        'abandoned_at',
        'entitlement_used',
        'failure_reason',
        'queue_job_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'claimed_at' => 'datetime',
            'released_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'entitlement_used' => 'boolean',
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
