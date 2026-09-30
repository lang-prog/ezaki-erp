<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalLicenseAudit extends Model
{
    protected $fillable = [
        'local_license_id', 'action', 'from_installation_id', 'to_installation_id',
        'from_customer_binding', 'to_customer_binding', 'from_company_id', 'to_company_id',
        'performed_by', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(LocalLicense::class, 'local_license_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
