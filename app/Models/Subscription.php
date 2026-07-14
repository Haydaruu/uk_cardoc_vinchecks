<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'plan_name',
        'price',
        'payment_method',
        'start_date',
        'end_date',
        'status',
        'reports_used',
        'reports_limit',
        'cancelled_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'cancelled_at' => 'datetime',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
