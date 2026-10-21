<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\Bank;
use App\Models\Cashbox;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationLink;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use App\Models\JournalEntry;
use App\Models\PaymentVoucher;
use App\Models\PurchaseBill;
use App\Models\Receipt;
use App\Models\SalesBill;
use App\Services\Legacy\LegacyDataset;
use App\Services\Legacy\LegacyLedger;
use App\Services\Legacy\MappingIndex;
use Illuminate\Support\Facades\DB;

/** Compares what the legacy data says at the end of the document range with what the ERP now holds. */
final class ReconciliationService
{
    private const TOLERANCE = 0.01;

    /** @return array<string, mixed> */
    public function build(DataMigrationRun $run): array
    {
        $map = new MappingIndex($run);
        $d = LegacyDataset::load($run);
        $ledger = new LegacyLedger($d, $run, fn (string $k) => $map->basis($k));
        $to = $ledger->to();

        return [
            'generated_at' => now()->toIso8601String(),
            'source' => ['staged_rows' => $run->integrity['staged_rows'] ?? [], 'duplicate_keys' => $run->integrity['duplicate_keys'] ?? [], 'source_hash' => $run->source_hash],
            'gap' => $this->gap($d, $ledger),
            'documents' => $this->documents($run),
            'review' => $this->review($run),
            'opening' => $this->opening($run),
            'parties' => $this->parties($run, $map, $ledger, $to),
            'stock' => $this->stock($run, $map, $ledger, $to),
            'cash' => $this->cash($run, $map, $ledger, $to),
        ];
    }

    /** @return array<string, int> */
    private function gap(LegacyDataset $d, LegacyLedger $ledger): array
    {
        $cut = $ledger->cutoff();
        $from = $ledger->from();
        $out = ['buy' => 0, 'sales' => 0, 'treasury' => 0, 'all_expenses' => 0, 'bank_operations' => 0];
        foreach (['buy' => 'date', 'sales' => 'insert_date', 'treasury' => 'inserted_date', 'all_expenses' => 'insert_date', 'bank_operations' => 'inserted_date'] as $t => $col) {
            foreach ($d->rows($t) as $r) {
                $x = LegacyDataset::date($r[$col]);
                if ($x !== null && $x->greaterThan($cut) && $x->lessThan($from)) {
                    $out[$t]++;
                }
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private function documents(DataMigrationRun $run): array
    {
        $out = [];
        foreach (DataMigrationRecord::query()->where('run_id', $run->id)->get(['entity_type', 'status', 'normalized_payload', 'target_type', 'target_id']) as $r) {
            $row = &$out[$r->entity_type];
            $row ??= ['total' => 0, 'by_status' => [], 'legacy_amount_created' => 0.0, 'erp_amount_created' => 0.0];
            $row['total']++;
            $row['by_status'][$r->status] = ($row['by_status'][$r->status] ?? 0) + 1;
            if ($r->status === 'created') {
                $p = $r->normalized_payload ?? [];
                $legacy = match ($r->entity_type) {
                    'purchase_document' => array_sum(array_map(fn ($l) => (float) $l['legacy_value'], $p['lines'] ?? [])) + (float) ($p['extra_cost'] ?? 0),
                    'sales_document' => (float) ($p['legacy_total'] ?? 0),
                    default => (float) ($p['amount'] ?? 0),
                };
                $erp = match ($r->target_type) {
                    PurchaseBill::class => (float) PurchaseBill::query()->whereKey($r->target_id)->value('total'),
                    SalesBill::class => (float) SalesBill::query()->whereKey($r->target_id)->value('total'),
                    Receipt::class => (float) Receipt::query()->whereKey($r->target_id)->value('amount'),
                    PaymentVoucher::class => (float) PaymentVoucher::query()->whereKey($r->target_id)->value('amount'),
                    default => 0.0,
                };
                $row['legacy_amount_created'] = round($row['legacy_amount_created'] + $legacy, 2);
                $row['erp_amount_created'] = round($row['erp_amount_created'] + $erp, 2);
            }
            unset($row);
        }
        foreach ($out as &$row) {
            $row['amount_difference'] = round($row['legacy_amount_created'] - $row['erp_amount_created'], 2);
        }

        return $out;
    }

    /** @return list<array{entity: string, code: string|null, status: string, count: int}> */
    private function review(DataMigrationRun $run): array
    {
        return DataMigrationRecord::query()->where('run_id', $run->id)->whereIn('status', ['needs_review', 'failed', 'excluded', 'ready'])
            ->selectRaw('entity_type as entity, review_code as code, status, count(*) as count')->groupBy('entity_type', 'review_code', 'status')->orderBy('entity_type')->get()
            ->map(fn ($r) => ['entity' => $r->entity, 'code' => $r->code, 'status' => $r->status, 'count' => (int) $r->count])->all();
    }

    /** @return array<string, mixed> */
    private function opening(DataMigrationRun $run): array
    {
        $links = DataMigrationLink::query()->where('run_id', $run->id)->whereIn('document_type', ['opening_balance', 'opening_stock', 'opening_inventory_journal', 'opening_cash_journal'])->get();
        $entries = [];
        $debit = 0.0;
        $credit = 0.0;
        foreach ($links as $l) {
            if ($l->target_type === JournalEntry::class) {
                $entries[] = $l->target_id;
            }
        }
        $partyEntries = CustomerSupplier::query()->whereIn('id', $links->where('document_type', 'opening_balance')->pluck('target_id'))->pluck('opening_balance_journal_entry_id')->filter()->all();
        $all = array_merge($entries, $partyEntries);
        if ($all !== []) {
            $sums = DB::table('journal_lines')->whereIn('journal_entry_id', $all)->selectRaw('coalesce(sum(debit),0) as d, coalesce(sum(credit),0) as c')->first();
            $debit = round((float) $sums->d, 2);
            $credit = round((float) $sums->c, 2);
        }

        return ['parties' => $links->where('document_type', 'opening_balance')->count(), 'stock_items' => $links->where('document_type', 'opening_stock')->count(), 'journals' => count($all), 'journal_debit' => $debit, 'journal_credit' => $credit, 'balanced' => abs($debit - $credit) < self::TOLERANCE];
    }

    private function accountBalance(int $companyId, ?int $accountId): float
    {
        if (! $accountId) {
            return 0.0;
        }

        return round((float) DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.company_id', $companyId)->where('l.account_id', $accountId)->whereIn('e.status', ['posted', 'reversed'])->sum(DB::raw('l.debit - l.credit')), 2);
    }

    /** @return array<string, mixed> */
    private function parties(DataMigrationRun $run, MappingIndex $map, LegacyLedger $ledger, $to): array
    {
        $raw = $ledger->opening($to);
        $signC = $run->policy('customer_start_balance_sign') === 'debit_negative' ? -1 : 1;
        $signS = $run->policy('supplier_start_balance_sign') === 'credit_negative' ? -1 : 1;
        $rows = [];
        foreach (['customer' => $raw['customers'], 'supplier' => $raw['suppliers']] as $entity => $set) {
            foreach ($set as $key => $v) {
                $target = $map->target($entity, (string) $key);
                if (! $target) {
                    continue;
                }
                $legacy = $entity === 'customer' ? $signC * $v['start'] + $v['invoices'] - $v['receipts'] : $signS * $v['start'] + $v['invoices'] - $v['payments'];
                $account = CustomerSupplier::query()->where('company_id', $run->company_id)->whereKey($target)->value('account_id');
                $erpRaw = $this->accountBalance((int) $run->company_id, $account ? (int) $account : null);
                $erp = $entity === 'customer' ? $erpRaw : -$erpRaw;
                $diff = round($legacy - $erp, 2);
                $rows[] = ['entity' => $entity, 'source_key' => (string) $key, 'mapping_id' => $map->get($entity, (string) $key)?->id, 'legacy' => round($legacy, 2), 'erp' => $erp, 'difference' => $diff];
            }
        }
        $diffs = array_values(array_filter($rows, fn ($r) => abs($r['difference']) > self::TOLERANCE));

        return ['compared' => count($rows), 'matching' => count($rows) - count($diffs), 'differences' => count($diffs), 'abs_difference' => round(array_sum(array_map(fn ($r) => abs($r['difference']), $diffs)), 2), 'items' => array_slice($diffs, 0, 300)];
    }

    /** @return array<string, mixed> */
    private function stock(DataMigrationRun $run, MappingIndex $map, LegacyLedger $ledger, $to): array
    {
        $legacy = [];
        foreach ($ledger->stock($to) as $key => $s) {
            $target = $map->target('product', (string) $key);
            if ($target) {
                $legacy[$target] = ($legacy[$target] ?? 0.0) + $s['tons'];
            }
        }
        $rows = [];
        foreach ($legacy as $product => $tons) {
            $erp = round((float) DB::table('stock_balances')->where('company_id', $run->company_id)->where('product_id', $product)->sum('quantity'), 3);
            $diff = round($tons - $erp, 3);
            if (abs($diff) > 0.0005) {
                $rows[] = ['product_id' => $product, 'legacy_tons' => round($tons, 3), 'erp_tons' => $erp, 'difference' => $diff];
            }
        }

        return ['compared' => count($legacy), 'differences' => count($rows), 'items' => array_slice($rows, 0, 300)];
    }

    /** @return array<string, mixed> */
    private function cash(DataMigrationRun $run, MappingIndex $map, LegacyLedger $ledger, $to): array
    {
        $c = $ledger->cashAndBanks($to);
        $rows = [];
        foreach (['cashbox' => $c['cashboxes'], 'bank' => $c['banks']] as $entity => $set) {
            foreach ($set as $key => $legacy) {
                $target = $map->target($entity, (string) $key);
                if (! $target) {
                    continue;
                }
                $account = $entity === 'cashbox' ? Cashbox::query()->whereKey($target)->value('account_id') : Bank::query()->whereKey($target)->value('account_id');
                $erp = $this->accountBalance((int) $run->company_id, $account ? (int) $account : null);
                $rows[] = ['entity' => $entity, 'source_key' => (string) $key, 'legacy' => $legacy, 'erp' => $erp, 'difference' => round($legacy - $erp, 2)];
            }
        }
        $diffs = array_values(array_filter($rows, fn ($r) => abs($r['difference']) > self::TOLERANCE));

        return ['compared' => count($rows), 'differences' => count($diffs), 'unassigned_treasury' => $c['unassigned_treasury'], 'items' => array_slice($diffs, 0, 100)];
    }
}
