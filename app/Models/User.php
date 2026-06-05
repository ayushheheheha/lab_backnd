<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'avatar',
        'is_admin',
        'is_pro',
        'email_verified_at',
        'otp',
        'otp_expires_at',
        'xp',
        'last_xp_action_date',
        'highest_level_reached',
        'last_seen_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'otp',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_pro' => 'boolean',
            'email_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'last_xp_action_date' => 'date',
            'last_seen_at' => 'datetime',
            'xp' => 'integer',
            'highest_level_reached' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }

    public function ideSubmissions(): HasMany
    {
        return $this->hasMany(IDESubmission::class);
    }
}
