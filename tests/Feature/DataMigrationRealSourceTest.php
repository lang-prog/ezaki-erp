<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRecord;
use App\Models\DataMigrationRun;
use App\Models\Product;
use App\Models\User;
use App\Services\AccountingFoundationService;
use App\Services\Migration\MigrationWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Drives the complete wizard over HTTP with the real legacy dump on a disposable in-memory database.
 * Skipped when the (git-ignored) dump is absent. Only counts are written out; no names or amounts of real parties.
 */
final class DataMigrationRealSourceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $report = [];

    private function dump(): string
    {
        return base_path('alawaadc_alawaadv2.sql.gz');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('data-migrations');
        parent::tearDown();
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function api(User $user, string $method, string $url, array $data = []): array
    {
        $response = $this->withSession(['platform_stepup_at' => time()])->actingAs($user)->json($method, $url, $data);
        $body = $response->json();
        if (is_array($body)) {
            unset($body['trace']);
            if (isset($body['message']) && isset($body['file'])) {
                $body['message'] .= ' @ '.basename((string) $body['file']).':'.($body['line'] ?? '?');
            }
            unset($body['file'], $body['line']);
        }

        return [$response->getStatusCode(), is_array($body) ? $body : []];
    }

    private function snapshotCounts(Company $c): array
    {
        return [
            'parties' => CustomerSupplier::query()->where('company_id', $c->id)->count(), 'products' => Product::query()->where('company_id', $c->id)->count(),
            'journals' => DB::table('journal_entries')->where('company_id', $c->id)->count(), 'purchase_bills' => DB::table('purchase_bills')->where('company_id', $c->id)->count(),
            'sales_bills' => DB::table('sales_bills')->where('company_id', $c->id)->count(), 'receipts' => DB::table('receipts')->where('company_id', $c->id)->count(),
            'vouchers' => DB::table('payment_vouchers')->where('company_id', $c->id)->count(), 'stock_movements' => DB::table('stock_movements')->where('company_id', $c->id)->count(),
            'links' => DB::table('data_migration_links')->where('company_id', $c->id)->count(),
        ];
    }

    public function test_real_dump_isolated_dry_run(): void
    {
        if (! is_file($this->dump())) {
            $this->markTestSkipped('The legacy dump is not present in this checkout.');
        }
        Storage::fake('local');
        $owner = User::query()->create(['name' => 'Dry run owner', 'email' => 'dryrun@example.test', 'password' => 'Correct-horse-1', 'account_type' => 'super_admin', 'platform_role' => 'owner', 'status' => 'active']);
        $spare = Company::query()->create(['name' => 'Spare company', 'status' => 'active']);
        $target = Company::query()->create(['name' => 'Dry-run target', 'status' => 'active']);
        $other = Company::query()->create(['name' => 'Isolation company', 'status' => 'active']);
        foreach ([$spare, $target, $other] as $c) {
            app(AccountingFoundationService::class)->seedCompany($c, $owner);
        }
        Branch::query()->create(['company_id' => $other->id, 'code' => 'B1', 'name' => 'Other branch', 'status' => 'active']);
        $before = ['other' => $this->snapshotCounts($other), 'spare' => $this->snapshotCounts($spare)];
        $this->assertGreaterThan($spare->id, $target->id, 'target is deliberately not the first company');

        // 1. validate, hash and upload
        $file = new UploadedFile($this->dump(), 'alawaadc_alawaadv2.sql.gz', 'application/gzip', null, true);
        $response = $this->withSession(['platform_stepup_at' => time()])->actingAs($owner)->call('POST', '/super-admin/data-migrations', [
            'company_id' => $target->id, 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30',
            'customer_start_balance_sign' => 'debit_negative', 'supplier_start_balance_sign' => 'credit_negative',
        ], [], ['source' => $file], ['HTTP_ACCEPT' => 'application/json']);
        $response->assertOk();
        $runId = (int) $response->json('run.id');
        $run = DataMigrationRun::query()->findOrFail($runId);
        $this->assertSame($target->id, (int) $run->company_id, 'the selected company is honoured');
        $this->assertSame(hash_file('sha256', $this->dump()), $run->source_hash);
        $this->report['source'] = ['compressed_bytes' => filesize($this->dump()), 'sha256_recorded' => true];

        // 2-3. parse, profile, integrity
        [$status, $body] = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/parse");
        $this->assertSame(200, $status, json_encode($body));
        $run->refresh();
        $this->assertSame('review', $run->status);
        $this->assertTrue($run->integrity['material_clean']);
        $this->assertSame(0, $run->integrity['error_count']);
        $this->report['integrity'] = ['uncompressed_bytes' => $run->integrity['uncompressed_bytes'], 'statements' => $run->integrity['statements'], 'errors' => $run->integrity['error_count'], 'charsets' => $run->integrity['charsets'], 'tables' => $run->integrity['tables'], 'staged_rows' => $run->integrity['staged_rows'], 'duplicate_keys' => $run->integrity['duplicate_keys']];
        $this->report['mappings_initial'] = $this->api($owner, 'GET', "/super-admin/data-migrations/{$runId}/mappings")[1]['counts'] ?? [];

        // 6-7. approve only deterministic matches, decide the rest explicitly (disposable environment)
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/mappings/approve-safe");
        $decisions = [];
        foreach (DataMigrationMapping::query()->where('run_id', $runId)->where('decision', 'needs_review')->get() as $m) {
            $p = $m->payload;
            $payload = ['decision' => 'excluded', 'reason' => 'Dry run: not deterministic ('.($p['note'] ?? 'review').')'];
            if ($m->entity_type === 'product' && ($p['note'] ?? null) === 'unit_required') {
                $evidence = $p['source_attributes']['price_evidence'] ?? '';
                $payload = ['decision' => 'create_required', 'unit_basis' => $evidence === 'per_kg' ? 'kg_weight' : (in_array($evidence, ['per_ton', 'none'], true) ? 'ton_weight' : 'other')];
            }
            $key = $m->entity_type.':'.($payload['unit_basis'] ?? $payload['decision']).':'.($p['note'] ?? '-');
            $decisions[$key] = ($decisions[$key] ?? 0) + 1;
            [$s, $b] = $this->api($owner, 'PUT', "/super-admin/data-migrations/{$runId}/mappings/{$m->id}", $payload);
            $this->assertSame(200, $s, json_encode($b));
        }
        $this->report['manual_decisions'] = $decisions;

        // 8. master data: preflight then create
        [$s, $b] = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/masters/preflight");
        $this->assertSame(200, $s);
        $this->assertTrue($b['preflight']['ok'], 'masters preflight blockers: '.json_encode(array_slice($b['preflight']['blockers'], 0, 5)));
        [$s, $b] = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/masters/create");
        $this->assertSame(200, $s, json_encode($b));
        $this->report['masters_created'] = $b['created'];
        $this->assertSame($before['other'], $this->snapshotCounts($other), 'master data never touched another company');
        $this->assertSame($before['spare'], $this->snapshotCounts($spare));
        $second = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/masters/create");
        $this->assertSame(409, $second[0], 'masters cannot be created twice');

        // 9. opening preview + acknowledgements
        [$s, $b] = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/openings/preflight");
        $this->assertSame(200, $s, json_encode($b));
        $acked = [];
        for ($i = 0; $i < 8 && ! $b['ready']; $i++) {
            foreach ($b['blockers'] as $blocker) {
                if ($blocker['ack'] && ! isset($acked[$blocker['ack']])) {
                    $acked[$blocker['ack']] = count(array_filter($b['blockers'], fn ($x) => $x['ack'] === $blocker['ack']));
                    $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/policy", ['ack' => $blocker['ack'], 'reason' => 'Dry run acknowledgement of '.$blocker['code']]);
                }
            }
            [$s, $b] = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/openings/preflight");
        }
        $this->report['opening_acknowledgements'] = $acked;
        $this->assertTrue($b['ready'], 'opening blockers: '.json_encode(array_slice($b['blockers'], 0, 6)));
        $preview = $this->api($owner, 'GET', "/super-admin/data-migrations/{$runId}/openings/preview")[1]['preview'];
        $this->report['opening_preview'] = ['totals' => $preview['totals'], 'customers' => count($preview['parties']['customers']), 'suppliers' => count($preview['parties']['suppliers']), 'stock_rows' => count($preview['stock']), 'stock_status' => array_count_values(array_column($preview['stock'], 'status')), 'cash' => array_count_values(array_column($preview['cash'], 'status')), 'banks' => array_count_values(array_column($preview['banks'], 'status')), 'party_status' => array_count_values(array_merge(array_column($preview['parties']['customers'], 'status'), array_column($preview['parties']['suppliers'], 'status'))), 'issues' => $preview['issues']];
        [$s, $b] = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/openings/post");
        $this->assertSame(200, $s, json_encode($b));
        $this->report['openings_posted'] = $b['result'];

        // 11. stage post-cutoff documents
        [$s, $b] = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/documents/stage");
        $this->assertSame(200, $s, json_encode($b));
        $this->report['staged'] = $b['counts'];
        $this->report['staged_by_type'] = DataMigrationRecord::query()->where('run_id', $runId)->selectRaw('entity_type, status, coalesce(review_code, \'-\') as code, count(*) as n')->groupBy('entity_type', 'status', 'review_code')->get()->map(fn ($r) => "{$r->entity_type}|{$r->status}|{$r->code}={$r->n}")->all();

        // 12. execution preflight: resolve payment-source mapping explicitly
        for ($i = 0; $i < 4; $i++) {
            [$s, $b] = $this->api($owner, 'GET', "/super-admin/data-migrations/{$runId}/execution/preflight");
            if ($b['ok']) {
                break;
            }
            foreach ($b['blockers'] as $blocker) {
                if ($blocker['mapping_id']) {
                    $this->api($owner, 'PUT', "/super-admin/data-migrations/{$runId}/mappings/{$blocker['mapping_id']}", ['decision' => 'excluded', 'reason' => 'Dry run: '.$blocker['code']]);
                }
            }
            $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/documents/stage");
        }
        $this->assertTrue($b['ok'], 'execution blockers: '.json_encode($b['blockers'] ?? []));
        $effectsBefore = $this->snapshotCounts($target);

        // 14. interrupted run: lock held -> busy; stale lock -> resumable; budget exhausted -> resumable
        $run->refresh();
        $run->forceFill(['lock_token' => 'x', 'lock_action' => 'execute', 'lock_expires_at' => now()->addMinutes(10)])->save();
        $this->assertSame(409, $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/execute")[0], 'duplicate click while a worker holds the lock');
        $run->forceFill(['lock_token' => null, 'lock_action' => null, 'lock_expires_at' => null])->save();
        app(MigrationWorkflow::class)->execute($runId, $owner, 0);
        $this->assertSame('executing', $run->fresh()->status, 'a paused execution stays resumable');
        $run->forceFill(['lock_token' => 'dead', 'lock_action' => 'execute', 'lock_expires_at' => now()->subMinute()])->save();
        [$s, $b] = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/execute");
        $this->assertSame(200, $s, json_encode($b));
        $this->report['execution'] = $b['result'];
        // failed records are retried on every execute (stock may arrive later); iterate until the outcome is stable
        for ($pass = 0; $pass < 6; $pass++) {
            $prev = $this->snapshotCounts($target);
            $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/execute");
            if ($prev === $this->snapshotCounts($target)) {
                break;
            }
        }
        $this->report['execution_passes'] = $pass + 1;
        $reasons = [];
        foreach (DB::table('data_migration_records')->where('run_id', $runId)->where('status', 'failed')->pluck('error_message') as $msg) {
            $key = preg_replace('/[^\x20-\x7E]+/u', '?', (string) $msg);
            $key = mb_substr(preg_replace('/\d+(\.\d+)?/', 'N', $key), 0, 80);
            $reasons[$key] = ($reasons[$key] ?? 0) + 1;
        }
        $this->report['failure_reasons'] = $reasons;
        $this->report['after_execution'] = $this->snapshotCounts($target);

        // 13. idempotency: every repeatable stage again
        $afterFirst = $this->snapshotCounts($target);
        $again = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/execute");
        $this->assertSame($afterFirst, $this->snapshotCounts($target), 'a second execute changes nothing ('.$again[0].')');
        $this->assertSame(409, $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/parse")[0], 're-analysis is refused after financial effects');
        $this->assertSame(409, $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/openings/post")[0]);
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/documents/stage");
        $this->assertSame($afterFirst, $this->snapshotCounts($target), 'restaging never recreates created records');
        $dup = $this->withSession(['platform_stepup_at' => time()])->actingAs($owner)->call('POST', '/super-admin/data-migrations', ['company_id' => $target->id, 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30'], [], ['source' => new UploadedFile($this->dump(), 'alawaadc_alawaadv2.sql.gz', 'application/gzip', null, true)], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertTrue($dup->json('duplicate'), 're-uploading the same source maps to the same run');
        $this->assertSame($runId, (int) $dup->json('run.id'));
        $this->assertSame(1, DataMigrationRun::query()->count());
        $this->report['idempotency'] = ['second_execute_status' => $again[0], 'duplicate_upload_same_run' => true, 'effects_before_execute' => $effectsBefore];

        // 15. reconciliation and reports
        [$s, $b] = $this->api($owner, 'GET', "/super-admin/data-migrations/{$runId}/reconciliation");
        $this->assertSame(200, $s, json_encode($b));
        $rec = $b['reconciliation'];
        $this->report['reconciliation'] = [
            'gap' => $rec['gap'], 'documents' => $rec['documents'], 'review' => $rec['review'], 'opening' => $rec['opening'],
            'parties' => ['compared' => $rec['parties']['compared'], 'matching' => $rec['parties']['matching'], 'differences' => $rec['parties']['differences'], 'abs_difference' => $rec['parties']['abs_difference']],
            'stock' => ['compared' => $rec['stock']['compared'], 'differences' => $rec['stock']['differences']], 'cash' => ['compared' => $rec['cash']['compared'], 'differences' => $rec['cash']['differences'], 'unassigned_treasury_rows' => $rec['cash']['unassigned_treasury']['rows']],
        ];
        $this->assertTrue($rec['opening']['balanced'], 'opening journals are balanced');
        foreach (['mappings', 'integrity', 'records', 'errors', 'reconciliation'] as $type) {
            $csv = $this->withSession(['platform_stepup_at' => time()])->actingAs($owner)->get("/super-admin/data-migrations/{$runId}/reports/{$type}");
            $csv->assertOk();
            $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        }

        // 16. isolation
        $this->assertSame($before['other'], $this->snapshotCounts($other));
        $this->assertSame($before['spare'], $this->snapshotCounts($spare));
        $foreign = CustomerSupplier::query()->create(['company_id' => $other->id, 'account_id' => DB::table('accounts')->where('company_id', $other->id)->value('id'), 'parent_account_id' => DB::table('accounts')->where('company_id', $other->id)->value('id'), 'name' => 'Foreign', 'is_customer' => true, 'status' => 'active']);
        $m = DataMigrationMapping::query()->where('run_id', $runId)->where('entity_type', 'customer')->where('decision', 'matched')->whereNull('locked_at')->first();
        if ($m) {
            $this->assertSame(422, $this->api($owner, 'PUT', "/super-admin/data-migrations/{$runId}/mappings/{$m->id}", ['decision' => 'matched', 'target_id' => $foreign->id])[0]);
        }
        $locked = DataMigrationMapping::query()->where('run_id', $runId)->whereNotNull('locked_at')->first();
        $this->assertNotNull($locked);
        $this->assertSame(409, $this->api($owner, 'PUT', "/super-admin/data-migrations/{$runId}/mappings/{$locked->id}", ['decision' => 'excluded', 'reason' => 'late change'])[0], 'used mappings are frozen');
        $this->report['isolation'] = ['other_company_unchanged' => true, 'foreign_target_rejected' => $m !== null, 'locked_mapping_rejected' => true];

        $this->report['final_status'] = $run->fresh()->status;
        @mkdir(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ezaki-mig');
        file_put_contents(sys_get_temp_dir().DIRECTORY_SEPARATOR.'ezaki-mig'.DIRECTORY_SEPARATOR.'dryrun.json', json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
