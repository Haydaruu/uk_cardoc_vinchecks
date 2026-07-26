<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'vin',
        'brand',
        'model',
        'year',
        'registration_number',
        'tax_status',
        'mot_expiry_date',
        'colour',
        'fuel_type',
        'engine_capacity',
        'co2_emissions',
        'year_of_manufacture',
        'outstanding_finance',
        'write_off_category',
        'last_refresed_at',
        'raw_api_response',
    ];

    protected $casts = [
        'mot_expiry_date' => 'date',
        'last_refresed_at' => 'datetime',
        'outstanding_finance' => 'boolean',
        'raw_api_response' => 'array',
    ];
}
