<?php

declare(strict_types=1);

namespace App\Services\Migration;

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
use App\Services\AccountingService;
use App\Services\InventoryProductResolver;
use App\Services\Legacy\LegacyLedger;
use Illuminate\Support\Facades\DB;

final class MasterDataService
{
    private const ORDER = ['branch' => 1, 'customer' => 2, 'supplier' => 3, 'product_type' => 4, 'category' => 5, 'warehouse' => 6, 'cashbox' => 7, 'bank' => 8, 'product' => 9];

    public function __construct(private readonly AccountingService $accounting, private readonly InventoryProductResolver $resolver) {}

    /** @return array{ok: bool, blockers: list<array<string, mixed>>, counts: array<string, int>} */
    public function preflight(DataMigrationRun $run): array
    {
        $blockers = [];
        $counts = ['create_required' => 0, 'matched' => 0, 'excluded' => 0, 'needs_review' => 0];
        $company = (int) $run->company_id;
        $has = fn (string $code) => Account::query()->where('company_id', $company)->where('code', $code)->exists();
        $mappings = DataMigrationMapping::query()->where('run_id', $run->id)->get();
        $byKey = $mappings->keyBy(fn ($m) => $m->entity_type.'|'.$m->source_key);
        $block = function (string $code, DataMigrationMapping $m, array $params = []) use (&$blockers): void {
            if (count($blockers) < 500) {
                $blockers[] = ['code' => $code, 'entity' => $m->entity_type, 'source_key' => $m->source_key, 'mapping_id' => $m->id, 'name' => $m->payload['source_name'] ?? $m->payload['source_value'] ?? null, 'params' => $params];
            }
        };
        foreach ($mappings as $m) {
            $counts[$m->decision] = ($counts[$m->decision] ?? 0) + 1;
            $payload = $m->payload ?? [];
            $attrs = $payload['source_attributes'] ?? [];
            if ($m->decision === 'needs_review') {
                $block('mapping_needs_review', $m, ['note' => $payload['note'] ?? null]);

                continue;
            }
            if ($m->decision === 'excluded' && trim((string) $m->reason) === '' && ! in_array($payload['note'] ?? null, ['material_text_not_diameter'], true)) {
                $block('exclusion_reason_missing', $m);
            }
            if ($m->decision !== 'create_required') {
                continue;
            }
            $name = trim((string) ($payload['source_name'] ?? ''));
            if ($m->entity_type !== 'category' && $name === '') {
                $block('source_name_missing', $m);
            }
            if (in_array($m->entity_type, ['customer', 'supplier'], true) && ! $has($m->entity_type === 'customer' ? '1.2' : '2.1')) {
                $block('control_account_missing', $m, ['account' => $m->entity_type === 'customer' ? '1.2' : '2.1']);
            }
            if (in_array($m->entity_type, ['cashbox', 'bank'], true) && ! $has('1.2')) {
                $block('control_account_missing', $m, ['account' => '1.2']);
            }
            if ($m->entity_type === 'warehouse') {
                $branch = $byKey->get('branch|default');
                if (! $branch || ! in_array($branch->decision, ['matched', 'create_required'], true)) {
                    $block('branch_unresolved', $m);
                }
            }
            if ($m->entity_type === 'category' && LegacyLedger::diameter((string) ($payload['source_value'] ?? '')) === null) {
                $block('not_a_numeric_diameter', $m);
            }
            if ($m->entity_type === 'product') {
                if (empty($attrs['unit_basis'])) {
                    $block('unit_required', $m);
                }
                $type = $byKey->get('product_type|'.($attrs['type_key'] ?? ''));
                if (! $type || ! in_array($type->decision, ['matched', 'create_required'], true)) {
                    $block('product_type_unresolved', $m, ['type_key' => $attrs['type_key'] ?? null]);
                }
                if (($attrs['diameter_value'] ?? null) !== null) {
                    $cat = $byKey->get('category|'.($attrs['category_key'] ?? ''));
                    if (! $cat || ! in_array($cat->decision, ['matched', 'create_required'], true)) {
                        $block('diameter_unresolved', $m, ['category_key' => $attrs['category_key'] ?? null]);
                    }
                }
            }
        }
        foreach ($mappings->where('decision', 'matched') as $m) {
            if ($m->target_id === null && $m->entity_type !== 'payment_source') {
                $block('matched_without_target', $m);
            }
        }

        return ['ok' => $blockers === [], 'blockers' => $blockers, 'counts' => $counts];
    }

    /** @return array<string, int> created counts */
    public function create(DataMigrationRun $run, User $actor): array
    {
        $company = Company::query()->findOrFail($run->company_id);
        $counts = array_fill_keys(['branches', 'customers', 'suppliers', 'product_types', 'diameters', 'warehouses', 'cashboxes', 'banks', 'products'], 0);

        DB::transaction(function () use ($run, $actor, $company, &$counts): void {
            $mappings = DataMigrationMapping::query()->where('run_id', $run->id)->where('decision', 'create_required')->lockForUpdate()->get()
                ->sortBy(fn ($m) => (self::ORDER[$m->entity_type] ?? 99) * 1000000 + $m->id);
            foreach ($mappings as $m) {
                $payload = $m->payload ?? [];
                $attrs = $payload['source_attributes'] ?? [];
                $name = trim((string) ($payload['source_name'] ?? ''));
                [$id, $key] = match ($m->entity_type) {
                    'branch' => [Branch::query()->create(['company_id' => $company->id, 'code' => 'MAIN-'.substr(bin2hex(random_bytes(3)), 0, 6), 'name' => 'Main branch', 'status' => 'active'])->id, 'branches'],
                    'customer' => [$this->party($company, $actor, $name, $attrs, true), 'customers'],
                    'supplier' => [$this->party($company, $actor, $name, $attrs, false), 'suppliers'],
                    'product_type' => [ProductType::query()->create(['company_id' => $company->id, 'name' => $name, 'kind' => $attrs['kind'] ?? 'other', 'status' => 'active'])->id, 'product_types'],
                    'category' => [Diameter::query()->firstOrCreate(['company_id' => $company->id, 'millimeters' => LegacyLedger::diameter((string) $payload['source_value'])])->id, 'diameters'],
                    'warehouse' => [$this->warehouse($run, $company, $m, $name, $attrs), 'warehouses'],
                    'cashbox' => [$this->accounting->createCashbox($company, $actor, $name)->id, 'cashboxes'],
                    'bank' => [$this->accounting->createBank($company, $actor, $name, $attrs['account_number'] ?? null)->id, 'banks'],
                    'product' => [$this->product($run, $company, $name, $attrs), 'products'],
                    default => [null, null],
                };
                if ($id === null) {
                    continue;
                }
                $payload['created'] = true;
                $payload['reviewed'] = true;
                $m->forceFill(['target_id' => $id, 'decision' => 'matched', 'confidence' => 1, 'payload' => $payload])->save();
                $counts[$key]++;
            }
        });

        return $counts;
    }

    /** @param array<string, mixed> $attrs */
    private function party(Company $company, User $actor, string $name, array $attrs, bool $customer): int
    {
        $parent = Account::query()->where('company_id', $company->id)->where('code', $customer ? '1.2' : '2.1')->firstOrFail();

        return $this->accounting->createParty($company, $actor, [
            'parent_account_id' => $parent->id, 'name' => $name, 'phone' => $attrs['phone'] ?? null, 'address' => $attrs['address'] ?? null,
            'is_customer' => $customer, 'is_supplier' => ! $customer, 'opening_balance' => 0,
        ])->id;
    }

    /** @param array<string, mixed> $attrs */
    private function warehouse(DataMigrationRun $run, Company $company, DataMigrationMapping $m, string $name, array $attrs): int
    {
        $branchId = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'branch')->where('source_key', 'default')->value('target_id');
        $branch = Branch::query()->where('company_id', $company->id)->findOrFail($branchId);

        return Warehouse::query()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'code' => 'LEG-'.$run->id.'-'.$m->source_key, 'name' => $name, 'details' => $attrs['address'] ?? null, 'status' => 'active'])->id;
    }

    /** @param array<string, mixed> $attrs */
    private function product(DataMigrationRun $run, Company $company, string $name, array $attrs): int
    {
        $target = fn (string $entity, string $key) => (int) DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $entity)->where('source_key', $key)->value('target_id');
        $typeId = $target('product_type', (string) $attrs['type_key']);
        $weight = in_array($attrs['unit_basis'], ['ton_weight', 'kg_weight'], true);
        if (($attrs['diameter_value'] ?? null) !== null && $weight) {
            $product = $this->resolver->resolve($company, ['product_type_id' => $typeId, 'diameter_id' => $target('category', (string) $attrs['category_key'])]);

            return $product->id;
        }
        $category = trim((string) $attrs['category_name']);
        $existing = Product::query()->where('company_id', $company->id)->where('product_type_id', $typeId)->where('material_category', $category)->first();
        if ($existing) {
            return $existing->id;
        }
        $unit = $weight ? 'ton' : (string) $attrs['unit_basis'];

        return Product::query()->create([
            'company_id' => $company->id, 'product_type_id' => $typeId, 'diameter_id' => null, 'material_category' => $category,
            'sku' => 'MAT-'.$typeId.'-'.sprintf('%08x', crc32($category)), 'name' => $name, 'unit' => $unit,
            'quantity_basis' => $weight ? 'weight' : $unit, 'minimum_stock' => 0, 'status' => 'active',
        ])->id;
    }
}
