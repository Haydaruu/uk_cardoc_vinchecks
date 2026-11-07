<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestLead extends Model
{
    protected $fillable = [
        'vin_check_id',
        'report_id',
        'email',
        'phone',
        'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<VinCheck, $this>
     */
    public function vinCheck(): BelongsTo
    {
        return $this->belongsTo(VinCheck::class);
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
