<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\CheckAccountType;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\EnsureLocalLicense;
use App\Http\Middleware\EnsureSessionVersion;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\PlatformCan;
use App\Http\Middleware\PlatformMfa;
use App\Http\Middleware\PlatformStepUp;
use App\Http\Middleware\RequireCompanyApiAbility;
use App\Http\Middleware\ScopeSessionCookie;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

$isPhpUnit = isset($_SERVER['argv']) && in_array('artisan', $_SERVER['argv'], true) && in_array('test', $_SERVER['argv'], true);
$configCache = dirname(__DIR__).'/bootstrap/cache/config.php';
if ($isPhpUnit && is_file($configCache)) {
    if (! @unlink($configCache)) {
        throw new RuntimeException('Remove the cached Laravel configuration before running PHPUnit.');
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->statefulApi();
        $middleware->web(prepend: [TrustProxies::class, AssignRequestId::class, ScopeSessionCookie::class], append: [SetLocale::class, HandleInertiaRequests::class]);
        $middleware->api(prepend: [TrustProxies::class, AssignRequestId::class, ScopeSessionCookie::class]);
        $middleware->alias([
            'account.type' => CheckAccountType::class,
            'tenant' => SetTenantContext::class,
            'subscription' => CheckSubscriptionStatus::class,
            'local.license' => EnsureLocalLicense::class,
            'session.version' => EnsureSessionVersion::class,
            'platform.can' => PlatformCan::class,
            'platform.mfa' => PlatformMfa::class,
            'platform.stepup' => PlatformStepUp::class,
            'abilities' => CheckAbilities::class,
            'company.api' => RequireCompanyApiAbility::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
