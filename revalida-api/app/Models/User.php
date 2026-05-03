<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'password',
        'avatar_url',
        'is_admin',
        'is_active',
        'google_id',
        'question_text_size',
        'monthly_question_limit',
        'current_plans_by_category',
        'current_plan_code',
        'current_plan_name',
        'current_plan_price_cents',
        'plan_activated_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'question_text_size' => 'integer',
            'current_plans_by_category' => 'array',
            'monthly_question_limit' => 'integer',
            'current_plan_price_cents' => 'integer',
            'plan_activated_at' => 'datetime',
        ];
    }

    public function simulations(): HasMany
    {
        return $this->hasMany(Simulation::class);
    }

    public function paymentSessions(): HasMany
    {
        return $this->hasMany(PaymentSession::class);
    }
}
