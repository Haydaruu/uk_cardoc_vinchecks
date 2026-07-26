<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class CreditTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balancee_after',
        'refernece_id',
        'description',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    public function User () 
    {
        return $this->belogsTo(User::class);
    }

    public function scopePurchase($query)
    {
        return $query->where('type', 'purchase');
    }

    public function scopeUsage($query)
    {
        return $query->where('type', 'usage');
    }
}
