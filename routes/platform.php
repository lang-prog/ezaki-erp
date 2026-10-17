<?php

use App\Http\Controllers\Platform\AdminController;
use App\Http\Controllers\Platform\AuditController;
use App\Http\Controllers\Platform\CatalogController;
use App\Http\Controllers\Platform\CompanyController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\DurationController;
use App\Http\Controllers\Platform\LicenseController;
use App\Http\Controllers\Platform\MfaController;
use App\Http\Controllers\Platform\RegistrationController as PlatformRegistrationController;
use App\Http\Controllers\Platform\RequestController;
use App\Http\Controllers\Platform\SettingsController;
use App\Http\Controllers\Platform\SubscriptionController;
use App\Http\Controllers\Web\AuthenticationController;
use Illuminate\Support\Facades\Route;

// Platform area: authentication + account type + session version everywhere; MFA, permission and step-up per route.
Route::prefix('super-admin')->name('super-admin.')->middleware(['auth', 'account.type:super_admin', 'session.version'])->group(function (): void {
    Route::post('/logout', [AuthenticationController::class, 'logout'])->name('logout');

    // Reachable before the second factor is satisfied (challenge and enrolment).
    Route::get('/mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('/mfa/challenge', [MfaController::class, 'verify'])->middleware('throttle:platform.auth')->name('mfa.verify');
    Route::get('/security', [MfaController::class, 'security'])->name('security');
    Route::post('/security/enroll', [MfaController::class, 'confirm'])->middleware('throttle:platform.auth')->name('mfa.confirm');

    Route::middleware('platform.mfa')->group(function (): void {
        Route::post('/step-up', [MfaController::class, 'stepUp'])->middleware('throttle:platform.auth')->name('step-up');
        Route::post('/security/recovery-codes', [MfaController::class, 'regenerate'])->middleware(['platform.stepup', 'throttle:platform.write'])->name('mfa.recovery-codes');
        Route::post('/security/disable', [MfaController::class, 'disable'])->middleware(['platform.stepup', 'throttle:platform.write'])->name('mfa.disable');

        Route::get('/', [DashboardController::class, 'index'])->middleware('platform.can:dashboard.view')->name('dashboard');

        Route::get('/companies', [CompanyController::class, 'index'])->middleware('platform.can:companies.view')->name('companies.index');
        Route::post('/companies', [CompanyController::class, 'store'])->middleware(['platform.can:companies.create', 'throttle:platform.write'])->name('companies.store');
        foreach (['activate', 'suspend', 'archive', 'restore'] as $action) {
            Route::post("/companies/{company}/{$action}", [CompanyController::class, $action])->middleware(['platform.can:companies.lifecycle', 'platform.stepup', 'throttle:platform.write'])->name("companies.{$action}");
        }

        Route::get('/registrations', [PlatformRegistrationController::class, 'index'])->middleware('platform.can:registrations.manage')->name('registrations.index');
        Route::post('/registrations/{registration}/approve', [PlatformRegistrationController::class, 'approve'])->middleware(['platform.can:registrations.manage', 'throttle:platform.write'])->name('registrations.approve');
        Route::post('/registrations/{registration}/reject', [PlatformRegistrationController::class, 'reject'])->middleware(['platform.can:registrations.manage', 'throttle:platform.write'])->name('registrations.reject');

        Route::get('/subscriptions', [SubscriptionController::class, 'index'])->middleware('platform.can:subscriptions.view')->name('subscriptions.index');
        Route::get('/subscriptions/preview', [SubscriptionController::class, 'preview'])->middleware('platform.can:subscriptions.view')->name('subscriptions.preview');
        Route::post('/subscriptions/{subscription}/payments', [SubscriptionController::class, 'recordPayment'])->middleware(['platform.can:subscriptions.payments', 'platform.stepup', 'throttle:platform.write'])->name('subscriptions.payments.store');
        Route::post('/payments/{payment}/resolve', [SubscriptionController::class, 'resolvePayment'])->middleware(['platform.can:subscriptions.payments', 'platform.stepup', 'throttle:platform.write'])->name('payments.resolve');
        Route::post('/companies/{company}/plan', [SubscriptionController::class, 'changePlan'])->middleware(['platform.can:subscriptions.plan_change', 'platform.stepup', 'throttle:platform.write'])->name('companies.plan');
        Route::post('/companies/{company}/renew', [SubscriptionController::class, 'renew'])->middleware(['platform.can:subscriptions.payments', 'platform.stepup', 'throttle:platform.write'])->name('companies.renew');
        Route::put('/companies/{company}/limit-addons', [SubscriptionController::class, 'updateAddons'])->middleware(['platform.can:subscriptions.limits', 'platform.stepup', 'throttle:platform.write'])->name('companies.limit-addons');

        Route::get('/plans', [CatalogController::class, 'index'])->middleware('platform.can:plans.manage,coupons.manage')->name('plans.index');
        Route::post('/plans', [CatalogController::class, 'storePlan'])->middleware(['platform.can:plans.manage', 'throttle:platform.write'])->name('plans.store');
        Route::post('/plans/{plan}/toggle', [CatalogController::class, 'togglePlan'])->middleware(['platform.can:plans.manage', 'platform.stepup', 'throttle:platform.write'])->name('plans.toggle');
        Route::put('/plans/{plan}', [CatalogController::class, 'updatePlan'])->middleware(['platform.can:plans.manage', 'platform.stepup', 'throttle:platform.write'])->name('plans.update');

        Route::get('/durations', [DurationController::class, 'index'])->middleware('platform.can:plans.manage')->name('durations.index');
        Route::post('/durations', [DurationController::class, 'store'])->middleware(['platform.can:plans.manage', 'throttle:platform.write'])->name('durations.store');
        Route::put('/durations/{duration}', [DurationController::class, 'update'])->middleware(['platform.can:plans.manage', 'platform.stepup', 'throttle:platform.write'])->name('durations.update');
        Route::post('/durations/{duration}/activate', [DurationController::class, 'activate'])->middleware(['platform.can:plans.manage', 'platform.stepup', 'throttle:platform.write'])->name('durations.activate');
        Route::post('/durations/{duration}/deactivate', [DurationController::class, 'deactivate'])->middleware(['platform.can:plans.manage', 'platform.stepup', 'throttle:platform.write'])->name('durations.deactivate');
        Route::post('/coupons', [CatalogController::class, 'storeCoupon'])->middleware(['platform.can:coupons.manage', 'throttle:platform.write'])->name('coupons.store');
        Route::post('/coupons/{coupon}/toggle', [CatalogController::class, 'toggleCoupon'])->middleware(['platform.can:coupons.manage', 'platform.stepup', 'throttle:platform.write'])->name('coupons.toggle');

        Route::get('/licenses', [LicenseController::class, 'index'])->middleware('platform.can:licenses.view')->name('licenses.index');
        foreach (['enable', 'disable', 'revoke', 'transfer'] as $action) {
            Route::post("/licenses/{license}/{$action}", [LicenseController::class, $action])->middleware(['platform.can:licenses.manage', 'platform.stepup', 'throttle:platform.write'])->name("licenses.{$action}");
        }

        Route::get('/admins', [AdminController::class, 'index'])->middleware('platform.can:admins.view')->name('admins.index');
        Route::post('/admins', [AdminController::class, 'store'])->middleware(['platform.can:admins.manage', 'platform.stepup', 'throttle:platform.write'])->name('admins.store');
        Route::put('/admins/{admin}/role', [AdminController::class, 'updateRole'])->middleware(['platform.can:admins.manage', 'platform.stepup', 'throttle:platform.write'])->name('admins.role');
        Route::post('/admins/{admin}/status', [AdminController::class, 'setStatus'])->middleware(['platform.can:admins.manage', 'platform.stepup', 'throttle:platform.write'])->name('admins.status');
        Route::post('/admins/{admin}/mfa-reset', [AdminController::class, 'resetMfa'])->middleware(['platform.can:admins.manage', 'platform.stepup', 'throttle:platform.write'])->name('admins.mfa-reset');

        Route::get('/audit', [AuditController::class, 'index'])->middleware('platform.can:audit.view')->name('audit.index');
        Route::get('/settings', [SettingsController::class, 'index'])->middleware('platform.can:settings.view')->name('settings.index');
        Route::post('/registration-setting', [SettingsController::class, 'updateRegistration'])->middleware(['platform.can:settings.manage', 'platform.stepup', 'throttle:platform.write'])->name('registration-setting');

        Route::get('/requests', [RequestController::class, 'index'])->middleware('platform.can:subscription_requests.manage')->name('requests.index');
        Route::post('/requests/{subscriptionRequest}/handle', [RequestController::class, 'handle'])->middleware(['platform.can:subscription_requests.manage', 'throttle:platform.write'])->name('requests.handle');
    });
});
