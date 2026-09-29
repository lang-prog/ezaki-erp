<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected string $guard_name = 'web';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'first_name',
        'second_name',
        'email',
        'password',
        'company_id',
        'account_type',
        'status',
        'disabled_at',
        'email_verified_at',
        'last_login_at',
        'login_count',
        'failed_login_attempts',
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
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'login_count' => 'integer',
            'failed_login_attempts' => 'integer',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->account_type === 'super_admin' && $this->status === 'active';
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->disabled_at === null;
    }

    public function isCompanyOwner(): bool
    {
        return $this->account_type === 'company' && $this->company_id !== null && $this->isActive() && $this->hasRole('Company Owner');
    }

    public function can($ability, $arguments = []): bool
    {
        if ($this->isCompanyOwner()) {
            return true;
        }

        return parent::can($ability, $arguments);
    }
}
