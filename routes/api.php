<?php

use App\Http\Controllers\Api\V1\AuthenticationController;
use App\Http\Controllers\Api\V1\CompanyProfileController;
use App\Http\Controllers\Api\V1\CoreErpController;
use App\Http\Controllers\Api\V1\LocalLicenseController;
use App\Http\Controllers\Api\V1\OperationsController;
use App\Http\Controllers\Web\CompanyAccessController;
use App\Services\SubscriptionLifecycle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'version' => 'v1']))->name('api.v1.health');
    Route::get('/version', fn () => response()->json(['data' => ['api' => 'v1', 'application' => config('app.name'), 'laravel' => app()->version()]]))->name('api.v1.version');
    Route::post('/login', [AuthenticationController::class, 'login'])->middleware('throttle:6,1')->name('api.v1.login');
    Route::post('/license/activate', [LocalLicenseController::class, 'activate'])->middleware('throttle:5,1')->name('api.v1.license.activate');
    Route::middleware(['auth:sanctum', 'account.type:company', 'tenant', 'subscription', 'local.license'])->group(function (): void {
        Route::get('/subscription', function (Request $request, SubscriptionLifecycle $lifecycle) {
            $subscription = $lifecycle->currentFor($request->attributes->get('company'));

            return response()->json([
                'status' => $request->attributes->get('subscription_status'),
                'plan' => $subscription?->plan?->only(['id', 'name', 'duration']),
                'ends_at' => $subscription?->ends_at,
            ]);
        })->name('api.v1.subscription');
        Route::put('/password', [CompanyProfileController::class, 'updatePassword'])->name('api.v1.password.update');
        Route::put('/profile', [CompanyProfileController::class, 'update'])->name('api.v1.profile.update');
        Route::post('/logout', [AuthenticationController::class, 'logout'])->name('api.v1.logout');
        Route::get('/me', [CoreErpController::class, 'me'])->name('api.v1.me');
        Route::get('/dashboard', [CoreErpController::class, 'dashboard'])->middleware('can:dashboard.view')->name('api.v1.dashboard');
        Route::middleware('can:branches.view')->group(function (): void {
            Route::get('/branches', [CoreErpController::class, 'branches'])->name('api.v1.branches.index');
            Route::get('/warehouses', [CoreErpController::class, 'warehouses'])->name('api.v1.warehouses.index');
        });
        Route::middleware('can:branches.create')->post('/branches', [CoreErpController::class, 'createBranch'])->name('api.v1.branches.store');
        Route::middleware('can:branches.update')->put('/branches/{branch}', [CoreErpController::class, 'updateBranch'])->name('api.v1.branches.update');
        Route::middleware('can:branches.archive')->post('/branches/{branch}/archive', [CoreErpController::class, 'archiveBranch'])->name('api.v1.branches.archive');
        Route::middleware('can:warehouses.update')->put('/warehouses/{warehouse}', [CoreErpController::class, 'updateWarehouse'])->name('api.v1.warehouses.update');
        Route::middleware('can:warehouses.archive')->post('/warehouses/{warehouse}/archive', [CoreErpController::class, 'archiveWarehouse'])->name('api.v1.warehouses.archive');
        Route::middleware('can:products.view')->get('/product-types', [CoreErpController::class, 'productTypes']);
        Route::middleware('can:products.create')->post('/product-types', [CoreErpController::class, 'createProductType']);
        Route::middleware('can:products.update')->put('/product-types/{productType}', [CoreErpController::class, 'updateProductType']);
        Route::middleware('can:products.view')->get('/diameters', [CoreErpController::class, 'diameters']);
        Route::middleware('can:products.create')->post('/diameters', [CoreErpController::class, 'createDiameter']);
        Route::middleware('can:products.update')->put('/diameters/{diameter}', [CoreErpController::class, 'updateDiameter']);
        Route::middleware('can:products.view')->get('/products', [CoreErpController::class, 'products']);
        Route::middleware('can:products.create')->post('/products', [CoreErpController::class, 'createProduct']);
        Route::middleware('can:products.create')->post('/inventory/type-diameter-profile', [CoreErpController::class, 'saveTypeDiameterProfile']);
        Route::middleware('can:products.update')->put('/products/{product}', [CoreErpController::class, 'updateProduct']);
        Route::middleware('can:products.archive')->post('/products/{product}/archive', [CoreErpController::class, 'archiveProduct']);
        Route::middleware('can:inventory.view')->get('/inventory/matrix', [CoreErpController::class, 'inventoryMatrix']);
        Route::middleware('can:inventory.transfer')->get('/inventory/transfers', [CoreErpController::class, 'transfers']);
        Route::middleware('can:inventory.transfer')->post('/inventory/transfers', [CoreErpController::class, 'createTransfer']);
        Route::middleware('can:parties.view')->get('/parties', [CoreErpController::class, 'parties']);
        Route::middleware('can:parties.create')->post('/parties', [CoreErpController::class, 'createParty']);
        Route::middleware('can:parties.update')->put('/parties/{party}', [CoreErpController::class, 'updateParty']);
        Route::middleware('can:accounting.view')->get('/accounts', [CoreErpController::class, 'accounts']);
        Route::middleware('can:accounting.view')->get('/accounts/{account}/statement', [CoreErpController::class, 'accountStatement']);
        Route::middleware('can:accounting.create')->post('/accounts', [CoreErpController::class, 'createAccount']);
        Route::middleware('can:accounting.create')->put('/accounts/{account}', [CoreErpController::class, 'updateAccount']);
        Route::middleware('can:accounting.view')->get('/cashboxes', [CoreErpController::class, 'cashboxes']);
        Route::middleware('can:accounting.create')->post('/cashboxes', [CoreErpController::class, 'createCashbox']);
        Route::middleware('can:accounting.view')->get('/banks', [CoreErpController::class, 'banks']);
        Route::middleware('can:accounting.create')->post('/banks', [CoreErpController::class, 'createBank']);
        Route::middleware('can:accounting.view')->get('/fiscal-periods', [CoreErpController::class, 'periods']);
        Route::middleware('can:accounting.create')->post('/fiscal-periods', [CoreErpController::class, 'createPeriod']);
        Route::middleware('can:accounting.close_period')->post('/fiscal-periods/{period}/close', [CoreErpController::class, 'closePeriod']);
        Route::middleware('can:accounting.view')->get('/receipts', [CoreErpController::class, 'receipts']);
        Route::middleware('can:accounting.post')->post('/receipts', [CoreErpController::class, 'createReceipt']);
        Route::middleware('can:accounting.view')->get('/payment-vouchers', [CoreErpController::class, 'vouchers']);
        Route::middleware('can:accounting.post')->post('/payment-vouchers', [CoreErpController::class, 'createVoucher']);
        Route::middleware('can:accounting.view')->get('/journals', [CoreErpController::class, 'report'])->defaults('report', 'journal');
        Route::middleware('can:accounting.view')->get('/reports/{report}', [CoreErpController::class, 'report'])->whereIn('report', ['account-statement', 'ledger', 'trial-balance', 'income-statement', 'balance-sheet', 'debtors']);
        Route::middleware('can:accounting.reverse')->post('/journals/{entry}/reverse', [CoreErpController::class, 'reverseJournal']);
        Route::middleware('can:purchases.view')->get('/purchase-bills', [OperationsController::class, 'purchases']);
        Route::middleware('can:purchases.create')->post('/purchase-bills', [OperationsController::class, 'storePurchase']);
        Route::middleware('can:purchases.create')->get('/purchase-bills/near-duplicates', [OperationsController::class, 'purchaseWarnings']);
        Route::middleware('can:purchases.view')->get('/purchase-bills/{purchaseBill}', [OperationsController::class, 'purchase']);
        Route::middleware('can:purchases.update')->put('/purchase-bills/{purchaseBill}', [OperationsController::class, 'updatePurchase']);
        Route::middleware('can:purchases.view')->get('/bills/{type}/{bill}/transport-allocation', [OperationsController::class, 'allocation']);
        Route::middleware('can:purchases.approve')->post('/purchase-bills/{purchaseBill}/approve', [OperationsController::class, 'approvePurchase']);
        Route::middleware('can:purchases.reverse')->post('/purchase-bills/{purchaseBill}/reverse', [OperationsController::class, 'reversePurchase']);
        Route::middleware('can:purchases.update_approved')->put('/purchase-bills/{purchaseBill}/revision', [OperationsController::class, 'revisePurchase']);
        Route::middleware('can:sales.view')->get('/sales-bills', [OperationsController::class, 'sales']);
        Route::middleware('can:sales.create')->post('/sales-bills', [OperationsController::class, 'storeSales']);
        Route::middleware('can:sales.create')->get('/sales-bills/near-duplicates', [OperationsController::class, 'salesWarnings']);
        Route::middleware('can:sales.view')->get('/sales-bills/{salesBill}', [OperationsController::class, 'salesBill']);
        Route::middleware('can:sales.update')->put('/sales-bills/{salesBill}', [OperationsController::class, 'updateSales']);
        Route::middleware('can:sales.approve')->post('/sales-bills/{salesBill}/approve', [OperationsController::class, 'approveSales']);
        Route::middleware('can:sales.reverse')->post('/sales-bills/{salesBill}/reverse', [OperationsController::class, 'reverseSales']);
        Route::middleware('can:sales.update_approved')->put('/sales-bills/{salesBill}/revision', [OperationsController::class, 'reviseSales']);
        Route::middleware('can:fleet.view')->get('/fleet/vehicles', [OperationsController::class, 'vehicles']);
        Route::middleware('can:fleet.create')->post('/fleet/vehicles', [OperationsController::class, 'storeVehicle']);
        Route::middleware('can:fleet.update')->put('/fleet/vehicles/{vehicle}', [OperationsController::class, 'updateVehicle']);
        Route::middleware('can:fleet.view')->get('/fleet/drivers', [OperationsController::class, 'drivers']);
        Route::middleware('can:fleet.create')->post('/fleet/drivers', [OperationsController::class, 'storeDriver']);
        Route::middleware('can:fleet.update')->put('/fleet/drivers/{driver}', [OperationsController::class, 'updateDriver']);
        Route::middleware('can:fleet.view')->get('/fleet/trips', [OperationsController::class, 'trips']);
        Route::middleware('can:fleet.create')->post('/fleet/trips', [OperationsController::class, 'storeTrip']);
        Route::middleware('can:fleet.update')->put('/fleet/trips/{trip}', [OperationsController::class, 'updateTrip']);
        Route::middleware('can:fleet.expenses')->get('/fleet/expenses', [OperationsController::class, 'expenses']);
        Route::middleware('can:fleet.expenses')->post('/fleet/expenses', [OperationsController::class, 'storeExpense']);
        Route::middleware('can:fleet.expenses')->put('/fleet/expenses/{expense}', [OperationsController::class, 'updateExpense']);
        Route::middleware('can:fleet.approve')->post('/fleet/expenses/{expense}/reverse', [OperationsController::class, 'reverseExpense']);
        Route::middleware('can:fleet.approve')->post('/fleet/expenses/{expense}/approve', [OperationsController::class, 'approveExpense']);
        Route::middleware('can:fleet.maintenance')->get('/fleet/maintenance', [OperationsController::class, 'maintenance']);
        Route::middleware('can:fleet.maintenance')->post('/fleet/maintenance', [OperationsController::class, 'storeMaintenance']);
        Route::middleware('can:fleet.maintenance')->put('/fleet/maintenance/{record}', [OperationsController::class, 'updateMaintenance']);
        Route::middleware('can:fleet.approve')->post('/fleet/maintenance/{record}/reverse', [OperationsController::class, 'reverseMaintenance']);
        Route::middleware('can:fleet.approve')->post('/fleet/maintenance/{record}/approve', [OperationsController::class, 'approveMaintenance']);
        Route::middleware('can:fleet.view')->get('/fleet/reports/{report}', [OperationsController::class, 'report'])->whereIn('report', ['vehicle-pl', 'trip-cost', 'fuel', 'driver-performance', 'expenses-by-category', 'maintenance-period', 'inactive-vehicles']);
        Route::middleware('can:roles.view')->get('/roles', [CompanyAccessController::class, 'roles']);
        Route::middleware('can:roles.create')->post('/roles', [CompanyAccessController::class, 'storeRole']);
        Route::middleware('can:roles.assign_permissions')->put('/roles/{role}', [CompanyAccessController::class, 'updateRole']);
        Route::middleware('can:users.view')->get('/users', [CompanyAccessController::class, 'users']);
        Route::middleware('can:users.create')->post('/users', [CompanyAccessController::class, 'storeUser']);
        Route::middleware('can:users.update')->put('/users/{user}', [CompanyAccessController::class, 'updateUser']);
        Route::middleware('can:users.view_activity')->get('/users/{user}/activity', [CompanyAccessController::class, 'activity']);
        Route::middleware('can:users.update')->put('/users/{user}/permissions', [CompanyAccessController::class, 'setDirectPermissions']);
        Route::middleware('can:users.deactivate')->patch('/users/{user}/status', [CompanyAccessController::class, 'setStatus']);
        Route::middleware('can:users.reset_password')->put('/users/{user}/password', [CompanyAccessController::class, 'resetPassword']);
    });
});
