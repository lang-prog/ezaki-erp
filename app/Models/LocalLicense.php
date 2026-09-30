<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocalLicense extends Model
{
    protected $fillable = [
        'key_hash', 'installation_id', 'customer_binding', 'company_id', 'status',
        'activated_at', 'enabled_at', 'disabled_at', 'revoked_at', 'transferred_at',
        'transfer_reference', 'last_verified_at', 'signed_payload',
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime', 'enabled_at' => 'datetime', 'disabled_at' => 'datetime',
            'revoked_at' => 'datetime', 'transferred_at' => 'datetime', 'last_verified_at' => 'datetime',
            'signed_payload' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(LocalLicenseAudit::class);
    }
}
