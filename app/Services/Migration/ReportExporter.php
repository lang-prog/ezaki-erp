<?php

declare(strict_types=1);

namespace App\Services\Migration;

use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;

/** Builds the CSV exports (mappings, source integrity, records, errors, reconciliation). Cells are neutralised against spreadsheet formulas. */
final class ReportExporter
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    /** @return array{0: list<string>, 1: list<list<mixed>>} header and rows */
    public function build(DataMigrationRun $run, string $type): array
    {
        return match ($type) {
            'mappings' => $this->mappings($run),
            'integrity' => $this->integrity($run),
            'records' => $this->records($run, false),
            'errors' => $this->records($run, true),
            'reconciliation' => $this->reconciliationRows($run),
            default => abort(404),
        };
    }

    public static function cell(mixed $v): string
    {
        $s = is_bool($v) ? ($v ? '1' : '0') : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? ''));

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($s) ? "'".$s : $s;
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function mappings(DataMigrationRun $run): array
    {
        $rows = [];
        foreach (DataMigrationMapping::query()->where('run_id', $run->id)->orderBy('entity_type')->orderBy('id')->cursor() as $m) {
            $p = $m->payload ?? [];
            $rows[] = [$m->entity_type, $m->source_key, $p['source_name'] ?? $p['source_value'] ?? '', $m->decision, $p['note'] ?? '', $m->target_id, $p['source_attributes']['unit_basis'] ?? '', $m->isReviewed() ? 'yes' : 'no', $m->reason, $m->locked_at ? 'locked' : ''];
        }

        return [['entity', 'source_key', 'source_name', 'decision', 'note', 'target_id', 'unit_basis', 'reviewed', 'reason', 'locked'], $rows];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function integrity(DataMigrationRun $run): array
    {
        $i = $run->integrity ?? [];
        $rows = [['summary', 'sha256', $run->source_hash, '', ''], ['summary', 'uncompressed_bytes', $i['uncompressed_bytes'] ?? '', '', ''], ['summary', 'statements', $i['statements'] ?? '', '', ''], ['summary', 'errors', $i['error_count'] ?? 0, '', '']];
        foreach ($i['tables'] ?? [] as $t => $s) {
            $rows[] = ['table', $t, $s['statements'], $s['rows'], $s['rejected']];
            $rows[] = ['staged', $t, $i['staged_rows'][$t] ?? 0, '', ''];
        }
        foreach ($i['duplicate_keys'] ?? [] as $t => $n) {
            $rows[] = ['duplicate_keys', $t, $n, '', ''];
        }
        foreach ($i['schema_issues'] ?? [] as $s) {
            $rows[] = ['schema_issue', $s, '', '', ''];
        }
        foreach ($i['errors'] ?? [] as $e) {
            $rows[] = ['parse_error', $e['table'], $e['line'], $e['offset'], $e['message']];
        }

        return [['section', 'name', 'a', 'b', 'c'], $rows];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function records(DataMigrationRun $run, bool $errorsOnly): array
    {
        $q = DataMigrationRecord::query()->where('run_id', $run->id)->orderBy('source_date')->orderBy('id');
        if ($errorsOnly) {
            $q->whereIn('status', ['failed', 'needs_review', 'ready']);
        }
        $rows = [];
        foreach ($q->cursor() as $r) {
            $rows[] = [$r->entity_type, $r->source_number, $r->source_date?->toDateString(), $r->status, $r->review_code, $r->error_message, $r->exclusion_reason, $r->target_type ? class_basename($r->target_type).'#'.$r->target_id : ''];
        }

        return [['document', 'source_number', 'date', 'status', 'review_code', 'error', 'exclusion_reason', 'target'], $rows];
    }

    /** @return array{0: list<string>, 1: list<list<mixed>>} */
    private function reconciliationRows(DataMigrationRun $run): array
    {
        $r = $this->reconciliation->build($run);
        $rows = [];
        foreach ($r['documents'] as $type => $d) {
            $rows[] = ['documents', $type, 'total', $d['total']];
            foreach ($d['by_status'] as $s => $n) {
                $rows[] = ['documents', $type, $s, $n];
            }
            $rows[] = ['documents', $type, 'legacy_amount_created', $d['legacy_amount_created']];
            $rows[] = ['documents', $type, 'erp_amount_created', $d['erp_amount_created']];
        }
        foreach (['parties', 'stock', 'cash'] as $s) {
            $rows[] = [$s, 'compared', '', $r[$s]['compared']];
            $rows[] = [$s, 'differences', '', $r[$s]['differences']];
            foreach ($r[$s]['items'] as $item) {
                $rows[] = [$s, json_encode(array_diff_key($item, ['legacy' => 1, 'erp' => 1, 'difference' => 1]), JSON_UNESCAPED_UNICODE), 'legacy/erp/diff', ($item['legacy'] ?? $item['legacy_tons'] ?? '').' / '.($item['erp'] ?? $item['erp_tons'] ?? '').' / '.$item['difference']];
            }
        }
        $rows[] = ['opening', 'journal_debit', '', $r['opening']['journal_debit']];
        $rows[] = ['opening', 'journal_credit', '', $r['opening']['journal_credit']];
        $rows[] = ['opening', 'balanced', '', $r['opening']['balanced'] ? 'yes' : 'no'];
        foreach ($r['gap'] as $t => $n) {
            $rows[] = ['gap', $t, '', $n];
        }

        return [['section', 'item', 'metric', 'value'], $rows];
    }
}
