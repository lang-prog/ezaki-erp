<?php

use App\Http\Controllers\Web\AuthenticationController;
use App\Http\Controllers\Web\CompanyAccessController;
use App\Http\Controllers\Web\CompanyProfileController;
use App\Http\Controllers\Web\CompanySubscriptionRequestController;
use App\Http\Controllers\Web\CoreErpPageController;
use App\Http\Controllers\Web\LocaleController;
use App\Http\Controllers\Web\PasswordResetController;
use App\Http\Controllers\Web\RegistrationController;
use App\Http\Middleware\PlatformGuest;
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
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'form'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:3,1')->name('password.update');
    Route::get('/register', [RegistrationController::class, 'show'])->name('registration.create');
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:3,1')->name('registration.store');
});

Route::middleware(PlatformGuest::class)->group(function (): void {
    Route::get('/super-admin/login', [AuthenticationController::class, 'show'])->defaults('type', 'super_admin')->name('super-admin.login');
    Route::post('/super-admin/login', [AuthenticationController::class, 'login'])->defaults('type', 'super_admin')->middleware('throttle:login');
});

Route::get('/register/verify/{registration}', [RegistrationController::class, 'verify'])
    ->middleware('signed')
    ->name('registration.verify');
Route::post('/locale/{locale}', [LocaleController::class, 'update'])->name('locale.update');
Route::post('/logout', [AuthenticationController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'account.type:company', 'tenant', 'subscription', 'local.license', 'session.version'])->group(function (): void {
    Route::get('/dashboard', [CoreErpPageController::class, 'dashboard'])->middleware('can:dashboard.view')->name('company.dashboard');
    Route::get('/help', fn () => Inertia::render('Company/HelpCenter'))->name('company.help');
    Route::get('/settings/company', [CompanyProfileController::class, 'companySettings'])->name('company.settings');
    Route::put('/settings/company', [CompanyProfileController::class, 'updateCompany'])->name('company.settings.update');
    Route::get('/settings/access', [CompanyAccessController::class, 'manage'])->middleware('can:users.view')->name('company.access');
    Route::get('/settings/users/{user}', [CompanyAccessController::class, 'profile'])->name('company.users.profile');
    Route::get('/settings/users/{user}/activity', [CompanyAccessController::class, 'activityPage'])->middleware('can:users.view_activity')->name('company.users.activity');
    Route::get('/settings/users/{user}/logins', [CompanyAccessController::class, 'loginHistoryPage'])->middleware('can:users.view_activity')->name('company.users.logins');
    Route::get('/subscription', function (Request $request, SubscriptionLifecycle $lifecycle) {
        $subscription = $lifecycle->currentFor($request->attributes->get('company'));

        return Inertia::render('Company/Subscription', [
            'subscription' => $subscription ? ['plan' => $subscription->plan ? [...$subscription->plan->only(['name', 'duration']), 'duration_info' => $subscription->plan->durationInfo()] : null] : null,
            'status' => $request->attributes->get('subscription_status'),
        ]);
    })->middleware('can:subscriptions.view')->name('company.subscription');
    Route::post('/subscription/contact', [CompanySubscriptionRequestController::class, 'store'])->defaults('type', 'contact')->middleware(['can:subscriptions.view', 'throttle:6,1'])->name('company.subscription.contact');
    Route::post('/subscription/renew', [CompanySubscriptionRequestController::class, 'store'])->defaults('type', 'renew')->middleware(['can:subscriptions.view', 'throttle:6,1'])->name('company.subscription.renew');
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
    Route::get('/accounting/chart', [CoreErpPageController::class, 'chartOfAccounts'])->middleware('can:accounting.view')->name('company.accounting.chart');
    Route::get('/accounting/periods', [CoreErpPageController::class, 'fiscalPeriods'])->middleware('can:accounting.view')->name('company.accounting.periods');
    Route::get('/accounting/cashboxes', [CoreErpPageController::class, 'cashboxes'])->middleware('can:accounting.view')->name('company.accounting.cashboxes');
    Route::get('/accounting/banks', [CoreErpPageController::class, 'banks'])->middleware('can:accounting.view')->name('company.accounting.banks');
    Route::get('/accounting/receipts/export', [CoreErpPageController::class, 'exportReceipts'])->middleware(['can:accounting.view', 'throttle:api.exports'])->name('company.receipts.export');
    Route::get('/accounting/payment-vouchers/export', [CoreErpPageController::class, 'exportVouchers'])->middleware(['can:accounting.view', 'throttle:api.exports'])->name('company.vouchers.export');
    Route::get('/accounting/receipts', [CoreErpPageController::class, 'receiptsList'])->middleware('can:accounting.view')->name('company.receipts.index');
    Route::get('/accounting/receipts/{document}', [CoreErpPageController::class, 'showReceipt'])->middleware('can:accounting.view')->whereNumber('document')->name('company.receipt.show');
    Route::get('/accounting/receipts/{document}/print', [CoreErpPageController::class, 'printReceipt'])->middleware('can:accounting.view')->whereNumber('document')->name('company.receipt.print');
    Route::get('/accounting/payment-vouchers', [CoreErpPageController::class, 'paymentVouchersList'])->middleware('can:accounting.view')->name('company.vouchers.index');
    Route::get('/accounting/payment-vouchers/{document}', [CoreErpPageController::class, 'showVoucher'])->middleware('can:accounting.view')->whereNumber('document')->name('company.voucher.show');
    Route::get('/accounting/payment-vouchers/{document}/print', [CoreErpPageController::class, 'printVoucher'])->middleware('can:accounting.view')->whereNumber('document')->name('company.voucher.print');
    Route::get('/settings/accounting', [CoreErpPageController::class, 'accountingSettings'])->name('company.accounting-settings');
    Route::get('/reports/product-movement', [CoreErpPageController::class, 'productMovementReport'])->middleware(['can:reports.view', 'throttle:api.reports'])->name('company.product-movement-report');
    Route::get('/reports/invoice-settlements', [CoreErpPageController::class, 'invoiceSettlementReport'])->middleware(['can:reports.view', 'throttle:api.reports'])->name('company.invoice-settlements');
    Route::get('/reports/{report}', [CoreErpPageController::class, 'report'])->middleware(['can:reports.view', 'throttle:api.reports'])->whereIn('report', ['journal', 'account-statement', 'ledger', 'trial-balance', 'income-statement', 'balance-sheet', 'debtors', 'receivables', 'payables', 'creditors', 'invoice-settlements'])->name('company.accounting.report');
    Route::get('/operations/purchase/create', [CoreErpPageController::class, 'billForm'])->defaults('type', 'purchase')->middleware('can:purchases.create')->name('company.purchase.create');
    Route::get('/operations/sales/create', [CoreErpPageController::class, 'billForm'])->defaults('type', 'sales')->middleware('can:sales.create')->name('company.sales.create');
    Route::get('/operations/purchase/{bill}', [CoreErpPageController::class, 'showBill'])->defaults('type', 'purchase')->middleware('can:purchases.view')->name('company.purchase.show');
    Route::get('/operations/sales/{bill}', [CoreErpPageController::class, 'showBill'])->defaults('type', 'sales')->middleware('can:sales.view')->name('company.sales.show');
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

require __DIR__.'/platform.php';
