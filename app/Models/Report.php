<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Vehicle;

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


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function vinCheck()
    {
        return $this->belongsTo(VinCheck::class, 'vin_check_id');
    }

    public function vehicle()
    {
         return $this->belongsTo(Vehicle::class);
    }
}
