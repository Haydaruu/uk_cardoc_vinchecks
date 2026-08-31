<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'user_id',
        'invoice_id',
        'currency',
        'amount',
        'type',
        'payment_method',
        'payment_gateway_ref',
        'description',
        'status',
        'paid_at',
        'refunded_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
