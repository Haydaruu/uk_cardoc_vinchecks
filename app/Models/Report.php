<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    protected $fillable = [
        'user_id',
        'vin_check_id',
        'vehicle_id',
        'vin',
        'report_data',
        'report_type',
        'file_path',
        'download_count',
        'generated_at',
        'expired_at',
    ];

    protected $casts = [
        'report_data' => 'array',
        'generated_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<VinCheck, $this>
     */
    public function vinCheck(): BelongsTo
    {
        return $this->belongsTo(VinCheck::class, 'vin_check_id');
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return HasMany<GuestReportPurchase, $this>
     */
    public function guestReportPurchases(): HasMany
    {
        return $this->hasMany(GuestReportPurchase::class, 'report_id');
    }

    /**
     * @return HasMany<GuestReportAccessToken, $this>
     */
    public function guestReportAccessTokens(): HasMany
    {
        return $this->hasMany(GuestReportAccessToken::class, 'guest_report_id');
    }
}
