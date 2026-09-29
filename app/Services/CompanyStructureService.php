<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompanyStructureService
{
    public function __construct(private readonly CompanyLimitService $limits) {}

    public function createBranch(Company $company, User $actor, array $data): Branch
    {
        return DB::transaction(function () use ($company, $actor, $data): Branch {
            $subscription = $company->subscriptions()->latest('starts_at')->firstOrFail();
            $subscription = $this->limits->lockAndAssertCanAdd($company, $subscription->id, 'branches');
            $this->limits->assertCanAdd($company, $subscription, 'warehouses');

            $branch = Branch::query()->create([
                'company_id' => $company->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'status' => 'active',
            ]);
            Warehouse::query()->create([
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'code' => $data['warehouse_code'] ?? $data['code'].'-WH',
                'name' => $data['warehouse_name'] ?? $data['name'].' Warehouse',
                'status' => 'active',
            ]);
            app(AuditRecorder::class)->record('branches.created', $branch, $company->id, $actor->id, ['warehouse_created' => true]);

            return $branch->load('warehouses');
        });
    }

    public function updateBranch(Company $company, Branch $branch, User $actor, array $data): Branch
    {
        abort_unless((int) $branch->company_id === (int) $company->id, 404);
        abort_if($branch->archived_at, 409, 'Archived branches cannot be edited.');
        $branch->forceFill(array_intersect_key($data, array_flip(['name', 'phone', 'address'])))->save();
        app(AuditRecorder::class)->record('branches.updated', $branch, $company->id, $actor->id, ['fields' => array_keys($data)]);

        return $branch->fresh('warehouses');
    }

    public function updateWarehouse(Company $company, Warehouse $warehouse, User $actor, array $data): Warehouse
    {
        abort_unless((int) $warehouse->company_id === (int) $company->id, 404);
        abort_if($warehouse->archived_at, 409, 'Archived warehouses cannot be edited.');
        $warehouse->forceFill(array_intersect_key($data, array_flip(['name', 'details'])))->save();
        app(AuditRecorder::class)->record('warehouses.updated', $warehouse, $company->id, $actor->id, ['fields' => array_keys($data)]);

        return $warehouse->fresh();
    }

    public function archiveBranch(Company $company, Branch $branch, User $actor): void
    {
        abort_unless((int) $branch->company_id === (int) $company->id, 404);
        DB::transaction(function () use ($company, $branch, $actor): void {
            $warehouseIds = $branch->warehouses()->whereNull('archived_at')->pluck('id');
            $this->assertArchivable($company, $warehouseIds->all(), 'branch');
            $timestamp = now();
            $branch->forceFill(['status' => 'archived', 'archived_at' => $timestamp])->save();
            Warehouse::query()->whereIn('id', $warehouseIds)->update(['status' => 'archived', 'archived_at' => $timestamp, 'updated_at' => $timestamp]);
            app(AuditRecorder::class)->record('branches.archived', $branch, $company->id, $actor->id, ['warehouse_ids' => $warehouseIds->all()]);
        });
    }

    public function archiveWarehouse(Company $company, Warehouse $warehouse, User $actor): void
    {
        abort_unless((int) $warehouse->company_id === (int) $company->id, 404);
        $this->assertArchivable($company, [$warehouse->id], 'warehouse');
        $warehouse->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        app(AuditRecorder::class)->record('warehouses.archived', $warehouse, $company->id, $actor->id);
    }

    private function assertArchivable(Company $company, array $warehouseIds, string $resource): void
    {
        $stockExists = DB::table('stock_balances')
            ->where('company_id', $company->id)
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('quantity', '!=', 0)
            ->exists();
        abort_if($stockExists, 409, "Cannot archive {$resource} while stock remains.");

        foreach (['purchase_bills', 'sales_bills'] as $documentTable) {
            if (Schema::hasTable($documentTable)) {
                $blockingDocument = DB::table($documentTable)
                    ->where('company_id', $company->id)
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->whereIn('status', ['draft', 'pending', 'open'])
                    ->exists();
                abort_if($blockingDocument, 409, "Cannot archive {$resource} while blocking documents exist.");
            }
        }
    }
}
