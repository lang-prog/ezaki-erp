<?php

use App\Http\Middleware\CheckAccountType;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\EnsureLocalLicense;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->web(append: [SetLocale::class, HandleInertiaRequests::class]);
        $middleware->alias([
            'account.type' => CheckAccountType::class,
            'tenant' => SetTenantContext::class,
            'subscription' => CheckSubscriptionStatus::class,
            'local.license' => EnsureLocalLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
