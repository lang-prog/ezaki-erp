<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LocalLicense;
use Illuminate\Support\Facades\DB;

class LocalLicenseService
{
    public function activate(string $licenseKey, string $installationId, string $customerBinding): LocalLicense
    {
        $parts = explode('.', $licenseKey, 2);
        abort_unless(count($parts) === 2 && config('licensing.public_key'), 503, 'Offline license verification is not configured.');

        $payload = base64_decode($parts[0], true);
        $signature = base64_decode($parts[1], true);
        abort_unless($payload !== false && $signature !== false, 422, 'Invalid license format.');
        $publicKey = openssl_pkey_get_public(config('licensing.public_key'));
        abort_unless($publicKey !== false, 503, 'Offline license verification is not configured correctly.');
        abort_unless(openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1, 422, 'License signature is invalid.');

        $claims = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(
            is_array($claims)
            && ($claims['installation_id'] ?? null) === $installationId
            && hash_equals((string) ($claims['customer_binding'] ?? ''), $customerBinding),
            422,
            'License does not match this installation.',
        );

        return DB::transaction(function () use ($licenseKey, $installationId, $customerBinding, $payload): LocalLicense {
            abort_if(LocalLicense::query()->where('installation_id', $installationId)->exists(), 409, 'Installation is already activated.');

            return LocalLicense::query()->create([
                'key_hash' => hash('sha256', $licenseKey),
                'installation_id' => $installationId,
                'customer_binding' => $customerBinding,
                'activated_at' => now(),
                'signed_payload' => json_decode($payload, true, flags: JSON_THROW_ON_ERROR),
            ]);
        });
    }
}
