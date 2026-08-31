<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

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
        'monthly_credits',
        'cancelled_at',

        'stripe_subscription_id',
        'stripe_price_id',
        'cancel_at_period_end',
        'current_period_end',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'cancelled_at' => 'datetime',
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
