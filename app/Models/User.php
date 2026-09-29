<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'phone', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'api_token_hash'])]
class User extends Authenticatable
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
            'is_active' => 'boolean',
            'discount_percent' => 'integer',
            'discount_expires_at' => 'datetime',
            'show_discount_countdown' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function activeDiscountPercent(): int
    {
        if ($this->role !== 'dealer' || ($this->discount_expires_at && $this->discount_expires_at->isPast())) {
            return 0;
        }

        return $this->discount_percent;
    }
}
