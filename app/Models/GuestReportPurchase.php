<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestReportPurchase extends Model
{
    protected $fillable = [
        'report_id',
        'provider',
        'gateway_reference',
        'amount_minor',
        'currency',
        'status',
        'access_token_hash',
        'paid_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
