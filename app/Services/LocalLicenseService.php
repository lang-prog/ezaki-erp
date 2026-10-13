<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\LocalLicense;
use App\Models\LocalLicenseAudit;
use Illuminate\Support\Facades\DB;

class LocalLicenseService
{
    public function activate(string $licenseKey, string $installationId, string $customerBinding, ?int $companyId = null): LocalLicense
    {
        $installation = trim((string) config('licensing.installation_id'));
        $configuredCustomer = trim((string) config('licensing.customer_binding'));
        abort_unless($installation !== '' && $configuredCustomer !== '' && config('licensing.public_key'), 503, 'Offline license installation identity is not configured.');
        abort_unless($companyId !== null, 422, 'A company binding is required for local activation.');
        abort_unless(hash_equals($installation, $installationId), 422, 'Activation installation ID does not match this installation.');
        abort_unless(hash_equals($configuredCustomer, $customerBinding), 422, 'Activation customer binding does not match this installation.');
        $verified = $this->verifySignedLicense($licenseKey);
        $claims = $verified['claims'];
        abort_unless(($claims['installation_id'] ?? null) === $installationId, 422, 'License does not match this installation.');
        abort_unless(hash_equals((string) ($claims['customer_binding'] ?? ''), $customerBinding), 422, 'License does not match this customer.');
        abort_unless(isset($claims['company_id']) && (int) $claims['company_id'] === (int) $companyId, 422, 'License does not match this company.');
        $this->assertNotExpired($claims);

        return DB::transaction(function () use ($licenseKey, $installationId, $customerBinding, $companyId, $verified): LocalLicense {
            $existing = LocalLicense::query()->where('installation_id', $installationId)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->key_hash, hash('sha256', $licenseKey)) && (int) $existing->company_id === $companyId, 409, 'Installation is already activated with a different license.');

                return $existing;
            }
            $license = LocalLicense::query()->create([
                'key_hash' => hash('sha256', $licenseKey),
                'installation_id' => $installationId,
                'customer_binding' => $customerBinding,
                'company_id' => $companyId,
                'status' => 'enabled',
                'activated_at' => now(),
                'enabled_at' => now(),
                'last_verified_at' => now(),
                'signed_payload' => $verified['claims'],
                'signed_payload_raw' => $verified['payload'],
                'signature' => $verified['signature'],
            ]);
            $this->audit($license, 'activated', null, null, null, null, null);

            return $license;
        });
    }

    public function verifyForCompany(Company $company): LocalLicense
    {
        $installation = trim((string) config('licensing.installation_id'));
        $customer = trim((string) config('licensing.customer_binding'));
        abort_unless($installation !== '' && $customer !== '' && config('licensing.public_key'), 402, 'Local installation identity is not configured.');
        $license = LocalLicense::query()
            ->where('company_id', $company->id)
            ->where('status', 'enabled')
            ->whereNotNull('activated_at')
            ->latest('id')
            ->first();
        abort_unless($license, 402, 'Local installation is not activated for this company.');
        abort_unless($license->signed_payload_raw && $license->signature, 402, 'Local license must be reactivated with a signed installation-bound key.');
        abort_unless(hash_equals($installation, (string) $license->installation_id), 402, 'Local license installation binding is invalid.');
        abort_unless(hash_equals($customer, (string) $license->customer_binding), 402, 'Local license customer binding is invalid.');
        $claims = $this->verifyStoredPayload($license->signed_payload_raw, $license->signature);
        abort_unless(($claims['installation_id'] ?? null) === $installation, 402, 'Local license signed installation binding is invalid.');
        abort_unless(hash_equals($customer, (string) ($claims['customer_binding'] ?? '')), 402, 'Local license signed customer binding is invalid.');
        abort_unless((int) ($claims['company_id'] ?? 0) === (int) $company->id && (int) $license->company_id === (int) $company->id, 402, 'Local license company binding is invalid.');
        $this->assertNotExpired($claims);
        $license->forceFill(['last_verified_at' => now()])->save();

        return $license;
    }

    public function enable(LocalLicense $license, ?int $actorId = null): LocalLicense
    {
        return $this->transition($license, 'enabled', $actorId);
    }

    public function disable(LocalLicense $license, ?int $actorId = null): LocalLicense
    {
        return $this->transition($license, 'disabled', $actorId);
    }

    public function revoke(LocalLicense $license, ?int $actorId = null): LocalLicense
    {
        return DB::transaction(function () use ($license, $actorId): LocalLicense {
            $license = LocalLicense::query()->lockForUpdate()->findOrFail($license->id);
            if ($license->status === 'revoked') {
                return $license;
            }
            $license->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
            $this->audit($license, 'revoked', $actorId, $license->installation_id, null, $license->customer_binding, null);

            return $license;
        });
    }

    public function transfer(LocalLicense $license, string $installationId, string $customerBinding, ?int $companyId, string $licenseKey, ?int $actorId = null): LocalLicense
    {
        $verified = $this->verifySignedLicense($licenseKey);
        $claims = $verified['claims'];
        abort_unless(($claims['installation_id'] ?? null) === $installationId, 422, 'New license does not match the destination installation.');
        abort_unless(hash_equals((string) ($claims['customer_binding'] ?? ''), $customerBinding), 422, 'New license does not match the destination customer.');
        abort_unless(isset($claims['company_id']) && (int) $claims['company_id'] === (int) $companyId, 422, 'New license does not match the destination company.');
        $this->assertNotExpired($claims);

        return DB::transaction(function () use ($license, $installationId, $customerBinding, $companyId, $actorId, $licenseKey, $verified): LocalLicense {
            $license = LocalLicense::query()->lockForUpdate()->findOrFail($license->id);
            if (hash_equals($license->key_hash, hash('sha256', $licenseKey)) && $license->installation_id === $installationId && $license->customer_binding === $customerBinding && (int) $license->company_id === (int) $companyId) {
                return $license;
            }
            abort_if($license->status === 'revoked', 409, 'A revoked license cannot be transferred.');
            abort_if(LocalLicense::query()->where('installation_id', $installationId)->where('id', '!=', $license->id)->exists(), 409, 'Installation is already bound to another license.');
            $oldInstallation = $license->installation_id;
            $oldCustomer = $license->customer_binding;
            $oldCompany = $license->company_id;
            $license->forceFill([
                'installation_id' => $installationId,
                'customer_binding' => $customerBinding,
                'company_id' => $companyId,
                'status' => 'enabled',
                'transferred_at' => now(),
                'transfer_reference' => bin2hex(random_bytes(12)),
                'disabled_at' => null,
                'revoked_at' => null,
                'enabled_at' => now(),
                'key_hash' => hash('sha256', $licenseKey),
                'signed_payload' => $verified['claims'],
                'signed_payload_raw' => $verified['payload'],
                'signature' => $verified['signature'],
            ])->save();
            $this->audit($license, 'transferred', $actorId, $oldInstallation, $installationId, $oldCustomer, $customerBinding, $oldCompany, $companyId);

            return $license;
        });
    }

    private function transition(LocalLicense $license, string $status, ?int $actorId): LocalLicense
    {
        return DB::transaction(function () use ($license, $status, $actorId): LocalLicense {
            $license = LocalLicense::query()->lockForUpdate()->findOrFail($license->id);
            if ($license->status === $status) {
                return $license;
            }
            abort_if($license->status === 'revoked', 409, 'A revoked license cannot be changed.');
            $license->forceFill([
                'status' => $status,
                'enabled_at' => $status === 'enabled' ? now() : $license->enabled_at,
                'disabled_at' => $status === 'disabled' ? now() : null,
            ])->save();
            $this->audit($license, $status, $actorId);

            return $license;
        });
    }

    private function verifySignedLicense(string $licenseKey): array
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
        abort_unless(is_array($claims), 422, 'License claims are invalid.');

        return ['claims' => $claims, 'payload' => $payload, 'signature' => $parts[1]];
    }

    private function verifyStoredPayload(string $payload, string $signature): array
    {
        $publicKey = openssl_pkey_get_public((string) config('licensing.public_key'));
        abort_unless($publicKey !== false, 503, 'Offline license verification is not configured correctly.');
        $signatureBytes = base64_decode($signature, true);
        abort_unless($signatureBytes !== false && openssl_verify($payload, $signatureBytes, $publicKey, OPENSSL_ALGO_SHA256) === 1, 402, 'Stored local license signature is invalid.');
        $claims = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(is_array($claims), 402, 'Stored local license claims are invalid.');

        return $claims;
    }

    private function assertNotExpired(array $claims): void
    {
        if (isset($claims['expires_at'])) {
            abort_unless(now()->lessThanOrEqualTo($claims['expires_at']), 402, 'Local license has expired.');
        }
    }

    private function audit(LocalLicense $license, string $action, ?int $actorId = null, ?string $fromInstallation = null, ?string $toInstallation = null, ?string $fromCustomer = null, ?string $toCustomer = null, ?int $fromCompany = null, ?int $toCompany = null): void
    {
        LocalLicenseAudit::query()->create([
            'local_license_id' => $license->id,
            'action' => $action,
            'from_installation_id' => $fromInstallation,
            'to_installation_id' => $toInstallation,
            'from_customer_binding' => $fromCustomer,
            'to_customer_binding' => $toCustomer,
            'from_company_id' => $fromCompany,
            'to_company_id' => $toCompany,
            'performed_by' => $actorId,
        ]);
    }
}
