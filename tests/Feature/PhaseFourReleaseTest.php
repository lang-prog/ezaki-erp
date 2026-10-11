<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ScopeSessionCookie;
use Tests\TestCase;

final class PhaseFourReleaseTest extends TestCase
{
    public function test_public_health_and_version_endpoints_are_json_and_securely_headered(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $this->getJson('/api/v1/version')
            ->assertOk()
            ->assertJsonPath('data.api', 'v1')
            ->assertJsonStructure(['data' => ['api', 'application', 'laravel']]);
    }

    public function test_browser_login_and_static_home_responses_receive_security_headers(): void
    {
        $this->get('/')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/login')->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_vite_assets_use_https_when_hosting_proxy_forwards_https(): void
    {
        $response = $this->withHeader('X-Forwarded-Proto', 'https')->get('/login');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('https://localhost/build/assets/', $html);
        $this->assertStringNotContainsString('http://localhost/build/assets/', $html);
    }

    public function test_stateful_api_shares_the_company_session_cookie_with_web_requests(): void
    {
        $groups = app('router')->getMiddlewareGroups();

        $this->assertContains(ScopeSessionCookie::class, $groups['web']);
        $this->assertContains(ScopeSessionCookie::class, $groups['api']);
    }
}
