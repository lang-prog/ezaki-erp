<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationRequest extends Model
{
    protected $fillable = [
        'company_name', 'trade_name', 'tax_number', 'owner_name', 'email', 'phone', 'country', 'city',
        'address', 'password', 'plan_id', 'coupon_id', 'terms_accepted', 'status',
        'verification_expires_at', 'verified_at', 'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'terms_accepted' => 'boolean',
            'verification_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
