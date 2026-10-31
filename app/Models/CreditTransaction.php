<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'balance_after',
        'reference_id',
        'idempotency_key',
        'description',
    ];

    protected $casts = [
        'amount' => 'integer',
        'balance_after' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function User(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<CreditTransaction>  $query
     * @return Builder<CreditTransaction>
     */
    public function scopePurchase(Builder $query): Builder
    {
        return $query->where('type', 'purchase');
    }

    /**
     * @param  Builder<CreditTransaction>  $query
     * @return Builder<CreditTransaction>
     */
    public function scopeUsage(Builder $query): Builder
    {
        return $query->where('type', 'usage');
    }
}
