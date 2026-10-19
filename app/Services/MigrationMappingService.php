<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bank;
use App\Models\Cashbox;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\Diameter;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MigrationMappingService
{
    public function __construct(private readonly LegacySqlSnapshotParser $parser) {}

    /** @return array<string, array<string, int>> */
    public function suggest(DataMigrationRun $run): array
    {
        $snapshot = $this->parser->parse(Storage::disk($run->source_disk)->path($run->source_path));
        $companyId = (int) $run->company_id;
        $summary = [];

        $summary['customers'] = $this->mapNamed($run, 'customer', 'customer', $snapshot['customer'], 'id_customer', 'name_customer', CustomerSupplier::query()->where('company_id', $companyId)->where('is_customer', true)->pluck('name', 'id')->all(), 'CustomerSupplier');
        $summary['suppliers'] = $this->mapNamed($run, 'supplier', 'import', $snapshot['import'], 'id_import', 'name', CustomerSupplier::query()->where('company_id', $companyId)->where('is_supplier', true)->pluck('name', 'id')->all(), 'CustomerSupplier');
        $summary['product_types'] = $this->mapNamed($run, 'product_type', 'type_mm', $snapshot['type_mm'], 'id_type', 'name', ProductType::query()->where('company_id', $companyId)->pluck('name', 'id')->all(), 'ProductType');
        $summary['categories'] = $this->mapCategories($run, $snapshot['categories'], $companyId);
        $summary['stores'] = $this->mapNamed($run, 'warehouse', 'stores', $snapshot['stores'], 'id_stores', 'name_stores', Warehouse::query()->where('company_id', $companyId)->pluck('name', 'id')->all(), 'Warehouse');
        $summary['cashboxes'] = $this->mapNamed($run, 'cashbox', 'name_treasury', $snapshot['name_treasury'], 'id_name_treasury', 'name_treasury', Cashbox::query()->where('company_id', $companyId)->pluck('name', 'id')->all(), 'Cashbox');
        $bankRows = collect($snapshot['bank_accounts'])->map(function (array $row) use ($snapshot): array {
            $bank = collect($snapshot['name_bank'])->firstWhere('id_name_bank', $row['id_name_bank'] ?? null);
            $row['display_name'] = trim((string) ($bank['name_bank'] ?? 'Bank').' / '.(string) ($row['account_number'] ?? ''));
            $row['source_bank_name'] = $bank['name_bank'] ?? null;

            return $row;
        })->values()->all();
        $bankTargets = Bank::query()->where('company_id', $companyId)->get(['id', 'name', 'account_number'])->mapWithKeys(fn (Bank $bank): array => [$bank->id => trim($bank->name.' / '.($bank->account_number ?? ''))])->all();
        $summary['banks'] = $this->mapNamed($run, 'bank', 'bank_accounts', $bankRows, 'id_bank_accounts', 'display_name', $bankTargets, 'Bank');
        $summary['products'] = $this->mapProducts($run, $snapshot, $companyId);

        return $summary;
    }

    /** @param list<array<string, mixed>> $rows @param array<int|string, mixed> $targets @return array<string, int> */
    private function mapNamed(DataMigrationRun $run, string $entity, string $sourceTable, array $rows, string $sourceKey, string $sourceName, array $targets, string $targetType): array
    {
        $normalizedTargets = [];
        foreach ($targets as $id => $name) {
            $normalizedTargets[$this->normalize((string) $name)] = (int) $id;
        }
        $counts = $this->emptyCounts();
        foreach ($rows as $row) {
            $key = (string) ($row[$sourceKey] ?? '');
            $name = trim((string) ($row[$sourceName] ?? ''));
            $targetId = $normalizedTargets[$this->normalize($name)] ?? null;
            $decision = $targetId ? 'matched' : ($name === '' ? 'needs_review' : 'create_required');
            $existing = $this->findMapping($run, $entity, $sourceTable, $key);
            $reviewed = (bool) data_get($existing?->payload, 'reviewed', false);
            if ($reviewed) {
                $targetId = $existing->target_id;
                $decision = $existing->decision;
            }
            $counts[$decision]++;
            DataMigrationMapping::query()->updateOrCreate(
                ['run_id' => $run->id, 'entity_type' => $entity, 'source_table' => $sourceTable, 'source_key' => $key],
                ['target_type' => $targetType, 'target_id' => $targetId, 'decision' => $decision, 'confidence' => $targetId ? 1 : 0, 'payload' => ['source_name' => $name, 'normalized_name' => $this->normalize($name), 'source_attributes' => $this->sourceAttributes($row, $entity), 'reviewed' => $reviewed]],
            );
        }

        return $counts;
    }

    /** @param list<array<string, mixed>> $rows @return array<string, int> */
    private function mapCategories(DataMigrationRun $run, array $rows, int $companyId): array
    {
        $diameters = Diameter::query()->where('company_id', $companyId)->pluck('millimeters', 'id')->all();
        $normalizedTargets = [];
        foreach ($diameters as $id => $millimeters) {
            $normalizedTargets[$this->normalizeNumber($millimeters)] = (int) $id;
        }
        $counts = $this->emptyCounts();
        foreach ($rows as $row) {
            $key = (string) ($row['id_categories'] ?? '');
            $value = trim((string) ($row['categories'] ?? ''));
            $numericValue = $this->diameterValue($value);
            $normalized = $numericValue !== null ? $this->normalizeNumber($numericValue) : null;
            $targetId = $normalized !== null ? ($normalizedTargets[$normalized] ?? null) : null;
            $decision = $numericValue === null ? 'needs_review' : ($targetId ? 'matched' : 'create_required');
            $existing = $this->findMapping($run, 'category', 'categories', $key);
            $reviewed = (bool) data_get($existing?->payload, 'reviewed', false);
            if ($reviewed) {
                $targetId = $existing->target_id;
                $decision = $existing->decision;
            }
            $counts[$decision]++;
            DataMigrationMapping::query()->updateOrCreate(
                ['run_id' => $run->id, 'entity_type' => 'category', 'source_table' => 'categories', 'source_key' => $key],
                ['target_type' => $numericValue !== null ? 'Diameter' : null, 'target_id' => $targetId, 'decision' => $decision, 'confidence' => $targetId ? 1 : 0, 'payload' => ['source_value' => $value, 'source_kind' => $numericValue !== null ? 'diameter_candidate' : 'non_diameter_item', 'normalized_value' => $normalized, 'steel_or_other' => (int) ($row['steel_or_other'] ?? 1), 'reviewed' => $reviewed]],
            );
        }

        return $counts;
    }

    /** @param array<string, list<array<string, mixed>>> $snapshot @return array<string, int> */
    private function mapProducts(DataMigrationRun $run, array $snapshot, int $companyId): array
    {
        $types = collect($snapshot['type_mm'])->keyBy(fn (array $row): string => (string) ($row['id_type'] ?? ''));
        $categories = collect($snapshot['categories'])->keyBy(fn (array $row): string => (string) ($row['id_categories'] ?? ''));
        $buyParents = collect($snapshot['buy'])->filter(fn (array $row): bool => $this->inRange($row['date'] ?? null, $run->documents_from, $run->documents_to))->pluck('id_buy')->map(fn ($id): string => (string) $id);
        $salesParents = collect($snapshot['sales'])->filter(fn (array $row): bool => $this->inRange($row['insert_date'] ?? null, $run->documents_from, $run->documents_to))->pluck('id_sales')->map(fn ($id): string => (string) $id);
        $pairs = collect($snapshot['details_buy'])->filter(fn (array $row): bool => $buyParents->contains((string) ($row['id_buy'] ?? '')))->merge(collect($snapshot['details_sales'])->filter(fn (array $row): bool => $salesParents->contains((string) ($row['id_sales'] ?? ''))))->map(fn (array $row): string => (string) ($row['id_type'] ?? '').':'.(string) ($row['id_categories'] ?? ''))->unique()->values();
        $typeMappings = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'product_type')->get()->keyBy('source_key');
        $categoryMappings = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'category')->get()->keyBy('source_key');
        $products = Product::query()->where('company_id', $companyId)->get(['id', 'name', 'product_type_id', 'diameter_id'])->keyBy('id');
        $counts = $this->emptyCounts();

        foreach ($pairs as $pair) {
            [$typeId, $categoryId] = array_pad(explode(':', $pair, 2), 2, '');
            $type = $types->get($typeId, []);
            $category = $categories->get($categoryId, []);
            $typeMapping = $typeMappings->get($typeId);
            $categoryMapping = $categoryMappings->get($categoryId);
            $typeName = trim((string) ($type['name'] ?? ''));
            $categoryName = trim((string) ($category['categories'] ?? ''));
            if ($typeName === '' || $categoryName === '' || ! $typeMapping) {
                $counts['needs_review']++;

                continue;
            }
            $numericCategory = $this->diameterValue($categoryName);
            $targetTypeId = $typeMapping->target_id;
            $targetDiameterId = $numericCategory !== null && $categoryMapping?->decision === 'matched' ? $categoryMapping->target_id : null;
            $productName = $numericCategory !== null ? $typeName.' '.(float) $numericCategory.' mm' : $typeName.' / '.$categoryName;
            $target = $products->first(function (Product $product) use ($targetTypeId, $targetDiameterId, $numericCategory, $productName): bool {
                if ((int) $product->product_type_id !== (int) $targetTypeId) {
                    return false;
                }
                if ($numericCategory !== null) {
                    return (int) $product->diameter_id === (int) $targetDiameterId;
                }

                return $this->normalize((string) $product->name) === $this->normalize($productName);
            });
            $decision = $target ? 'matched' : ($typeMapping->decision === 'matched' && ($numericCategory === null || $categoryMapping?->decision === 'matched') ? 'create_required' : 'needs_review');
            $existing = $this->findMapping($run, 'product', 'legacy_product', $pair);
            $reviewed = (bool) data_get($existing?->payload, 'reviewed', false);
            if ($reviewed) {
                $target = $existing->target_id ? $products->get((int) $existing->target_id) : null;
                $decision = $existing->decision;
            }
            $counts[$decision]++;
            DataMigrationMapping::query()->updateOrCreate(
                ['run_id' => $run->id, 'entity_type' => 'product', 'source_table' => 'legacy_product', 'source_key' => $pair],
                ['target_type' => 'Product', 'target_id' => $target?->id, 'decision' => $decision, 'confidence' => $target ? 1 : 0, 'payload' => ['source_name' => $productName, 'source_attributes' => ['source_type_id' => $typeId, 'source_category_id' => $categoryId, 'type_name' => $typeName, 'category_name' => $categoryName, 'diameter_value' => $numericCategory, 'unit' => $numericCategory !== null ? 'ton' : null], 'reviewed' => $reviewed]],
            );
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return ['matched' => 0, 'needs_review' => 0, 'create_required' => 0];
    }

    private function findMapping(DataMigrationRun $run, string $entity, string $sourceTable, string $sourceKey): ?DataMigrationMapping
    {
        return DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', $entity)->where('source_table', $sourceTable)->where('source_key', $sourceKey)->first();
    }

    private function inRange(mixed $value, mixed $from, mixed $to): bool
    {
        if ($value === null || $from === null || $to === null) {
            return false;
        }
        try {
            return CarbonImmutable::parse((string) $value)->betweenIncluded(CarbonImmutable::parse((string) $from), CarbonImmutable::parse((string) $to));
        } catch (\Throwable) {
            return false;
        }
    }

    private function diameterValue(string $value): ?float
    {
        if (! preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(?:مم|mm)?\s*$/iu', $value, $matches)) {
            return null;
        }

        return (float) $matches[1];
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }

    /** @return array<string, mixed> */
    private function sourceAttributes(array $row, string $entity): array
    {
        return match ($entity) {
            'customer' => ['phone' => $row['phon'] ?? null, 'address' => $row['address'] ?? null, 'tax_number' => null],
            'supplier' => ['phone' => null, 'address' => null, 'tax_number' => null],
            'product_type' => ['kind' => ((int) ($row['steel_or_other'] ?? 1)) === 1 ? 'steel' : 'other'],
            'warehouse' => ['address' => $row['address'] ?? null],
            'cashbox' => [],
            'bank' => ['bank_name' => $row['source_bank_name'] ?? $row['display_name'] ?? null, 'account_number' => $row['account_number'] ?? null],
            default => [],
        };
    }

    private function normalizeNumber(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
