<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\Diameter;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Warehouse;
use App\Services\Legacy\LegacyDataset;
use App\Services\Legacy\LegacyLedger;
use App\Services\Legacy\MappingIndex;

/**
 * Builds one mapping row for every legacy entity that has a financial effect (opening balances or in-range documents),
 * including entities that are referenced but missing from their master table. Human decisions are never overwritten.
 */
final class MappingGenerator
{
    /** @var array<string, array<string, mixed>> */
    private array $seen = [];

    private DataMigrationRun $run;

    private MappingIndex $existing;

    /** @return array<string, array<string, int>> counts per entity by decision */
    public function generate(DataMigrationRun $run, LegacyDataset $d): array
    {
        $this->run = $run;
        $this->seen = [];
        $this->existing = new MappingIndex($run);
        $ledger = new LegacyLedger($d, $run, function (string $k): ?string {
            $m = $this->existing->get('product', $k);
            $basis = data_get($m?->payload, 'source_attributes.unit_basis');

            return $basis === 'kg_weight' ? 'kg' : ($basis === 'ton_weight' ? 'ton' : null);
        });
        $refs = $this->references($d, $ledger);
        $company = (int) $run->company_id;

        $this->parties($d, $refs, $company);
        $this->branches($company);
        $this->warehouses($d, $refs, $company);
        $this->productTypesAndCategories($d, $refs, $ledger, $company);
        $this->products($d, $refs, $ledger, $company);
        $this->cashAndBanks($d, $refs, $company);

        $this->retire();

        return $this->summary();
    }

    /** @return array<string, array<string, true>> */
    private function references(LegacyDataset $d, LegacyLedger $ledger): array
    {
        $r = ['customer' => [], 'supplier' => [], 'warehouse' => [], 'product' => [], 'type' => [], 'category' => [], 'cashbox' => [], 'bank' => []];
        $from = $ledger->from();
        $to = $ledger->to();
        $in = fn ($date) => ($x = LegacyDataset::date($date)) !== null && $x->betweenIncluded($from, $to);
        $opening = $ledger->opening();
        foreach ($opening['customers'] as $k => $c) {
            if ($c['start'] != 0.0 || abs($c['invoices'] - $c['receipts']) > 0.004) {
                $r['customer'][$k] = true;
            }
        }
        foreach ($opening['suppliers'] as $k => $c) {
            if ($c['start'] != 0.0 || abs($c['invoices'] - $c['payments']) > 0.004) {
                $r['supplier'][$k] = true;
            }
        }
        foreach ($d->rows('buy') as $b) {
            if (! $in($b['date'])) {
                continue;
            }
            $r['supplier'][LegacyDataset::key($b['id_import'])] = true;
            $box = $this->treasuryBox($d, 'buy', $b['id_buy']);
            if ($box !== null && $box !== '0') {
                $r['cashbox'][$box] = true;
            }
            foreach ($d->where('details_buy', 'id_buy', LegacyDataset::key($b['id_buy'])) as $l) {
                $r['product'][LegacyLedger::productKey($l['id_type'], $l['id_categories'])] = true;
                $r['warehouse'][LegacyDataset::key($l['id_stores'])] = true;
            }
            if (LegacyDataset::key($b['id_stores']) !== '0') {
                $r['warehouse'][LegacyDataset::key($b['id_stores'])] = true;
            }
        }
        foreach ($d->rows('sales') as $s) {
            if (! $in($s['insert_date'])) {
                continue;
            }
            $r['customer'][LegacyDataset::key($s['id_customer'])] = true;
            $box = $this->treasuryBox($d, 'sales', $s['id_sales']);
            if ($box !== null && $box !== '0') {
                $r['cashbox'][$box] = true;
            }
            foreach ($d->where('details_sales', 'id_sales', LegacyDataset::key($s['id_sales'])) as $l) {
                $r['product'][LegacyLedger::productKey($l['id_type'], $l['id_categories'])] = true;
                $r['warehouse'][LegacyDataset::key($l['id_stores'])] = true;
            }
            if (LegacyDataset::key($s['id_stores']) !== '0') {
                $r['warehouse'][LegacyDataset::key($s['id_stores'])] = true;
            }
        }
        foreach ($ledger->stock() as $k => $p) {
            if (abs($p['kg']) > 0.0005) {
                $r['product'][$k] = true;
                foreach ($p['warehouses'] as $w) {
                    if ($w !== '?') {
                        $r['warehouse'][$w] = true;
                    }
                }
            }
        }
        $cash = $ledger->cashAndBanks();
        foreach ($cash['cashboxes'] as $k => $v) {
            if (abs($v) > 0.004) {
                $r['cashbox'][$k] = true;
            }
        }
        foreach ($cash['banks'] as $k => $v) {
            if (abs($v) > 0.004) {
                $r['bank'][$k] = true;
            }
        }
        foreach (array_keys($r['product']) as $k) {
            [$t, $c] = explode(':', $k);
            $r['type'][$t] = true;
            $r['category'][$c] = true;
        }
        unset($r['customer']['0']);
        $r['customer_walkin'] = $this->hasWalkIn($d, $in);
        unset($r['supplier']['0']);

        return $r;
    }

    /** @param callable(mixed): bool $in */
    private function hasWalkIn(LegacyDataset $d, callable $in): array
    {
        foreach ($d->rows('sales') as $s) {
            if ($in($s['insert_date']) && LegacyDataset::key($s['id_customer']) === '0') {
                return ['0' => true];
            }
        }

        return [];
    }

    public static function treasuryBox(LegacyDataset $d, string $table, mixed $id): ?string
    {
        foreach ($d->where('treasury', 'id_table_name', LegacyDataset::key($id)) as $t) {
            if ($t['name_table'] === $table) {
                return LegacyDataset::key($t['id_name_treasury']);
            }
        }

        return null;
    }

    /** @param array<string, array<string, true>> $refs */
    private function parties(LegacyDataset $d, array $refs, int $company): void
    {
        $targets = CustomerSupplier::query()->where('company_id', $company)->get(['id', 'name', 'is_customer', 'is_supplier']);
        foreach (['customer' => ['customer', 'id_customer', 'name_customer'], 'supplier' => ['import', 'id_import', 'name']] as $entity => [$table, $idCol, $nameCol]) {
            $byKey = [];
            foreach ($d->rows($table) as $row) {
                $byKey[LegacyDataset::key($row[$idCol])] = $row;
            }
            $keys = array_map('strval', array_keys($refs[$entity]));
            if ($entity === 'customer') {
                foreach (array_map('strval', array_keys($refs['customer_walkin'])) as $w) {
                    $keys[] = $w;
                }
            }
            $dupNames = [];
            foreach ($keys as $k) {
                $n = LegacyLedger::nameKey((string) ($byKey[$k][$nameCol] ?? ''));
                if ($n !== '') {
                    $dupNames[$n][] = $k;
                }
            }
            $claimed = [];
            foreach ($keys as $k) {
                $row = $byKey[$k] ?? null;
                $name = trim((string) ($row[$nameCol] ?? ''));
                $attrs = $entity === 'customer'
                    ? ['phone' => $row['phon'] ?? null, 'address' => $row['address'] ?? null]
                    : ['phone' => null, 'address' => null];
                if ($k === '0') {
                    $name = '';
                }
                $base = ['source_name' => $name, 'source_attributes' => $attrs, 'missing_in_source' => $row === null && $k !== '0', 'walk_in' => $k === '0'];
                $this->decideNamed($entity, $table, $k, $name, $targets, $base, 'CustomerSupplier', $dupNames, $claimed, fn (CustomerSupplier $t): bool => $entity === 'customer' ? (bool) $t->is_customer : (bool) $t->is_supplier);
            }
        }
    }

    /**
     * @param  iterable<int, mixed>  $targets
     * @param  array<string, mixed>  $base
     * @param  array<string, list<string>>  $dupNames
     * @param  array<int, string>  $claimed
     * @param  callable(object): bool  $roleOk
     */
    private function decideNamed(string $entity, string $table, string $key, string $name, iterable $targets, array $base, string $targetType, array $dupNames, array &$claimed, ?callable $roleOk = null): void
    {
        $existing = $this->existing->get($entity, $key);
        if ($existing && $this->frozen($existing)) {
            $this->save($entity, $table, $key, $targetType, $existing->target_id, $existing->decision, $existing->confidence ?? 1, array_merge($existing->payload ?? [], $base, ['reviewed' => $existing->isReviewed()]), true);

            return;
        }
        $nk = LegacyLedger::nameKey($name);
        $exact = [];
        $fold = [];
        foreach ($targets as $t) {
            $tk = LegacyLedger::nameKey((string) $t->name);
            if ($nk !== '' && $tk === $nk) {
                $exact[] = $t;
            } elseif ($nk !== '' && $this->fold($tk) === $this->fold($nk)) {
                $fold[] = $t;
            }
        }
        $candidates = fn (array $list) => array_map(fn ($t) => ['id' => (int) $t->id, 'name' => (string) $t->name], $list);
        $payload = $base + ['candidates' => []];
        $decision = 'create_required';
        $targetId = null;
        $note = null;
        if ($name === '') {
            $decision = 'needs_review';
            $note = $base['walk_in'] ?? false ? 'walk_in_party' : 'source_name_missing';
        } elseif (count($dupNames[$nk] ?? []) > 1) {
            $decision = 'needs_review';
            $note = 'duplicate_source_name';
        } elseif (count($exact) > 1) {
            $decision = 'needs_review';
            $note = 'duplicate_targets';
            $payload['candidates'] = $candidates($exact);
        } elseif (count($exact) === 1) {
            $t = $exact[0];
            if ($roleOk !== null && ! $roleOk($t)) {
                $decision = 'needs_review';
                $note = 'other_role_exists';
                $payload['candidates'] = $candidates($exact);
            } elseif (isset($claimed[(int) $t->id])) {
                $decision = 'needs_review';
                $note = 'target_claimed_twice';
                $payload['candidates'] = $candidates($exact);
            } else {
                $decision = 'matched';
                $targetId = (int) $t->id;
                $claimed[$targetId] = $key;
                $note = 'name_exact';
            }
        } elseif ($fold !== []) {
            $decision = 'needs_review';
            $note = 'similar_names';
            $payload['candidates'] = $candidates($fold);
        }
        $payload['note'] = $note;
        $payload['reviewed'] = false;
        $this->save($entity, $table, $key, $targetType, $targetId, $decision, $decision === 'matched' ? 1 : 0, $payload, false);
    }

    private function fold(string $s): string
    {
        return strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
    }

    private function frozen(DataMigrationMapping $m): bool
    {
        return $m->locked_at !== null || $m->isReviewed() || (bool) data_get($m->payload, 'created', false);
    }

    private function branches(int $company): void
    {
        $existing = $this->existing->get('branch', 'default');
        $branches = Branch::query()->where('company_id', $company)->where('status', 'active')->orderBy('id')->get(['id', 'name']);
        if ($existing && $this->frozen($existing)) {
            $this->save('branch', 'company', 'default', 'Branch', $existing->target_id, $existing->decision, 1, $existing->payload ?? [], true);

            return;
        }
        [$decision, $target, $note] = match (true) {
            $branches->count() === 1 => ['matched', (int) $branches[0]->id, 'single_active_branch'],
            $branches->count() > 1 => ['needs_review', null, 'choose_branch'],
            default => ['create_required', null, 'no_branch'],
        };
        $this->save('branch', 'company', 'default', 'Branch', $target, $decision, $target ? 1 : 0, ['source_name' => 'Main branch', 'note' => $note, 'candidates' => $branches->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])->all(), 'reviewed' => false], false);
    }

    /** @param array<string, array<string, true>> $refs */
    private function warehouses(LegacyDataset $d, array $refs, int $company): void
    {
        $byKey = [];
        foreach ($d->rows('stores') as $s) {
            $byKey[LegacyDataset::key($s['id_stores'])] = $s;
        }
        $targets = Warehouse::query()->where('company_id', $company)->get(['id', 'name']);
        $keys = array_map('strval', array_keys($refs['warehouse']));
        $dup = [];
        foreach ($keys as $k) {
            $n = LegacyLedger::nameKey((string) ($byKey[$k]['name_stores'] ?? ''));
            if ($n !== '') {
                $dup[$n][] = $k;
            }
        }
        $claimed = [];
        foreach ($keys as $k) {
            $row = $byKey[$k] ?? null;
            $name = $k === '0' ? '' : trim((string) ($row['name_stores'] ?? ''));
            $this->decideNamed('warehouse', 'stores', $k, $name, $targets, ['source_name' => $name, 'source_attributes' => ['address' => $row['address'] ?? null], 'missing_in_source' => $row === null], 'Warehouse', $dup, $claimed);
        }
    }

    /** @param array<string, array<string, true>> $refs */
    private function productTypesAndCategories(LegacyDataset $d, array $refs, LegacyLedger $ledger, int $company): void
    {
        $types = [];
        foreach ($d->rows('type_mm') as $t) {
            $types[LegacyDataset::key($t['id_type'])] = $t;
        }
        $targets = ProductType::query()->where('company_id', $company)->get(['id', 'name']);
        $dup = [];
        foreach (array_map('strval', array_keys($refs['type'])) as $k) {
            $n = LegacyLedger::nameKey((string) ($types[$k]['name'] ?? ''));
            if ($n !== '') {
                $dup[$n][] = $k;
            }
        }
        $claimed = [];
        foreach (array_map('strval', array_keys($refs['type'])) as $k) {
            $row = $types[$k] ?? null;
            $name = trim((string) ($row['name'] ?? ''));
            $this->decideNamed('product_type', 'type_mm', $k, $name, $targets, ['source_name' => $name, 'source_attributes' => ['kind' => ((int) ($row['steel_or_other'] ?? 1)) === 1 ? 'steel' : 'other'], 'missing_in_source' => $row === null], 'ProductType', $dup, $claimed);
        }

        $cats = [];
        foreach ($d->rows('categories') as $c) {
            $cats[LegacyDataset::key($c['id_categories'])] = $c;
        }
        $diameters = Diameter::query()->where('company_id', $company)->get(['id', 'millimeters']);
        foreach (array_map('strval', array_keys($refs['category'])) as $k) {
            $row = $cats[$k] ?? null;
            $value = trim((string) ($row['categories'] ?? ''));
            $num = LegacyLedger::diameter($value);
            $existing = $this->existing->get('category', $k);
            $payload = ['source_value' => $value, 'source_kind' => $num !== null ? 'diameter' : 'material_text', 'normalized_value' => $num, 'steel_or_other' => (int) ($row['steel_or_other'] ?? 0), 'missing_in_source' => $row === null];
            if ($existing && $this->frozen($existing)) {
                $keep = $num === null && in_array($existing->decision, ['create_required'], true);
                $this->save('category', 'categories', $k, $num !== null ? 'Diameter' : null, $keep ? null : $existing->target_id, $keep ? 'excluded' : $existing->decision, 1, array_merge($existing->payload ?? [], $payload), true);

                continue;
            }
            if ($row === null || $value === '') {
                $this->save('category', 'categories', $k, null, null, 'needs_review', 0, $payload + ['note' => 'source_name_missing', 'reviewed' => false], false);
            } elseif ($num === null) {
                $this->save('category', 'categories', $k, null, null, 'excluded', 1, $payload + ['note' => 'material_text_not_diameter', 'reviewed' => false], false);
            } else {
                $hit = $diameters->first(fn ($x) => abs((float) $x->millimeters - $num) < 0.0005);
                $this->save('category', 'categories', $k, 'Diameter', $hit?->id, $hit ? 'matched' : 'create_required', $hit ? 1 : 0, $payload + ['note' => $hit ? 'diameter_exact' : null, 'reviewed' => false], false);
            }
        }
    }

    /** @param array<string, array<string, true>> $refs */
    private function products(LegacyDataset $d, array $refs, LegacyLedger $ledger, int $company): void
    {
        $facts = $ledger->products();
        $erp = Product::query()->where('company_id', $company)->get(['id', 'product_type_id', 'diameter_id', 'material_category', 'name']);
        foreach (array_map('strval', array_keys($refs['product'])) as $k) {
            $f = $facts[$k] ?? null;
            if ($f === null) {
                continue;
            }
            $existing = $this->existing->get('product', $k);
            $keptBasis = data_get($existing?->payload, 'source_attributes.unit_basis');
            $basis = $keptBasis;
            if ($basis === null && $f['steel'] && in_array($f['evidence'], ['per_ton', 'none'], true)) {
                $basis = 'ton_weight';
            }
            $attrs = [
                'type_key' => $f['type_key'], 'category_key' => $f['category_key'], 'type_name' => $f['type_name'], 'category_name' => $f['category_name'],
                'diameter_value' => $f['numeric_diameter'], 'steel' => $f['steel'], 'unit_basis' => $basis, 'price_evidence' => $f['evidence'],
                'opening_cost_per_ton' => data_get($existing?->payload, 'source_attributes.opening_cost_per_ton'),
            ];
            $payload = ['source_name' => trim($f['type_name'].' '.($f['numeric_diameter'] !== null ? $this->mm($f['numeric_diameter']).' mm' : '/ '.$f['category_name'])), 'source_attributes' => $attrs, 'lines' => $f['lines'], 'missing_in_source' => ! $f['type_known'] || ! $f['category_known']];
            if ($existing && ($this->frozen($existing))) {
                $payload['reviewed'] = $existing->isReviewed();
                $payload['created'] = (bool) data_get($existing->payload, 'created', false);
                $this->save('product', 'legacy_product', $k, 'Product', $existing->target_id, $existing->decision, 1, $payload, true);

                continue;
            }
            $typeMap = $this->existing->get('product_type', $f['type_key']);
            $catMap = $this->existing->get('category', $f['category_key']);
            $target = null;
            if ($typeMap?->decision === 'matched' && $typeMap->target_id) {
                $target = $f['numeric_diameter'] !== null
                    ? ($catMap?->decision === 'matched' && $catMap->target_id ? $erp->first(fn ($p) => (int) $p->product_type_id === (int) $typeMap->target_id && (int) $p->diameter_id === (int) $catMap->target_id) : null)
                    : $erp->first(fn ($p) => (int) $p->product_type_id === (int) $typeMap->target_id && $p->material_category !== null && LegacyLedger::nameKey((string) $p->material_category) === LegacyLedger::nameKey($f['category_name']));
            }
            $note = null;
            $decision = 'create_required';
            if ($f['type_name'] === '' || $f['category_name'] === '') {
                $decision = 'needs_review';
                $note = 'source_name_missing';
            } elseif ($basis === null) {
                $decision = 'needs_review';
                $note = $f['steel'] ? 'price_basis_conflict' : 'unit_required';
            } elseif ($target) {
                $decision = 'matched';
                $note = 'existing_product';
            }
            $this->save('product', 'legacy_product', $k, 'Product', $target?->id, $decision, $target ? 1 : 0, $payload + ['note' => $note, 'reviewed' => false], false);
        }
    }

    private function mm(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** @param array<string, array<string, true>> $refs */
    private function cashAndBanks(LegacyDataset $d, array $refs, int $company): void
    {
        $names = [];
        foreach ($d->rows('name_treasury') as $n) {
            $names[LegacyDataset::key($n['id_name_treasury'])] = trim((string) $n['name_treasury']);
        }
        $targets = Cashbox::query()->where('company_id', $company)->get(['id', 'name']);
        $dup = [];
        foreach (array_map('strval', array_keys($refs['cashbox'])) as $k) {
            $dup[LegacyLedger::nameKey($names[$k] ?? '')][] = $k;
        }
        $claimed = [];
        foreach (array_map('strval', array_keys($refs['cashbox'])) as $k) {
            $this->decideNamed('cashbox', 'name_treasury', $k, $names[$k] ?? '', $targets, ['source_name' => $names[$k] ?? '', 'source_attributes' => [], 'missing_in_source' => ! isset($names[$k])], 'Cashbox', $dup, $claimed);
        }

        $bankNames = [];
        foreach ($d->rows('name_bank') as $n) {
            $bankNames[LegacyDataset::key($n['id_name_bank'])] = trim((string) $n['name_bank']);
        }
        $accounts = [];
        foreach ($d->rows('bank_accounts') as $a) {
            $accounts[LegacyDataset::key($a['id_bank_accounts'])] = $a;
        }
        $bankTargets = Bank::query()->where('company_id', $company)->get(['id', 'name', 'account_number'])->map(function ($b) {
            $b->name = trim($b->name.' / '.($b->account_number ?? ''));

            return $b;
        });
        $dup = [];
        $display = [];
        foreach (array_map('strval', array_keys($refs['bank'])) as $k) {
            $a = $accounts[$k] ?? null;
            $display[$k] = trim(($bankNames[LegacyDataset::key($a['id_name_bank'] ?? 0)] ?? '').' / '.(string) ($a['account_number'] ?? ''));
            $dup[LegacyLedger::nameKey($display[$k])][] = $k;
        }
        $claimed = [];
        foreach (array_map('strval', array_keys($refs['bank'])) as $k) {
            $a = $accounts[$k] ?? null;
            $this->decideNamed('bank', 'bank_accounts', $k, $display[$k], $bankTargets, ['source_name' => $display[$k], 'source_attributes' => ['bank_name' => $bankNames[LegacyDataset::key($a['id_name_bank'] ?? 0)] ?? null, 'account_number' => $a['account_number'] ?? null], 'missing_in_source' => $a === null], 'Bank', $dup, $claimed);
        }

        $existing = $this->existing->get('payment_source', 'none');
        $needsNone = $this->needsUnassignedSource($d);
        if ($existing && ($this->frozen($existing) || $needsNone)) {
            $this->save('payment_source', 'treasury', 'none', $existing->target_type, $existing->target_id, $existing->decision, 1, $existing->payload ?? [], true);
        } elseif ($needsNone) {
            $this->save('payment_source', 'treasury', 'none', null, null, 'needs_review', 0, ['source_name' => '—', 'note' => 'no_cashbox_recorded', 'reviewed' => false], false);
        }
    }

    private function needsUnassignedSource(LegacyDataset $d): bool
    {
        $from = $this->run->documents_from->toDateString();
        $to = $this->run->documents_to->toDateString();
        $has = function (string $table, string $dateCol, string $payCol) use ($d, $from, $to): bool {
            foreach ($d->rows($table) as $r) {
                $x = LegacyDataset::date($r[$dateCol]);
                if ($x === null || $x->toDateString() < $from || $x->toDateString() > $to) {
                    continue;
                }
                if (LegacyDataset::num($r[$payCol]) > 0 && $d->where($table === 'buy' ? 'details_buy' : 'details_sales', $table === 'buy' ? 'id_buy' : 'id_sales', LegacyDataset::key($r[$table === 'buy' ? 'id_buy' : 'id_sales'])) === []) {
                    $box = self::treasuryBox($d, $table, $r[$table === 'buy' ? 'id_buy' : 'id_sales']);
                    if ($box === null || $box === '0') {
                        return true;
                    }
                }
            }

            return false;
        };

        return $has('buy', 'date', 'eda3') || $has('sales', 'insert_date', 'cash');
    }

    private function save(string $entity, string $table, string $key, ?string $targetType, ?int $targetId, string $decision, mixed $confidence, array $payload, bool $keepMeta): void
    {
        $existing = $this->existing->get($entity, $key);
        $attrs = ['target_type' => $targetType, 'target_id' => $targetId, 'decision' => $decision, 'confidence' => $confidence, 'payload' => $payload];
        if ($existing !== null) {
            $existing->forceFill($attrs)->save();
        } else {
            $this->existing->put(DataMigrationMapping::query()->create(['run_id' => $this->run->id, 'entity_type' => $entity, 'source_table' => $table, 'source_key' => $key] + $attrs));
        }
        $this->seen[$entity.'|'.$key] = true;
    }

    /** Mappings that are no longer referenced and were never reviewed or used are dropped. */
    private function retire(): void
    {
        foreach ($this->existing->all() as $id => $m) {
            if (! isset($this->seen[$id]) && ! $this->frozen($m) && $m->decision !== 'matched') {
                $m->delete();
            }
        }
    }

    /** @return array<string, array<string, int>> */
    private function summary(): array
    {
        $out = [];
        foreach (DataMigrationMapping::query()->where('run_id', $this->run->id)->selectRaw('entity_type, decision, count(*) as n')->groupBy('entity_type', 'decision')->get() as $r) {
            $out[$r->entity_type][$r->decision] = (int) $r->n;
        }

        return $out;
    }
}
