<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestReportAccessToken extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'guest_report_id',
        'token_hash',
        'revoked_at',
        'created_at',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'guest_report_id');
    }
}
