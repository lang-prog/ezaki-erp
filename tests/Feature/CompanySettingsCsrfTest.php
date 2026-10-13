<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CompanySettingsCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withMiddleware(ValidateCsrfToken::class);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    public function test_inertia_multipart_put_spoof_with_encrypted_xsrf_cookie_persists_company(): void
    {
        Storage::fake('public');
        [$company, $owner] = $this->tenant(true);
        $cookies = $this->browserCookies($owner);

        $response = $this->withHeaders($this->inertiaHeaders($cookies))
            ->call('POST', '/settings/company', [
                '_method' => 'put', 'name' => 'Saved Co', 'trade_name' => 'Saved Trade', 'tax_number' => 'T-9', 'phone' => '', 'country' => 'Egypt', 'city' => 'Cairo', 'address' => '', 'notes' => '',
                'print_background' => '', 'remove_print_background' => '0',
            ], $cookies, [], ['HTTP_X_INERTIA' => 'true', 'HTTP_X_XSRF_TOKEN' => $this->xsrfHeader($cookies)]);

        $this->assertContains($response->getStatusCode(), [302, 303]);
        $this->assertDatabaseHas('companies', ['id' => $company->id, 'name' => 'Saved Co', 'trade_name' => 'Saved Trade', 'tax_number' => 'T-9']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $company->id, 'user_id' => $owner->id, 'event' => 'company.settings_updated']);
    }

    public function test_multipart_background_upload_is_stored_with_csrf_enforced(): void
    {
        Storage::fake('public');
        [$company, $owner] = $this->tenant(true);
        $cookies = $this->browserCookies($owner);

        $this->call('POST', '/settings/company', ['_method' => 'put', 'name' => 'With Upload'], $cookies, [
            'print_background' => UploadedFile::fake()->create('bg.png', 10, 'image/png'),
        ], ['HTTP_X_XSRF_TOKEN' => $this->xsrfHeader($cookies), 'HTTP_X_INERTIA' => 'true']);

        $path = $company->refresh()->print_background_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_missing_invalid_or_url_encoded_xsrf_header_is_rejected_with_419(): void
    {
        [$company, $owner] = $this->tenant(true);
        $cookies = $this->browserCookies($owner);
        $payload = ['_method' => 'put', 'name' => 'Hacked'];

        foreach ([[], ['HTTP_X_XSRF_TOKEN' => 'garbage'], ['HTTP_X_XSRF_TOKEN' => rawurlencode($this->xsrfHeader($cookies))]] as $server) {
            $response = $this->call('POST', '/settings/company', $payload, $cookies, [], $server + ['HTTP_X_INERTIA' => 'true']);
            $this->assertSame(419, $response->getStatusCode());
        }
        $this->assertDatabaseMissing('companies', ['id' => $company->id, 'name' => 'Hacked']);
    }

    public function test_non_owner_with_valid_csrf_is_forbidden(): void
    {
        [$company] = $this->tenant(true);
        $staff = User::query()->create(['name' => 'Staff', 'email' => 'staff-csrf@example.test', 'password' => 'password', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $cookies = $this->browserCookies($staff, '/help');

        $response = $this->call('POST', '/settings/company', ['_method' => 'put', 'name' => 'Nope'], $cookies, [], ['HTTP_X_XSRF_TOKEN' => $this->xsrfHeader($cookies)]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertDatabaseMissing('companies', ['id' => $company->id, 'name' => 'Nope']);
    }

    public function test_validation_errors_are_returned_to_the_inertia_form(): void
    {
        [, $owner] = $this->tenant(true);
        $cookies = $this->browserCookies($owner);

        $response = $this->call('POST', '/settings/company', ['_method' => 'put', 'name' => ''], $cookies, [], ['HTTP_X_XSRF_TOKEN' => $this->xsrfHeader($cookies), 'HTTP_X_INERTIA' => 'true']);

        $this->assertContains($response->getStatusCode(), [302, 303]);
        $response->assertSessionHasErrors('name');
    }

    private function inertiaHeaders(array $cookies): array
    {
        return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'];
    }

    /** Mimics the browser: logs in via actingAs, loads a page and keeps the encrypted cookies Laravel set. */
    private function browserCookies(User $user, string $path = '/settings/company'): array
    {
        $response = $this->actingAs($user)->get($path);
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $this->assertArrayHasKey('XSRF-TOKEN', $cookies);
        $this->assertStringStartsWith('eyJpdiI6', $cookies['XSRF-TOKEN']);
        $this->assertArrayHasKey(config('session.company_cookie'), $cookies);

        return $cookies;
    }

    private function xsrfHeader(array $cookies): string
    {
        // Symfony stores the raw value; the browser sends it URL-decoded to the header.
        return $cookies['XSRF-TOKEN'];
    }

    private function tenant(bool $owner): array
    {
        $company = Company::query()->create(['name' => 'Csrf Co', 'status' => 'active']);
        $plan = Plan::query()->create(['code' => 'csrf', 'name' => 'Csrf', 'duration' => 'yearly', 'price' => 1, 'limits' => [], 'is_active' => true]);
        Subscription::query()->create(['company_id' => $company->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
        $user = User::query()->create(['name' => 'Owner', 'email' => 'owner-csrf@example.test', 'password' => 'password', 'company_id' => $company->id, 'account_type' => 'company', 'status' => 'active', 'email_verified_at' => now()]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId((int) $company->id);
        $user->assignRole(Role::query()->create(['name' => 'Company Owner', 'guard_name' => 'web', 'company_id' => $company->id]));
        $registrar->setPermissionsTeamId(null);

        return [$company, $user];
    }
}
