<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'role', 'email', 'phone_number', 'password', 'credits','google_id','avatar','provider', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    protected function isPremium (): Attribute
    {
        return Attribute::get(function (){
            return $this->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date')
                ->orWhere('end_date', '>=', now());
            })
            ->exists();
        }); 
            
    }

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->whereColumn('report_used', '<', 'report_limit')
            ->orWhere('reports_limit', null)
            ->latest()
            ->first();
    }

    public function canPerformCheck(): bool
    {
        return $this->activeSubscription() !== null || $this->credits > 0;
    }
}
