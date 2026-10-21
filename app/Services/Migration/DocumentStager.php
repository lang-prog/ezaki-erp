<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Company;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use App\Services\CompanyAccountingPolicyService;
use App\Services\Legacy\LegacyDataset;
use App\Services\Legacy\LegacyLedger;
use App\Services\Legacy\MappingIndex;

/**
 * Structural classification of legacy rows into ERP documents. Free text (e.g. the word "دفعة") is never used to decide a type;
 * ambiguous rows stay in needs_review with a machine-readable reason.
 */
final class DocumentStager
{
    public const PRIORITY = ['purchase_document' => 1, 'sales_document' => 2, 'payment_document' => 3, 'receipt_document' => 4, 'expense_document' => 5, 'bank_operation_document' => 6];

    public function __construct(private readonly CompanyAccountingPolicyService $policies) {}

    /** @return array<string, int> counts by status */
    public function stage(DataMigrationRun $run, LegacyDataset $d): array
    {
        $map = new MappingIndex($run);
        $ledger = new LegacyLedger($d, $run, fn (string $k) => $map->basis($k));
        $company = Company::query()->findOrFail($run->company_id);
        $vat = $this->policies->vatRate($company) > 0;
        $from = $ledger->from();
        $to = $ledger->to();
        $seen = [];
        foreach ($this->candidates($d, $ledger, $from, $to) as $c) {
            $c = $this->evaluate($c, $map, $ledger, $vat);
            $this->upsert($run, $c);
            $seen[] = $c['entity_type'].'|'.$c['source_table'].'|'.$c['source_id'];
        }
        $counts = [];
        foreach (DataMigrationRecord::query()->where('run_id', $run->id)->selectRaw('status, count(*) as n')->groupBy('status')->get() as $r) {
            $counts[$r->status] = (int) $r->n;
        }

        return $counts;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function candidates(LegacyDataset $d, LegacyLedger $ledger, $from, $to): \Generator
    {
        foreach ($d->rows('buy') as $b) {
            $date = LegacyDataset::date($b['date']);
            if ($date !== null && ! $date->betweenIncluded($from, $to)) {
                continue;
            }
            yield $this->purchaseOrPayment($d, $ledger, $b, $date);
        }
        foreach ($d->rows('sales') as $s) {
            $date = LegacyDataset::date($s['insert_date']);
            if ($date !== null && ! $date->betweenIncluded($from, $to)) {
                continue;
            }
            yield $this->salesOrReceipt($d, $ledger, $s, $date);
        }
        foreach ($d->rows('all_expenses') as $e) {
            $date = LegacyDataset::date($e['insert_date']);
            if ($date !== null && ! $date->betweenIncluded($from, $to)) {
                continue;
            }
            yield $this->doc('expense_document', 'all_expenses', $e['id_all_expenses'], $date, 'LEGACY-EX-'.$e['id_all_expenses'], 'needs_review', 'expense_account_required', ['amount' => LegacyDataset::num($e['amount']), 'legacy_expense_id' => $e['id_expenses'] ?? null]);
        }
        foreach ($d->rows('bank_operations') as $o) {
            $date = LegacyDataset::date($o['inserted_date']);
            if ($date !== null && ! $date->betweenIncluded($from, $to)) {
                continue;
            }
            yield $this->doc('bank_operation_document', 'bank_operations', $o['id_bank_operations'], $date, 'LEGACY-BK-'.$o['id_bank_operations'], 'needs_review', 'bank_operation_counterparty_required', ['cash_in' => LegacyDataset::num($o['cash_in']), 'cash_out' => LegacyDataset::num($o['cash_out']), 'bank_key' => LegacyDataset::key($o['id_bank_accounts'])]);
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function doc(string $type, string $table, mixed $id, $date, string $number, string $status, ?string $code, array $payload): array
    {
        if ($date === null && $code === null) {
            $status = 'needs_review';
            $code = 'invalid_date';
        }

        return ['entity_type' => $type, 'source_table' => $table, 'source_id' => (string) $id, 'date' => $date?->toDateString(), 'number' => $number, 'status' => $status, 'review_code' => $code, 'payload' => $payload];
    }

    /** @param array<string, mixed> $b @return array<string, mixed> */
    private function purchaseOrPayment(LegacyDataset $d, LegacyLedger $ledger, array $b, $date): array
    {
        $id = LegacyDataset::key($b['id_buy']);
        $supplier = LegacyDataset::key($b['id_import']);
        $f = $ledger->purchaseFacts($b);
        $eda3 = LegacyDataset::num($b['eda3']);
        if ($f['lines'] !== []) {
            $code = null;
            foreach ($f['lines'] as $l) {
                if ($l['kg'] < 0) {
                    $code = 'negative_quantity';
                } elseif ($l['kg'] == 0.0 && $code === null) {
                    $code = 'zero_quantity';
                } elseif ($l['price'] <= 0 && $code === null) {
                    $code = 'zero_price_line';
                }
            }
            $code ??= $supplier === '0' ? 'no_party' : ($eda3 > 0 ? 'invoice_with_payment' : null);
            $warehouse = LegacyDataset::key($b['id_stores']) !== '0' ? LegacyDataset::key($b['id_stores']) : ($f['lines'][0]['warehouse_key'] ?? '0');
            $whs = array_unique(array_column($f['lines'], 'warehouse_key'));
            if (count($whs) > 1) {
                $code ??= 'multiple_warehouses';
            }

            return $this->doc('purchase_document', 'buy', $id, $date, 'LEGACY-PB-'.$id, $code ? 'needs_review' : 'ready', $code, ['supplier_key' => $supplier, 'warehouse_key' => $warehouse, 'extra_cost' => $f['extra'], 'lines' => $f['lines'], 'note' => $b['bayan'] ?? null, 'paid_inline' => $eda3]);
        }
        if ($eda3 > 0) {
            $box = MappingGenerator::treasuryBox($d, 'buy', $id);
            $hasEda = ! empty($b['id_eda3at']);
            $source = $box !== null && $box !== '0' ? 'treasury:'.$box : 'none';
            $code = null;
            if ($supplier === '0') {
                $code = 'no_party';
            } elseif (! empty($b['id_sales']) && ! $hasEda && $source === 'none') {
                $code = 'party_transfer';
            } elseif (! $hasEda && $box === null) {
                $code = 'payment_without_source';
            }

            return $this->doc('payment_document', 'buy', $id, $date, 'LEGACY-PV-'.$id, $code ? 'needs_review' : 'ready', $code, ['supplier_key' => $supplier, 'amount' => $eda3, 'payment_source' => $source, 'note' => $b['bayan'] ?? null, 'linked_sales_id' => $b['id_sales'] ?? null]);
        }

        return $this->doc('payment_document', 'buy', $id, $date, 'LEGACY-PV-'.$id, 'needs_review', 'unclassified', ['supplier_key' => $supplier, 'amount' => 0.0, 'note' => $b['bayan'] ?? null]);
    }

    /** @param array<string, mixed> $s @return array<string, mixed> */
    private function salesOrReceipt(LegacyDataset $d, LegacyLedger $ledger, array $s, $date): array
    {
        $id = LegacyDataset::key($s['id_sales']);
        $customer = LegacyDataset::key($s['id_customer']);
        $f = $ledger->salesFacts($s);
        $cash = LegacyDataset::num($s['cash']);
        if ($f['lines'] !== []) {
            $code = null;
            foreach ($f['lines'] as $l) {
                if ($l['kg'] < 0) {
                    $code = 'negative_quantity';
                } elseif ($code === null && $l['kg'] == 0.0) {
                    $code = 'zero_quantity';
                } elseif ($code === null && $l['price'] <= 0) {
                    $code = 'zero_price_line';
                }
            }
            if ($code === null && $f['header_total'] <= 0) {
                $code = 'zero_value_invoice';
            }
            if ($code === null && abs($f['header_total'] - $f['computed_total']) > 0.5) {
                $code = 'total_mismatch';
            }
            if ($code === null && $cash > 0) {
                $code = 'invoice_with_cash';
            }
            $whs = array_unique(array_column($f['lines'], 'warehouse_key'));
            if ($code === null && count($whs) > 1) {
                $code = 'multiple_warehouses';
            }
            $warehouse = LegacyDataset::key($s['id_stores']) !== '0' ? LegacyDataset::key($s['id_stores']) : ($f['lines'][0]['warehouse_key'] ?? '0');

            return $this->doc('sales_document', 'sales', $id, $date, 'LEGACY-SB-'.$id, $code ? 'needs_review' : 'ready', $code, ['customer_key' => $customer, 'warehouse_key' => $warehouse, 'transport_cost' => $f['transport'], 'loading_cost' => $f['loading'], 'legacy_total' => $f['header_total'], 'packages' => LegacyDataset::num($s['numper']), 'lines' => $f['lines'], 'note' => $s['bayan'] ?? null]);
        }
        if ($cash > 0 && ! empty($s['id_cash'])) {
            $cashRow = $d->one('cash', 'id_cash', LegacyDataset::key($s['id_cash']));
            $box = MappingGenerator::treasuryBox($d, 'sales', $id);
            $code = null;
            if ($customer === '0') {
                $code = 'no_party';
            } elseif ($cashRow === null || abs(LegacyDataset::num($cashRow['cash']) - $cash) > 0.004) {
                $code = 'receipt_amount_mismatch';
            }

            return $this->doc('receipt_document', 'sales', $id, $date, 'LEGACY-RC-'.$id, $code ? 'needs_review' : 'ready', $code, ['customer_key' => $customer, 'amount' => $cash, 'payment_source' => $box !== null && $box !== '0' ? 'treasury:'.$box : 'none', 'note' => $s['bayan'] ?? null]);
        }

        return $this->doc('receipt_document', 'sales', $id, $date, 'LEGACY-RC-'.$id, 'needs_review', 'unclassified', ['customer_key' => $customer, 'amount' => 0.0, 'note' => $s['bayan'] ?? null]);
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function evaluate(array $c, MappingIndex $map, LegacyLedger $ledger, bool $vat): array
    {
        if ($c['status'] !== 'ready') {
            return $c;
        }
        foreach (self::requirements($c['entity_type'], $c['payload']) as [$entity, $key]) {
            $m = $entity === 'payment_source' ? ($key === 'none' ? $map->get('payment_source', 'none') : $map->get('cashbox', substr($key, 9))) : $map->get($entity, $key);
            if ($m?->decision === 'excluded') {
                return $this->review($c, 'mapping_excluded');
            }
        }
        if (in_array($c['entity_type'], ['purchase_document', 'sales_document'], true)) {
            if ($vat) {
                return $this->review($c, 'vat_policy_conflict');
            }
            foreach ($c['payload']['lines'] as $l) {
                $m = $map->get('product', $l['product_key']);
                $basis = data_get($m?->payload, 'source_attributes.unit_basis');
                if ($basis === null) {
                    return $this->review($c, 'unit_undecided');
                }
                if (! in_array($basis, ['ton_weight', 'kg_weight'], true)) {
                    return $this->review($c, 'unit_not_supported');
                }
                if ($c['entity_type'] === 'sales_document') {
                    $expected = $ledger->lineValue($l['product_key'], $l['kg'], $l['price']);
                    if ($expected === null || abs($expected - (float) $l['legacy_value']) > 1.0) {
                        return $this->review($c, 'line_total_mismatch');
                    }
                }
            }
        }

        return $c;
    }

    /** @return list<array{0: string, 1: string}> mapping entity and source key every executable record depends on */
    public static function requirements(string $type, array $p): array
    {
        $req = [];
        switch ($type) {
            case 'purchase_document':
            case 'sales_document':
                $req[] = $type === 'purchase_document' ? ['supplier', (string) $p['supplier_key']] : ['customer', (string) $p['customer_key']];
                $req[] = ['warehouse', (string) $p['warehouse_key']];
                foreach ($p['lines'] ?? [] as $l) {
                    $req[] = ['product', (string) $l['product_key']];
                }
                break;
            case 'payment_document':
                $req[] = ['supplier', (string) $p['supplier_key']];
                $req[] = ['payment_source', (string) $p['payment_source']];
                break;
            case 'receipt_document':
                $req[] = ['customer', (string) $p['customer_key']];
                $req[] = ['payment_source', (string) $p['payment_source']];
                break;
        }

        return $req;
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function review(array $c, string $code): array
    {
        $c['status'] = 'needs_review';
        $c['review_code'] = $code;

        return $c;
    }

    /** @param array<string, mixed> $c */
    private function upsert(DataMigrationRun $run, array $c): void
    {
        $existing = DataMigrationRecord::query()->where('run_id', $run->id)->where('entity_type', $c['entity_type'])->where('source_table', $c['source_table'])->where('source_id', $c['source_id'])->first();
        $payload = $c['payload'] + ['source_id' => $c['source_id'], 'date' => $c['date']];
        if ($existing && $existing->status === DataMigrationRecord::STATUS_CREATED) {
            return;
        }
        $status = $c['status'];
        $code = $c['review_code'];
        if ($existing && $existing->status === DataMigrationRecord::STATUS_EXCLUDED) {
            $status = DataMigrationRecord::STATUS_EXCLUDED;
            $code = $existing->review_code;
        } elseif ($existing && $existing->status === DataMigrationRecord::STATUS_FAILED && $status === DataMigrationRecord::STATUS_READY) {
            $status = DataMigrationRecord::STATUS_FAILED;
        }
        $attrs = ['source_date' => $c['date'], 'source_number' => $c['number'], 'normalized_payload' => $payload, 'status' => $status, 'review_code' => $code, 'target_type' => null];
        if ($existing) {
            $existing->forceFill($attrs)->save();

            return;
        }
        DataMigrationRecord::query()->create($attrs + [
            'run_id' => $run->id, 'entity_type' => $c['entity_type'], 'source_table' => $c['source_table'], 'source_id' => $c['source_id'],
            'fingerprint' => hash('sha256', implode('|', [$run->company_id, $run->source_system, $c['source_table'], $c['source_id'], $c['entity_type']])),
        ]);
    }
}
