<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\PurchaseBill;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompanyAccountingPolicyService
{
    public const FACTORY_WEIGHT = 'factory_weight';

    public const ACTUAL_WEIGHT = 'actual_weight';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function get(Company $company): array
    {
        $settings = $company->settings ?? [];

        return [
            'purchase_inventory_basis' => $settings['purchase_inventory_basis'] ?? self::FACTORY_WEIGHT,
            'vat_enabled' => (bool) ($settings['vat_enabled'] ?? false),
            'vat_rate' => round((float) ($settings['vat_rate'] ?? 0), 3),
            'weight_policy_locked' => PurchaseBill::query()
                ->where('company_id', $company->id)
                ->whereIn('status', ['approved', 'reversed'])
                ->exists(),
        ];
    }

    public function update(Company $company, User $actor, array $data): array
    {
        abort_unless($actor->isCompanyOwner() && (int) $actor->company_id === (int) $company->id, 403);

        return DB::transaction(function () use ($company, $actor, $data): array {
            $company = Company::query()->lockForUpdate()->findOrFail($company->id);
            $current = $this->get($company);
            $basis = (string) $data['purchase_inventory_basis'];
            if ($basis !== $current['purchase_inventory_basis'] && $current['weight_policy_locked']) {
                $this->audit->record('accounting_policy.weight_change_blocked', $company, $company->id, $actor->id, [
                    'from' => $current['purchase_inventory_basis'],
                    'to' => $basis,
                ]);
                throw ValidationException::withMessages([
                    'purchase_inventory_basis' => 'The inventory weight policy is locked after the first approved purchase. Use an audited stock migration instead.',
                ]);
            }

            $before = $company->settings ?? [];
            $settings = array_merge($before, [
                'purchase_inventory_basis' => $basis,
                'vat_enabled' => (bool) $data['vat_enabled'],
                'vat_rate' => (bool) $data['vat_enabled'] ? round((float) $data['vat_rate'], 3) : 0,
            ]);
            $company->forceFill(['settings' => $settings])->save();
            $this->audit->record('accounting_policy.updated', $company, $company->id, $actor->id, [
                'before' => $before,
                'after' => $settings,
            ]);

            return $this->get($company->fresh());
        });
    }

    public function purchaseInventoryBasis(Company $company): string
    {
        return $this->get($company)['purchase_inventory_basis'];
    }

    public function vatRate(Company $company): float
    {
        $policy = $this->get($company);

        return $policy['vat_enabled'] ? (float) $policy['vat_rate'] : 0.0;
    }
}
