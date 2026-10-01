<?php

use App\Http\Controllers\Web\AuthenticationController;
use App\Http\Controllers\Web\CompanyAccessController;
use App\Http\Controllers\Web\CompanyProfileController;
use App\Http\Controllers\Web\CoreErpPageController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\RegistrationController;
use App\Http\Controllers\Web\SuperAdminLicenseController;
use App\Http\Controllers\Web\SuperAdminPlatformController;
use App\Services\CompanyLandingService;
use App\Services\SubscriptionLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request, CompanyLandingService $landing) {
    if ($request->user()) {
        return redirect($landing->forUser($request->user()));
    }

    return Inertia::render('Welcome');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticationController::class, 'show'])->defaults('type', 'company')->name('login');
    Route::post('/login', [AuthenticationController::class, 'login'])->defaults('type', 'company')->middleware('throttle:login');
    Route::get('/super-admin/login', [AuthenticationController::class, 'show'])->defaults('type', 'super_admin')->name('super-admin.login');
    Route::post('/super-admin/login', [AuthenticationController::class, 'login'])->defaults('type', 'super_admin')->middleware('throttle:login');
    Route::get('/register', [RegistrationController::class, 'show'])->name('registration.create');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:3,1')->name('registration.store');
});

Route::get('/register/verify/{registration}', [RegistrationController::class, 'verify'])
    ->middleware('signed')
    ->name('registration.verify');
Route::post('/locale/{locale}', [LocaleController::class, 'update'])->name('locale.update');
Route::post('/logout', [AuthenticationController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'account.type:company', 'tenant', 'subscription', 'local.license', 'session.version'])->group(function (): void {
    Route::get('/dashboard', [CoreErpPageController::class, 'dashboard'])->middleware('can:dashboard.view')->name('company.dashboard');
    Route::get('/settings/access', [CompanyAccessController::class, 'manage'])->middleware('can:users.view')->name('company.access');
    Route::get('/settings/users/{user}', [CompanyAccessController::class, 'profile'])->name('company.users.profile');
    Route::get('/settings/users/{user}/activity', [CompanyAccessController::class, 'activityPage'])->middleware('can:users.view_activity')->name('company.users.activity');
    Route::get('/settings/users/{user}/logins', [CompanyAccessController::class, 'loginHistoryPage'])->middleware('can:users.view_activity')->name('company.users.logins');
    Route::get('/subscription', function (Request $request, SubscriptionLifecycle $lifecycle) {
        $subscription = $lifecycle->currentFor($request->attributes->get('company'));

        return Inertia::render('Company/Subscription', ['subscription' => $subscription, 'status' => $request->attributes->get('subscription_status')]);
    })->middleware('can:subscriptions.view')->name('company.subscription');
    Route::post('/subscription/contact', fn () => back()->with('status', 'Your renewal request has been sent to the platform team.'))->name('company.subscription.contact');
    Route::post('/subscription/renew', fn () => back()->with('status', 'Your renewal request has been sent to the platform team.'))->name('company.subscription.renew');
    Route::post('/password', [CompanyProfileController::class, 'updatePassword'])->name('company.password.update');
    Route::get('/branches', [CoreErpPageController::class, 'branches'])->middleware('can:branches.view')->name('company.branches');
    Route::get('/inventory', [CoreErpPageController::class, 'inventory'])->middleware('can:inventory.view')->name('company.inventory');
    Route::get('/inventory/export', [CoreErpPageController::class, 'exportInventory'])->middleware('can:inventory.export')->name('company.inventory.export');
    Route::get('/parties', [CoreErpPageController::class, 'parties'])->middleware('can:parties.view')->name('company.parties');
    Route::get('/customers', [CoreErpPageController::class, 'customers'])->middleware('can:parties.view')->name('company.customers');
    Route::get('/customers/{party}', [CoreErpPageController::class, 'customerProfile'])->middleware('can:parties.view')->name('company.customer.profile');
    Route::get('/suppliers', [CoreErpPageController::class, 'suppliers'])->middleware('can:parties.view')->name('company.suppliers');
    Route::get('/suppliers/{party}', [CoreErpPageController::class, 'supplierProfile'])->middleware('can:parties.view')->name('company.supplier.profile');
    Route::get('/accounting', [CoreErpPageController::class, 'accounting'])->middleware('can:accounting.view')->name('company.accounting');
    Route::get('/settings/accounting', [CoreErpPageController::class, 'accountingSettings'])->name('company.accounting-settings');
    Route::get('/reports/{report}', [CoreErpPageController::class, 'report'])->middleware(['can:reports.view', 'throttle:api.reports'])->whereIn('report', ['journal', 'account-statement', 'ledger', 'trial-balance', 'income-statement', 'balance-sheet', 'debtors', 'receivables', 'payables', 'creditors'])->name('company.accounting.report');
    Route::get('/operations/purchase/create', [CoreErpPageController::class, 'billForm'])->defaults('type', 'purchase')->middleware('can:purchases.create')->name('company.purchase.create');
    Route::get('/operations/sales/create', [CoreErpPageController::class, 'billForm'])->defaults('type', 'sales')->middleware('can:sales.create')->name('company.sales.create');
    Route::get('/operations/purchase/{bill}/edit', [CoreErpPageController::class, 'billForm'])->defaults('type', 'purchase')->name('company.purchase.edit');
    Route::get('/operations/sales/{bill}/edit', [CoreErpPageController::class, 'billForm'])->defaults('type', 'sales')->name('company.sales.edit');
    Route::get('/operations/purchase/{bill}/print', [CoreErpPageController::class, 'printBill'])->defaults('type', 'purchase')->middleware('can:purchases.print')->name('company.purchase.print');
    Route::get('/operations/sales/{bill}/print', [CoreErpPageController::class, 'printBill'])->defaults('type', 'sales')->middleware('can:sales.print')->name('company.sales.print');
    Route::get('/operations/purchase/{bill}/export', [CoreErpPageController::class, 'exportBill'])->defaults('type', 'purchase')->middleware(['can:purchases.export', 'throttle:api.exports'])->name('company.purchase.export');
    Route::get('/operations/sales/{bill}/export', [CoreErpPageController::class, 'exportBill'])->defaults('type', 'sales')->middleware(['can:sales.export', 'throttle:api.exports'])->name('company.sales.export');
    Route::get('/operations', [CoreErpPageController::class, 'operations'])->name('company.operations');
    Route::get('/fleet', [CoreErpPageController::class, 'fleet'])->middleware('can:fleet.view')->name('company.fleet');
    Route::get('/fleet/reports/{report}/export', [CoreErpPageController::class, 'exportFleetReport'])->middleware(['can:fleet.export', 'throttle:api.exports'])->whereIn('report', ['vehicle-pl', 'trip-cost', 'fuel', 'driver-performance', 'expenses-by-category', 'maintenance-period', 'inactive-vehicles', 'branch-performance'])->name('company.fleet.report.export');
    Route::get('/fleet/reports/{report}', [CoreErpPageController::class, 'fleetReport'])->middleware(['can:fleet.view', 'throttle:api.reports'])->whereIn('report', ['vehicle-pl', 'trip-cost', 'fuel', 'driver-performance', 'expenses-by-category', 'maintenance-period', 'inactive-vehicles', 'branch-performance'])->name('company.fleet.report');
});

Route::prefix('super-admin')->name('super-admin.')->middleware(['auth', 'account.type:super_admin', 'session.version'])->group(function (): void {
    Route::get('/', [SuperAdminPlatformController::class, 'index'])->name('dashboard');
    Route::post('/registration-setting', [SuperAdminPlatformController::class, 'updateRegistration'])->name('registration-setting');
    Route::post('/plans', [SuperAdminPlatformController::class, 'storePlan'])->name('plans.store');
    Route::post('/coupons', [SuperAdminPlatformController::class, 'storeCoupon'])->name('coupons.store');
    Route::post('/registrations/{registration}/approve', [SuperAdminPlatformController::class, 'approveRegistration'])->name('registrations.approve');
    Route::post('/registrations/{registration}/reject', [SuperAdminPlatformController::class, 'rejectRegistration'])->name('registrations.reject');
    Route::post('/companies', [SuperAdminPlatformController::class, 'storeCompany'])->name('companies.store');
    Route::post('/subscriptions/{subscription}/payments', [SuperAdminPlatformController::class, 'recordPayment'])->name('subscriptions.payments.store');
    Route::post('/companies/{company}/activate', [SuperAdminPlatformController::class, 'activateCompany'])->name('companies.activate');
    Route::post('/companies/{company}/suspend', [SuperAdminPlatformController::class, 'suspendCompany'])->name('companies.suspend');
    Route::post('/companies/{company}/archive', [SuperAdminPlatformController::class, 'archiveCompany'])->name('companies.archive');
    Route::post('/companies/{company}/restore', [SuperAdminPlatformController::class, 'restoreCompany'])->name('companies.restore');
    Route::post('/companies/{company}/plan', [SuperAdminPlatformController::class, 'changeCompanyPlan'])->name('companies.plan');
    Route::post('/companies/{company}/renew', [SuperAdminPlatformController::class, 'renewCompany'])->name('companies.renew');
    Route::put('/companies/{company}/limit-addons', [SuperAdminPlatformController::class, 'updateCompanyLimitAddons'])->name('companies.limit-addons');
    Route::post('/licenses/{license}/enable', [SuperAdminLicenseController::class, 'enable'])->name('licenses.enable');
    Route::post('/licenses/{license}/disable', [SuperAdminLicenseController::class, 'disable'])->name('licenses.disable');
    Route::post('/licenses/{license}/revoke', [SuperAdminLicenseController::class, 'revoke'])->name('licenses.revoke');
    Route::post('/licenses/{license}/transfer', [SuperAdminLicenseController::class, 'transfer'])->name('licenses.transfer');
});
