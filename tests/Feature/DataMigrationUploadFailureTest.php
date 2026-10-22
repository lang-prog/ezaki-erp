<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Models\User;
use App\Services\AccountingFoundationService;
use App\Services\Legacy\LegacySchema;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class DataMigrationUploadFailureTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('data-migrations');
        parent::tearDown();
    }

    private function owner(): User
    {
        $this->seq++;

        return User::query()->create([
            'name' => 'Upload owner', 'email' => 'upload-owner-'.$this->seq.'@example.test', 'password' => 'Correct-horse-1',
            'account_type' => 'super_admin', 'platform_role' => 'owner', 'status' => 'active',
        ]);
    }

    private function company(User $owner): Company
    {
        $company = Company::query()->create(['name' => 'Upload target '.$this->seq, 'status' => 'active']);
        app(AccountingFoundationService::class)->seedCompany($company, $owner);

        return $company;
    }

    /** @param array<string, list<array<string, mixed>>> $rows */
    private function sql(array $rows = [], bool $allTables = true): string
    {
        $sql = "-- synthetic upload fixture\n";
        foreach (LegacySchema::REQUIRED as $table => $columns) {
            if (! $allTables && $table !== 'customer') {
                continue;
            }
            $sql .= 'CREATE TABLE `'.$table.'` ('.implode(', ', array_map(fn ($column) => '`'.$column.'` text', $columns)).");\n";
            foreach ($rows[$table] ?? [] as $row) {
                $values = array_map(fn ($column) => array_key_exists($column, $row) ? "'".str_replace("'", "''", (string) $row[$column])."'" : 'NULL', $columns);
                $sql .= 'INSERT INTO `'.$table.'` (`'.implode('`, `', $columns).'`) VALUES ('.implode(', ', $values).");\n";
            }
        }

        return $sql;
    }

    private function uploaded(string $content, string $filename, string $mime = 'text/plain'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dmup');
        file_put_contents($path, $content);

        return new UploadedFile($path, $filename, $mime, null, true);
    }

    private function requestUpload(User $owner, Company $company, UploadedFile $file, array $headers = []): TestResponse
    {
        return $this->withSession(['platform_stepup_at' => time()])->actingAs($owner)->call('POST', '/super-admin/data-migrations', [
            'company_id' => $company->id,
            'documents_from' => '2026-10-01',
            'documents_to' => '2026-10-18',
            'opening_balance_as_of' => '2026-09-30',
            'gap_reason' => '',
            'customer_start_balance_sign' => 'debit_negative',
            'supplier_start_balance_sign' => 'credit_negative',
        ], [], ['source' => $file], ['HTTP_ACCEPT' => 'application/json', ...$headers]);
    }

    public function test_multipart_upload_uses_the_real_route_and_returns_json(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $file = $this->uploaded(gzencode($this->sql(), 6), 'legacy-fixture.sql.gz', 'application/gzip');
        $seen = null;
        Event::listen(RequestHandled::class, function ($event) use (&$seen): void {
            if ($event->request->path() === 'super-admin/data-migrations') {
                $seen = $event->request;
            }
        });

        $response = $this->requestUpload($owner, $company, $file);
        $response->assertOk()->assertJsonPath('duplicate', false);
        $run = DataMigrationRun::query()->findOrFail($response->json('run.id'));
        $this->assertSame($company->id, (int) $run->company_id);
        $this->assertSame(strlen(gzencode($this->sql(), 6)), (int) $run->source_bytes);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertNotNull($seen);
        $this->assertSame('POST', $seen->method());
        $this->assertTrue($seen->files->has('source'));
        $this->assertTrue($seen->headers->has('X-Requested-With') || $seen->headers->has('Accept'));
        $this->assertTrue(Storage::disk('local')->exists($run->source_path));
        $this->assertSame(1, DB::table('data_migration_identity_claims')->count());
    }

    public function test_missing_migration_record_returns_an_actionable_preflight_before_storage(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        DB::table('migrations')->where('migration', '2026_10_21_000001_remediate_data_migration_center')->delete();
        $response = $this->requestUpload($owner, $company, $this->uploaded(gzencode($this->sql()), 'valid.sql.gz', 'application/gzip'));

        $response->assertStatus(503)->assertJsonPath('code', 'migration_schema_pending');
        $this->assertSame(0, DataMigrationRun::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('data-migrations'));
    }

    public function test_inertia_upload_schema_preflight_returns_a_field_error_and_keeps_the_form_contract(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        DB::table('migrations')->where('migration', '2026_10_21_000001_remediate_data_migration_center')->delete();
        $response = $this->requestUpload($owner, $company, $this->uploaded(gzencode($this->sql()), 'valid.sql.gz', 'application/gzip'), [
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('source');
        $this->assertSame(0, DataMigrationRun::query()->count());
    }

    public function test_fake_gzip_binary_invalid_extension_and_oversized_files_are_rejected(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $this->requestUpload($owner, $company, $this->uploaded($this->sql(), 'fake.sql.gz', 'application/gzip'))->assertStatus(422)->assertJsonValidationErrors('source');
        $this->requestUpload($owner, $company, $this->uploaded("\x00\x01binary", 'binary.sql'))->assertStatus(422)->assertJsonValidationErrors('source');
        $this->requestUpload($owner, $company, $this->uploaded($this->sql(), 'source.txt'))->assertStatus(422)->assertJsonValidationErrors('source');

        config(['migration.max_upload_kb' => 1]);
        $large = $this->uploaded("INSERT INTO `customer` (`id_customer`) VALUES (1);\n".str_repeat('x', 2048), 'large.sql');
        $this->requestUpload($owner, $company, $large)->assertStatus(422)->assertJsonValidationErrors('source');
        $this->assertSame(0, DataMigrationRun::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('data-migrations'));
    }

    public function test_malformed_sql_is_accepted_for_upload_but_parse_rejects_it_without_staging(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $response = $this->requestUpload($owner, $company, $this->uploaded("INSERT INTO `customer` (`id_customer`) VALUES (1,2);\n", 'malformed.sql'));
        $response->assertOk();
        $runId = (int) $response->json('run.id');
        $this->withSession(['platform_stepup_at' => time()])->actingAs($owner)->json('POST', "/super-admin/data-migrations/{$runId}/parse")->assertStatus(422);
        $this->assertSame(0, DB::table('data_migration_source_rows')->where('run_id', $runId)->count());
    }

    public function test_archived_company_and_missing_platform_permission_are_rejected(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $company->forceFill(['status' => 'archived'])->save();
        $this->requestUpload($owner, $company, $this->uploaded(gzencode($this->sql()), 'archived.sql.gz', 'application/gzip'))
            ->assertStatus(404);
        $company->forceFill(['status' => 'active'])->save();

        $support = User::query()->create([
            'name' => 'Restricted support', 'email' => 'upload-support@example.test', 'password' => 'Correct-horse-1',
            'account_type' => 'super_admin', 'platform_role' => 'support', 'status' => 'active',
        ]);
        $this->requestUpload($support, $company, $this->uploaded(gzencode($this->sql()), 'forbidden.sql.gz', 'application/gzip'))
            ->assertForbidden();
        $this->assertSame(0, DataMigrationRun::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('data-migrations'));
    }

    public function test_storage_failure_creates_no_run_or_claim(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $rootFile = tempnam(sys_get_temp_dir(), 'dm-root');
        config(['filesystems.disks.local' => ['driver' => 'local', 'root' => $rootFile, 'throw' => true]]);
        Storage::forgetDisk('local');
        $response = $this->requestUpload($owner, $company, $this->uploaded(gzencode($this->sql()), 'storage-fail.sql.gz', 'application/gzip'));
        Storage::fake('local');
        @unlink($rootFile);

        $response->assertStatus(500);
        $this->assertSame(0, DataMigrationRun::query()->count());
        $this->assertSame(0, DB::table('data_migration_identity_claims')->count());
    }

    public function test_database_failure_after_private_store_rolls_back_run_and_deletes_file(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        DataMigrationRun::created(static function (): void {
            throw new \RuntimeException('injected database-side failure');
        });
        $response = $this->requestUpload($owner, $company, $this->uploaded(gzencode($this->sql()), 'rollback.sql.gz', 'application/gzip'));

        $response->assertStatus(500);
        $this->assertSame(0, DataMigrationRun::query()->count());
        $this->assertSame(0, DB::table('data_migration_identity_claims')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('data-migrations'));
    }

    public function test_rejected_upload_does_not_log_source_contents(): void
    {
        $owner = $this->owner();
        $company = $this->company($owner);
        $marker = 'PRIVATE-MIGRATION-FIXTURE-MARKER-DO-NOT-LOG';
        $this->requestUpload($owner, $company, $this->uploaded("\x00".$marker, 'binary.sql'))->assertStatus(422);
        $this->assertSame(0, DataMigrationRun::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('data-migrations'));
        $this->assertFalse(str_contains((string) file_get_contents(storage_path('logs/laravel.log')), $marker));
    }
}
