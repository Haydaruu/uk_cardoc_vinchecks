<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        'cached_until',
    ];

    protected $casts = [
        'cached_until' => 'datetime',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'vin_check_id');
    }
}
