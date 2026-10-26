<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\DataMigrationEvent;
use App\Models\DataMigrationMapping;
use App\Models\DataMigrationRun;
use App\Models\User;
use App\Services\AccountingFoundationService;
use App\Services\AuditRecorder;
use App\Services\Legacy\LegacySchema;
use App\Services\Migration\RunStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Synthetic (anonymous) dumps only: exercises the wizard rules without any real source data. */
final class DataMigrationRemediationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('data-migrations');
        parent::tearDown();
    }

    private function admin(string $role = 'owner'): User
    {
        $this->seq++;

        return User::query()->create(['name' => "Admin {$this->seq}", 'email' => "dm-admin-{$this->seq}@example.test", 'password' => 'Correct-horse-1', 'account_type' => 'super_admin', 'platform_role' => $role, 'status' => 'active']);
    }

    private function company(User $owner): Company
    {
        $c = Company::query()->create(['name' => 'Company '.(++$this->seq), 'status' => 'active']);
        app(AccountingFoundationService::class)->seedCompany($c, $owner);

        return $c;
    }

    /** @param array<string, list<array<string, mixed>>> $rows */
    private function dump(array $rows, bool $gz = false): UploadedFile
    {
        $sql = "-- synthetic\n";
        foreach (LegacySchema::REQUIRED as $table => $columns) {
            $sql .= "CREATE TABLE `{$table}` (".implode(', ', array_map(fn ($c) => "`{$c}` text", $columns)).");\n";
            foreach ($rows[$table] ?? [] as $row) {
                $values = array_map(fn ($c) => isset($row[$c]) ? "'".str_replace("'", "''", (string) $row[$c])."'" : 'NULL', $columns);
                $sql .= "INSERT INTO `{$table}` (`".implode('`, `', $columns).'`) VALUES ('.implode(', ', $values).");\n";
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'dms').($gz ? '.sql.gz' : '.sql');
        file_put_contents($path, $gz ? gzencode($sql) : $sql);

        return new UploadedFile($path, basename($path), null, null, true);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function baseRows(string $typeName = 'Rebar', int $steel = 1): array
    {
        return [
            'customer' => [['id_customer' => 1, 'name_customer' => 'Customer One', 'address' => 'x', 'phon' => '1', 'start_balance' => '0']],
            'import' => [['id_import' => 1, 'name' => 'Supplier One']],
            'categories' => [['id_categories' => 1, 'categories' => '12', 'steel_or_other' => 1]],
            'type_mm' => [['id_type' => 1, 'name' => $typeName, 'steel_or_other' => $steel]],
            'stores' => [['id_stores' => 2, 'name_stores' => 'Main']],
            'buy' => [['id_buy' => 1, 'date' => '2026-10-02', 'id_import' => 1, 'id_type' => 1, 'bayan' => 'b', 'id_stores' => 2, 'kg' => 1000, 'no' => 1, 'price' => 100, 'exp' => 0, 'eda3' => 0, 'id_eda3at' => 0, 'id_sales' => 0]],
            'details_buy' => [['id_details_buy' => 1, 'id_buy' => 1, 'id_categories' => 1, 'id_type' => 1, 'id_stores' => 2, 'kg' => 1000, 'no' => 1, 'price' => 100]],
        ];
    }

    private function upload(User $user, Company $company, UploadedFile $file, array $extra = []): TestResponse
    {
        return $this->withSession(['platform_stepup_at' => time()])->actingAs($user)->call('POST', '/super-admin/data-migrations', $extra + [
            'company_id' => $company->id, 'migration_scope' => 'full', 'documents_from' => '2026-10-01', 'documents_to' => '2026-10-18', 'opening_balance_as_of' => '2026-09-30',
            'customer_start_balance_sign' => 'debit_negative', 'supplier_start_balance_sign' => 'credit_negative',
        ], [], ['source' => $file], ['HTTP_ACCEPT' => 'application/json']);
    }

    private function api(User $user, string $method, string $url, array $data = []): TestResponse
    {
        return $this->withSession(['platform_stepup_at' => time()])->actingAs($user)->json($method, $url, $data);
    }

    private function parsedRun(User $owner, Company $company, ?array $rows = null): DataMigrationRun
    {
        $id = (int) $this->upload($owner, $company, $this->dump($rows ?? $this->baseRows()))->assertOk()->json('run.id');
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$id}/parse")->assertOk();

        return DataMigrationRun::query()->findOrFail($id);
    }

    public function test_state_machine_matrix_is_closed_and_terminal_states_have_no_exit(): void
    {
        $sm = new RunStateMachine(app(AuditRecorder::class));
        foreach (RunStateMachine::ALLOWED as $from => $targets) {
            foreach (array_keys(RunStateMachine::ALLOWED) as $to) {
                $this->assertSame(in_array($to, $targets, true), $sm->can($from, $to), "{$from} -> {$to}");
            }
        }
        $this->assertSame([], RunStateMachine::ALLOWED['archived']);
        $this->assertNotContains('masters_created', RunStateMachine::ALLOWED['executing']);
        $statuses = array_values(array_filter((new \ReflectionClass(DataMigrationRun::class))->getConstants(), fn ($v, $k) => str_starts_with((string) $k, 'STATUS_'), ARRAY_FILTER_USE_BOTH));
        $this->assertSame([], array_diff($statuses, array_keys(RunStateMachine::ALLOWED)));
        $this->assertSame([], array_diff(array_keys(RunStateMachine::ALLOWED), $statuses));
    }

    public function test_upload_parse_stages_rows_and_a_duplicate_upload_maps_to_the_same_run(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $file = $this->dump($this->baseRows(), true);
        $first = $this->upload($owner, $company, $file)->assertOk()->json('run.id');
        $again = $this->upload($owner, $company, $this->dump($this->baseRows(), true))->json('run.id');
        $this->assertSame($first, $again);
        $this->assertSame(1, DataMigrationRun::query()->count());
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$first}/parse")->assertOk();
        $run = DataMigrationRun::query()->findOrFail($first);
        $this->assertSame('review', $run->status);
        $this->assertGreaterThan(0, DB::table('data_migration_source_rows')->where('run_id', $first)->count());
        $this->assertSame(1, DB::table('data_migration_source_rows')->where('run_id', $first)->where('source_table', 'customer')->count());
        $this->assertGreaterThan(0, DataMigrationEvent::query()->where('run_id', $first)->count());
    }

    public function test_bad_source_files_are_rejected_without_creating_staged_data(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $junk = tempnam(sys_get_temp_dir(), 'dms').'.sql';
        file_put_contents($junk, "DELETE FROM customer;\n");
        $id = $this->upload($owner, $company, new UploadedFile($junk, 'x.sql', null, null, true));
        if ($id->getStatusCode() === 200) {
            $runId = (int) $id->json('run.id');
            $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/parse")->assertStatus(422);
            $this->assertSame(0, DB::table('data_migration_source_rows')->where('run_id', $runId)->count());
        } else {
            $this->assertContains($id->getStatusCode(), [409, 422]);
        }
        $missing = $this->dump(['customer' => []]);
        $this->assertSame(0, DataMigrationRun::query()->where('status', 'review')->count());
        $this->assertNotNull($missing);
    }

    public function test_authorization_unauthenticated_other_roles_and_company_admins(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $run = $this->parsedRun($owner, $company);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get("/super-admin/data-migrations/{$run->id}/state")->assertRedirect();
        foreach (['support', 'billing', 'auditor'] as $role) {
            $user = $this->admin($role);
            $this->api($user, 'GET', "/super-admin/data-migrations/{$run->id}/state")->assertForbidden();
            $this->api($user, 'POST', "/super-admin/data-migrations/{$run->id}/parse")->assertForbidden();
            $this->api($user, 'POST', "/super-admin/data-migrations/{$run->id}/execute")->assertForbidden();
        }
        $tenant = User::query()->create(['name' => 'Tenant', 'email' => 'tenant-dm@example.test', 'password' => 'Correct-horse-1', 'account_type' => 'company', 'company_id' => $company->id, 'status' => 'active']);
        $this->api($tenant, 'GET', "/super-admin/data-migrations/{$run->id}/state")->assertStatus(403);
    }

    public function test_step_up_is_required_for_mapping_decisions_and_execution(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $run = $this->parsedRun($owner, $company);
        $mapping = DataMigrationMapping::query()->where('run_id', $run->id)->firstOrFail();
        $this->flushSession();
        $response = $this->actingAs($owner)->json('PUT', "/super-admin/data-migrations/{$run->id}/mappings/{$mapping->id}", ['decision' => 'excluded', 'reason' => 'No step up']);
        $this->assertContains($response->getStatusCode(), [401, 403, 423, 428]);
        $this->assertNotSame('excluded', $mapping->fresh()->decision);
        $this->assertContains($this->actingAs($owner)->json('POST', "/super-admin/data-migrations/{$run->id}/execute")->getStatusCode(), [401, 403, 409, 423, 428]);
    }

    public function test_actions_are_refused_in_the_wrong_state_and_nothing_is_created(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $run = $this->parsedRun($owner, $company);
        foreach (['masters/create' => 'illegal_state', 'openings/post' => 'illegal_state', 'execute' => 'illegal_state'] as $path => $code) {
            $res = $this->api($owner, 'POST', "/super-admin/data-migrations/{$run->id}/{$path}");
            $res->assertStatus(409);
            $this->assertSame($code, $res->json('code'), $path);
        }
        $this->assertSame(0, DB::table('data_migration_links')->count());
        $this->assertSame(0, DB::table('customer_suppliers')->where('company_id', $company->id)->count());
    }

    public function test_run_lock_refuses_a_concurrent_action_and_an_expired_lock_can_be_taken_over(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $run = $this->parsedRun($owner, $company);
        $run->forceFill(['lock_token' => 'other', 'lock_action' => 'masters', 'lock_expires_at' => now()->addMinutes(5)])->save();
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$run->id}/masters/preflight")->assertStatus(409)->assertJsonPath('code', 'migration_busy');
        $run->forceFill(['lock_expires_at' => now()->subMinute()])->save();
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$run->id}/masters/preflight")->assertOk();
        $this->assertNull($run->fresh()->lock_token);
    }

    public function test_master_preflight_blocks_unreviewed_mappings_without_partial_creation(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $run = $this->parsedRun($owner, $company, $this->baseRows('سلك رباط', 0));
        $res = $this->api($owner, 'POST', "/super-admin/data-migrations/{$run->id}/masters/preflight")->assertOk();
        $this->assertFalse($res->json('preflight.ok'));
        $this->assertNotEmpty($res->json('preflight.blockers'));
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$run->id}/masters/create")->assertStatus(409);
        $this->assertSame(0, DB::table('customer_suppliers')->where('company_id', $company->id)->count());
    }

    public function test_full_synthetic_path_creates_masters_once_and_reconciles(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $run = $this->parsedRun($owner, $company);
        $base = "/super-admin/data-migrations/{$run->id}";
        $this->api($owner, 'POST', "{$base}/mappings/approve-safe")->assertOk();
        foreach (DataMigrationMapping::query()->where('run_id', $run->id)->where('decision', 'needs_review')->get() as $m) {
            $payload = ['decision' => 'create_required'];
            if ($m->entity_type === 'product') {
                $payload['unit_basis'] = 'ton_weight';
            }
            if (in_array($m->entity_type, ['payment_source'], true)) {
                $payload = ['decision' => 'excluded', 'reason' => 'No cashbox recorded in synthetic data'];
            }
            $this->api($owner, 'PUT', "{$base}/mappings/{$m->id}", $payload)->assertOk();
        }
        $pre = $this->api($owner, 'POST', "{$base}/masters/preflight")->assertOk();
        $this->assertTrue($pre->json('preflight.ok'), json_encode($pre->json('preflight.blockers')));
        $this->api($owner, 'POST', "{$base}/masters/create")->assertOk();
        $count = DB::table('customer_suppliers')->where('company_id', $company->id)->count();
        $this->assertGreaterThanOrEqual(1, $count);
        $this->api($owner, 'POST', "{$base}/masters/create")->assertStatus(409);
        $this->assertSame($count, DB::table('customer_suppliers')->where('company_id', $company->id)->count(), 'a repeated create makes no duplicates');
        $this->assertContains($this->api($owner, 'GET', "{$base}/reconciliation")->getStatusCode(), [200, 409]);
        foreach (['mappings', 'integrity', 'records', 'errors'] as $type) {
            $csv = $this->api($owner, 'GET', "{$base}/reports/{$type}");
            $csv->assertOk();
        }
    }

    public function test_cross_company_target_is_rejected_for_a_mapping(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $other = $this->company($owner);
        $foreign = DB::table('accounts')->where('company_id', $other->id)->value('id');
        $run = $this->parsedRun($owner, $company);
        $mapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'supplier')->firstOrFail();
        $foreignParty = CustomerSupplier::query()->create(['company_id' => $other->id, 'account_id' => $foreign, 'parent_account_id' => $foreign, 'name' => 'Foreign', 'is_customer' => true, 'status' => 'active']);
        $res = $this->api($owner, 'PUT', "/super-admin/data-migrations/{$run->id}/mappings/{$mapping->id}", ['decision' => 'matched', 'target_id' => $foreignParty->id, 'reason' => 'attempt']);
        $this->assertContains($res->getStatusCode(), [404, 422]);
        $this->assertNotSame('matched', $mapping->fresh()->decision);
    }

    public function test_count_based_unit_bases_are_never_converted_or_accepted_as_weight(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $run = $this->parsedRun($owner, $company, $this->baseRows('سلك رباط', 0));
        $mapping = DataMigrationMapping::query()->where('run_id', $run->id)->where('entity_type', 'product')->first();
        $this->assertNotNull($mapping);
        $this->assertSame('needs_review', $mapping->decision, 'a non-steel material needs a human unit decision');
        foreach (['bag', 'piece', 'meter'] as $basis) {
            $res = $this->api($owner, 'PUT', "/super-admin/data-migrations/{$run->id}/mappings/{$mapping->id}", ['decision' => 'create_required', 'unit_basis' => $basis]);
            $res->assertOk();
            $this->assertSame($basis, $mapping->fresh()->payload['source_attributes']['unit_basis'] ?? null, 'the decided unit is stored verbatim, never converted to tons');
        }
        $base = "/super-admin/data-migrations/{$run->id}";
        $this->api($owner, 'POST', "{$base}/mappings/approve-safe")->assertOk();
        foreach (DataMigrationMapping::query()->where('run_id', $run->id)->where('decision', 'needs_review')->get() as $m) {
            $this->api($owner, 'PUT', "{$base}/mappings/{$m->id}", $m->entity_type === 'payment_source' ? ['decision' => 'excluded', 'reason' => 'No cashbox in synthetic data'] : ['decision' => 'create_required'])->assertOk();
        }
        $this->api($owner, 'POST', "{$base}/masters/preflight")->assertOk();
        $this->api($owner, 'POST', "{$base}/masters/create")->assertOk();
        $this->assertSame(0, DB::table('products')->where('company_id', $company->id)->where('material_category', '12')->where('quantity_basis', 'weight')->count(), 'a meter product is never created as a weight product');
        $this->assertSame(1, DB::table('products')->where('company_id', $company->id)->where('material_category', '12')->where('quantity_basis', 'meter')->count());
    }

    public function test_phase_one_bulk_action_creates_all_new_parties_without_per_row_clicks_and_posts_balances(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $rows = $this->baseRows();
        $rows['customer'][] = ['id_customer' => 2, 'name_customer' => 'Customer Two', 'address' => 'y', 'phon' => '2', 'start_balance' => '0'];
        $rows['import'][] = ['id_import' => 2, 'name' => 'Supplier Two'];
        $rows['customer'][0]['start_balance'] = '100';
        $rows['account_import'][] = ['id_account_import' => 1, 'id_import' => 1, 'start_cash' => '100'];

        $runId = (int) $this->upload($owner, $company, $this->dump($rows), ['migration_scope' => 'party_openings_phase1'])->assertOk()->json('run.id');
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/parse")->assertOk();
        $base = "/super-admin/data-migrations/{$runId}";

        $bulk = $this->api($owner, 'POST', "{$base}/phase1/parties/create-all")->assertOk();
        $this->assertSame(2, $bulk->json('result.created.customers'));
        $this->assertSame(2, $bulk->json('result.created.suppliers'));
        $this->assertSame('party_openings_ready', DataMigrationRun::query()->findOrFail($runId)->status);
        $this->assertSame(2, DataMigrationMapping::query()->where('run_id', $runId)->where('entity_type', 'customer')->where('decision', 'matched')->whereNotNull('target_id')->count());
        $this->assertSame(2, DataMigrationMapping::query()->where('run_id', $runId)->where('entity_type', 'supplier')->where('decision', 'matched')->whereNotNull('target_id')->count());
        $this->assertSame(2, DB::table('customer_suppliers')->where('company_id', $company->id)->where('is_customer', true)->count());
        $this->assertSame(2, DB::table('customer_suppliers')->where('company_id', $company->id)->where('is_supplier', true)->count());

        $this->api($owner, 'POST', "{$base}/phase1/post")->assertOk();
        $this->assertSame(2, DB::table('data_migration_links')->where('run_id', $runId)->where('document_type', 'party_opening_balance_phase1')->count());
        $this->assertSame('phase1_completed', DataMigrationRun::query()->findOrFail($runId)->status);
    }

    public function test_phase_one_can_resolve_all_unresolved_items_of_one_code_at_once(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $rows = $this->baseRows();
        $rows['customer'][0]['start_balance'] = '';

        $runId = (int) $this->upload($owner, $company, $this->dump($rows), ['migration_scope' => 'party_openings_phase1'])->assertOk()->json('run.id');
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/parse")->assertOk();
        $preview = $this->api($owner, 'GET', "/super-admin/data-migrations/{$runId}/phase1/preview")->assertOk()->json('phase1');
        $this->assertSame(1, count(array_filter($preview['unresolved'], fn (array $issue): bool => $issue['code'] === 'invalid_customer_start_balance')));

        $response = $this->api($owner, 'POST', "/super-admin/data-migrations/{$runId}/phase1/issues/bulk", [
            'code' => 'invalid_customer_start_balance', 'decision' => 'exclude_component', 'reason' => 'القيمة غير صالحة واعتمدت صفرًا',
        ])->assertOk();
        $this->assertSame(1, $response->json('bulk.updated'));
        $this->assertCount(0, array_filter($response->json('phase1.unresolved'), fn (array $issue): bool => $issue['code'] === 'invalid_customer_start_balance'));
    }

    public function test_retention_and_archive_require_a_reason_and_state(): void
    {
        $owner = $this->admin();
        $company = $this->company($owner);
        $run = $this->parsedRun($owner, $company);
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$run->id}/archive", ['reason' => 'Premature'])->assertStatus(409);
        $this->api($owner, 'POST', "/super-admin/data-migrations/{$run->id}/sign-off", ['reason' => 'Premature'])->assertStatus(409);
        $this->assertNotSame('archived', $run->fresh()->status);
    }
}
