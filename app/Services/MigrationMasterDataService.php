<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\Diameter;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MigrationMasterDataService
{
    public function __construct(private readonly AccountingService $accounting) {}

    /** @return array<string, int> */
    public function createApproved(DataMigrationRun $run, User $actor): array
    {
        $company = Company::query()->findOrFail($run->company_id);
        $counts = ['customers' => 0, 'suppliers' => 0, 'product_types' => 0, 'diameters' => 0, 'warehouses' => 0, 'products' => 0, 'cashboxes' => 0, 'banks' => 0];
        $this->excludeTextCategories($run);

        DB::transaction(function () use ($run, $actor, $company, &$counts): void {
            $mappings = DataMigrationMapping::query()
                ->where('run_id', $run->id)
                ->where('decision', 'create_required')
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (DataMigrationMapping $mapping): int => $this->creationPriority($mapping->entity_type));
            $this->assertCreatable($run, $company, $mappings);
            $mappings->each(function (DataMigrationMapping $mapping) use ($actor, $company, &$counts): void {
                $payload = $mapping->payload ?? [];
                $attributes = $payload['source_attributes'] ?? [];
                $name = trim((string) ($payload['source_name'] ?? ''));
                if ($name === '' && $mapping->entity_type !== 'category') {
                    throw ValidationException::withMessages(['mapping' => 'A source name is required before creating master data.']);
                }

                [$targetId, $countKey] = match ($mapping->entity_type) {
                    'customer' => [$this->createParty($company, $actor, $name, $attributes, true), 'customers'],
                    'supplier' => [$this->createParty($company, $actor, $name, $attributes, false), 'suppliers'],
                    'product_type' => [ProductType::query()->create(['company_id' => $company->id, 'name' => $name, 'kind' => $attributes['kind'] ?? 'other', 'status' => 'active'])->id, 'product_types'],
                    'category' => [$this->createDiameter($company, (string) ($payload['source_value'] ?? '')), 'diameters'],
                    'product' => [$this->createProduct($run, $company, $mapping, $name, $attributes), 'products'],
                    'warehouse' => [$this->createWarehouse($company, $mapping, $name, $attributes), 'warehouses'],
                    'cashbox' => [$this->createCashbox($company, $actor, $name), 'cashboxes'],
                    'bank' => [$this->createBank($company, $actor, $attributes), 'banks'],
                    default => throw ValidationException::withMessages(['mapping' => 'This mapping type cannot be created automatically.']),
                };
                $payload['reviewed'] = true;
                $mapping->forceFill(['target_id' => $targetId, 'decision' => 'matched', 'confidence' => 1, 'target_type' => class_basename($mapping->target_type ?? '') ?: null, 'payload' => $payload])->save();
                $counts[$countKey]++;
            });
        });

        return $counts;
    }

    private function creationPriority(string $entity): int
    {
        return ['customer' => 10, 'supplier' => 20, 'product_type' => 30, 'category' => 40, 'warehouse' => 50, 'cashbox' => 60, 'bank' => 70, 'product' => 80][$entity] ?? 90;
    }

    /** @param Collection<int, DataMigrationMapping> $mappings */
    private function assertCreatable(DataMigrationRun $run, Company $company, $mappings): void
    {
        $errors = [];
        $activeBranch = Branch::query()->where('company_id', $company->id)->where('status', 'active')->exists();
        foreach ($mappings as $mapping) {
            $payload = $mapping->payload ?? [];
            $attributes = $payload['source_attributes'] ?? [];
            $name = trim((string) ($payload['source_name'] ?? ''));
            $sourceValue = (string) ($payload['source_value'] ?? '');
            if ($mapping->entity_type !== 'category' && $name === '') {
                $errors[] = "{$mapping->entity_type} {$mapping->source_key}: source name is empty";
            }
            if ($mapping->entity_type === 'category' && $this->diameterValue($sourceValue) === null) {
                $errors[] = "category {$mapping->source_key}: '{$sourceValue}' is not a numeric diameter; re-analyze the source before creating master data";
            }
            if (in_array($mapping->entity_type, ['customer', 'supplier'], true)) {
                $parentCode = $mapping->entity_type === 'customer' ? '1.2' : '2.1';
                if (! Account::query()->where('company_id', $company->id)->where('code', $parentCode)->exists()) {
                    $errors[] = "{$mapping->entity_type} {$mapping->source_key}: target company is missing parent account {$parentCode}";
                }
            }
            if ($mapping->entity_type === 'warehouse' && ! $activeBranch) {
                $errors[] = "warehouse {$mapping->source_key}: target company has no active branch";
            }
            if ($mapping->entity_type === 'product') {
                if (! ($attributes['unit'] ?? null)) {
                    $errors[] = "product {$mapping->source_key}: choose a unit of measure";
                }
                $typeMapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'product_type')->where('source_key', (string) ($attributes['source_type_id'] ?? ''))->first();
                if (! $typeMapping || ! in_array($typeMapping->decision, ['matched', 'create_required'], true)) {
                    $errors[] = "product {$mapping->source_key}: approve product type source ".($attributes['source_type_id'] ?? '');
                }
                if (($attributes['diameter_value'] ?? null) !== null) {
                    $categoryMapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'category')->where('source_key', (string) ($attributes['source_category_id'] ?? ''))->first();
                    if (! $categoryMapping || ! in_array($categoryMapping->decision, ['matched', 'create_required'], true)) {
                        $errors[] = "product {$mapping->source_key}: approve numeric diameter source ".($attributes['source_category_id'] ?? '');
                    }
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['mapping' => array_slice($errors, 0, 50)]);
        }
    }

    private function excludeTextCategories(DataMigrationRun $run): void
    {
        DataMigrationMapping::query()
            ->where('run_id', $run->id)
            ->where('entity_type', 'category')
            ->get()
            ->each(function (DataMigrationMapping $mapping): void {
                $payload = $mapping->payload ?? [];
                if ($this->diameterValue((string) ($payload['source_value'] ?? '')) !== null) {
                    return;
                }
                $payload['reviewed'] = true;
                $payload['source_kind'] = 'non_diameter_item';
                $mapping->forceFill(['target_id' => null, 'target_type' => null, 'decision' => 'excluded', 'payload' => $payload])->save();
            });
    }

    /** @param array<string, mixed> $attributes */
    private function createParty(Company $company, User $actor, string $name, array $attributes, bool $customer): int
    {
        $parentCode = $customer ? '1.2' : '2.1';
        $parent = Account::query()->where('company_id', $company->id)->where('code', $parentCode)->first();
        if (! $parent) {
            throw ValidationException::withMessages(['mapping' => "Target company is missing parent account {$parentCode}."]);
        }
        $party = $this->accounting->createParty($company, $actor, [
            'parent_account_id' => $parent->id,
            'name' => $name,
            'phone' => $attributes['phone'] ?? null,
            'address' => $attributes['address'] ?? null,
            'tax_number' => $attributes['tax_number'] ?? null,
            'is_customer' => $customer,
            'is_supplier' => ! $customer,
            'opening_balance' => 0,
        ]);

        return $party->id;
    }

    private function createDiameter(Company $company, string $value): int
    {
        $numericValue = $this->diameterValue($value);
        if ($numericValue === null || $numericValue <= 0) {
            throw ValidationException::withMessages(['mapping' => 'Only numeric categories can be created as diameters automatically.']);
        }

        return Diameter::query()->firstOrCreate(['company_id' => $company->id, 'millimeters' => $numericValue])->id;
    }

    private function diameterValue(string $value): ?float
    {
        $normalized = strtr(trim($value), [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => ',',
        ]);

        if (! preg_match('/^\s*([0-9]+(?:[.,][0-9]+)?)\s*(?:مم|ملم|mm|㎜)?\s*$/iu', $normalized, $matches)) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    /** @param array<string, mixed> $attributes */
    private function createProduct(DataMigrationRun $run, Company $company, DataMigrationMapping $mapping, string $name, array $attributes): int
    {
        $typeMapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'product_type')->where('source_key', (string) ($attributes['source_type_id'] ?? ''))->first();
        if (! $typeMapping?->target_id || $typeMapping->decision !== 'matched') {
            throw ValidationException::withMessages(['mapping' => 'Approve the product type mapping before creating imported products.']);
        }
        $diameterId = null;
        if (($attributes['diameter_value'] ?? null) !== null) {
            $categoryMapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'category')->where('source_key', (string) ($attributes['source_category_id'] ?? ''))->first();
            if (! $categoryMapping?->target_id || $categoryMapping->decision !== 'matched') {
                throw ValidationException::withMessages(['mapping' => 'Approve the diameter mapping before creating imported steel products.']);
            }
            $diameterId = (int) $categoryMapping->target_id;
        }
        $sku = Str::limit('LEGACY-'.$run->id.'-'.$mapping->source_key, 76, '');
        $unit = $attributes['unit'] ?? null;
        if (! $unit) {
            throw ValidationException::withMessages(['mapping' => "Choose a unit of measure before creating imported product {$name}."]);
        }
        $product = Product::query()->firstOrCreate(
            ['company_id' => $company->id, 'sku' => $sku],
            ['product_type_id' => (int) $typeMapping->target_id, 'diameter_id' => $diameterId, 'name' => $name, 'unit' => $unit, 'minimum_stock' => 0, 'status' => 'active'],
        );

        return $product->id;
    }

    private function createCashbox(Company $company, User $actor, string $name): int
    {
        return $this->accounting->createCashbox($company, $actor, $name)->id;
    }

    /** @param array<string, mixed> $attributes */
    private function createBank(Company $company, User $actor, array $attributes): int
    {
        return $this->accounting->createBank($company, $actor, (string) ($attributes['bank_name'] ?? 'Imported bank'), $attributes['account_number'] ?? null)->id;
    }

    /** @param array<string, mixed> $attributes */
    private function createWarehouse(Company $company, DataMigrationMapping $mapping, string $name, array $attributes): int
    {
        $branch = Branch::query()->where('company_id', $company->id)->where('status', 'active')->orderBy('id')->first();
        if (! $branch) {
            throw ValidationException::withMessages(['mapping' => 'Create an active branch before creating imported warehouses.']);
        }
        $warehouse = Warehouse::query()->firstOrCreate(
            ['company_id' => $company->id, 'name' => $name],
            ['branch_id' => $branch->id, 'code' => 'LEGACY-'.$mapping->source_key, 'details' => $attributes['address'] ?? null, 'status' => 'active'],
        );

        return $warehouse->id;
    }
}
