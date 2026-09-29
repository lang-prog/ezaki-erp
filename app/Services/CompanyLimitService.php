<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompanyLimitService
{
    public function effectiveLimit(Subscription $subscription, string $resource): ?int
    {
        $base = data_get($subscription->plan?->limits, $resource);
        $addon = data_get($subscription->overrides, "limit_addons.{$resource}", 0);

        if ($base === null && ! $addon) {
            return null;
        }

        return (int) $base + (int) $addon;
    }

    public function currentCount(Company $company, string $resource): int
    {
        if ($resource === 'users') {
            return User::query()
                ->where('company_id', $company->id)
                ->where('account_type', 'company')
                ->where('status', 'active')
                ->whereNull('disabled_at')
                ->whereNotIn('users.id', function ($query) use ($company): void {
                    $query->select('model_has_roles.model_id')
                        ->from('model_has_roles')
                        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                        ->where('model_has_roles.company_id', $company->id)
                        ->where('roles.company_id', $company->id)
                        ->where('roles.name', 'Company Owner')
                        ->where('model_has_roles.model_type', (new User)->getMorphClass());
                })
                ->count();
        }

        $table = match ($resource) {
            'branches' => 'branches',
            'warehouses' => 'warehouses',
            default => throw new \InvalidArgumentException("Unsupported company limit: {$resource}"),
        };

        if (! Schema::hasTable($table)) {
            return 0;
        }

        return (int) \DB::table($table)
            ->where('company_id', $company->id)
            ->whereNull('archived_at')
            ->count();
    }

    public function assertCanAdd(Company $company, Subscription $subscription, string $resource): void
    {
        $limit = $this->effectiveLimit($subscription, $resource);
        if ($limit !== null && $this->currentCount($company, $resource) >= $limit) {
            throw new HttpException(403, "The plan limit for {$resource} has been reached. Ask your Super Admin to increase the limit.");
        }
    }

    public function lockAndAssertCanAdd(Company $company, int $subscriptionId, string $resource): Subscription
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Company limits must be checked inside the resource creation transaction.');
        }

        $subscription = Subscription::query()
            ->where('company_id', $company->id)
            ->with('plan')
            ->lockForUpdate()
            ->findOrFail($subscriptionId);
        $this->assertCanAdd($company, $subscription, $resource);

        return $subscription;
    }
}
