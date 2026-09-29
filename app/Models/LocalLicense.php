<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalLicense extends Model
{
    protected $fillable = ['key_hash', 'installation_id', 'customer_binding', 'activated_at', 'signed_payload'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'signed_payload' => 'array'];
    }
}
