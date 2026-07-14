<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VinCheck extends Model
{
    protected $filliable = [
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

    public function report()
    {
        return $this->hasOne(report::class);
    }
}
