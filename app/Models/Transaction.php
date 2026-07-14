<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
        'amount',
        'type',
        'payment_method',
        'payment_gateaway_ref',
        'description',
        'status',
        'refunded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'refunded_at' => 'datetime',
    ];

    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
