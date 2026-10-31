<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestReportRecoveryToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'guest_report_purchase_id',
        'token_hash',
        'expires_at',
        'used_at',
        'revoked_at',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<GuestReportPurchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(GuestReportPurchase::class, 'guest_report_purchase_id');
    }
}
