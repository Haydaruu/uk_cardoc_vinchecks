<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VinCheck extends Model
{
    protected $fillable = [
        'user_id',
        'registration_number',
        'data_source',
        'vin',
        'check_type',
        'ip_address',
        'status',
        'stage',
        'cached_until',
    ];

    protected $casts = [
        'cached_until' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'vin_check_id');
    }
}
