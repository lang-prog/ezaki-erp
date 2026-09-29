<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CustomerSupplier;
use App\Models\Diameter;
use App\Models\Driver;
use App\Models\FiscalPeriod;
use App\Models\FleetExpense;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\PurchaseBill;
use App\Models\SalesBill;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\AccountingFoundationService;
use App\Services\AccountingReportService;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class CoreErpPageController extends Controller
{
    public function dashboard(Request $request, AccountingReportService $reports): Response
    {
        $company = $request->attributes->get('company');
        $companyId = (int) $company->id;

        return Inertia::render('Company/Dashboard', [
            'capabilities' => $this->capabilities($request, ['dashboard.view', 'branches.view', 'inventory.view', 'parties.view', 'accounting.view', 'reports.view', 'purchases.view', 'sales.view', 'fleet.view', 'users.view']),
            'metrics' => [
                'branches' => DB::table('branches')->where('company_id', $companyId)->whereNull('archived_at')->count(),
                'warehouses' => DB::table('warehouses')->where('company_id', $companyId)->whereNull('archived_at')->count(),
                'products' => DB::table('products')->where('company_id', $companyId)->whereNull('archived_at')->count(),
                'stock_quantity' => (float) DB::table('stock_balances')->where('company_id', $companyId)->sum('quantity'),
                'parties' => DB::table('customer_suppliers')->where('company_id', $companyId)->where('status', 'active')->count(),
                'debtors' => $reports->debtors($company)['rows']->total(),
                'monthly_sales' => DB::table('sales_bills')->where('company_id', $companyId)->where('status', 'approved')->whereBetween('bill_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('total'),
                'monthly_purchases' => DB::table('purchase_bills')->where('company_id', $companyId)->where('status', 'approved')->whereBetween('supplier_bill_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('total'),
            ],
            'daily_sales' => DB::table('sales_bills')->where('company_id', $companyId)->where('status', 'approved')->whereBetween('bill_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->select('bill_date', DB::raw('SUM(total) as total'))->groupBy('bill_date')->orderBy('bill_date')->get(),
            'payments_receipts' => ['payments' => (float) DB::table('payment_vouchers')->where('company_id', $companyId)->whereBetween('voucher_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount'), 'receipts' => (float) DB::table('receipts')->where('company_id', $companyId)->whereBetween('receipt_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('amount')],
            'top_sold_products' => DB::table('sales_bill_lines')->join('sales_bills', 'sales_bills.id', '=', 'sales_bill_lines.sales_bill_id')->join('products', 'products.id', '=', 'sales_bill_lines.product_id')->where('sales_bills.company_id', $companyId)->where('sales_bills.status', 'approved')->select('products.name', DB::raw('SUM(sales_bill_lines.actual_weight) as quantity'))->groupBy('products.id', 'products.name')->orderByDesc('quantity')->limit(5)->get(),
            'sales_by_customer' => DB::table('sales_bills')->join('customer_suppliers', 'customer_suppliers.id', '=', 'sales_bills.customer_id')->where('sales_bills.company_id', $companyId)->where('sales_bills.status', 'approved')->select('customer_suppliers.name', DB::raw('SUM(sales_bills.total) as total'))->groupBy('customer_suppliers.id', 'customer_suppliers.name')->orderByDesc('total')->limit(5)->get(),
        ]);
    }

    public function branches(Request $request): Response
    {
        $companyId = (int) $request->user()->company_id;

        return Inertia::render('Company/Branches', [
            'branches' => Branch::query()->where('company_id', $companyId)->whereNull('archived_at')->with('warehouses')->orderBy('name')->paginate(25),
            'capabilities' => $this->capabilities($request, ['branches.create', 'branches.update', 'branches.archive', 'warehouses.update', 'warehouses.archive']),
        ]);
    }

    public function inventory(Request $request, InventoryService $inventory): Response
    {
        $company = $request->attributes->get('company');

        return Inertia::render('Company/Inventory', [
            ...$inventory->matrix($company, $request->integer('warehouse_id') ?: null),
            'products' => Product::query()->where('company_id', $company->id)->whereNull('archived_at')->with(['type:id,name', 'diameter:id,millimeters'])->orderBy('name')->get(),
            'productTypes' => ProductType::query()->where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(),
            'diameters' => Diameter::query()->where('company_id', $company->id)->orderBy('millimeters')->get(),
            'capabilities' => $this->capabilities($request, ['products.create', 'inventory.transfer', 'inventory.export']),
        ]);
    }

    public function parties(Request $request, AccountingFoundationService $foundation): Response
    {
        return $this->partyPage($request, $foundation, null);
    }

    public function customers(Request $request, AccountingFoundationService $foundation): Response
    {
        return $this->partyPage($request, $foundation, 'customer');
    }

    public function suppliers(Request $request, AccountingFoundationService $foundation): Response
    {
        return $this->partyPage($request, $foundation, 'supplier');
    }

    public function partyProfile(Request $request, CustomerSupplier $party): Response
    {
        abort_unless((int) $party->company_id === (int) $request->attributes->get('company')->id, 404);

        return Inertia::render('Company/PartyProfile', ['party' => $party->load('account:id,code,name'), 'kind' => $party->is_supplier && ! $party->is_customer ? 'supplier' : 'customer']);
    }

    private function partyPage(Request $request, AccountingFoundationService $foundation, ?string $kind): Response
    {
        $company = $request->attributes->get('company');
        $foundation->seedCompany($company);
        $companyId = (int) $company->id;

        return Inertia::render('Company/Parties', [
            'parties' => CustomerSupplier::query()->where('company_id', $companyId)->where('status', 'active')->when($kind === 'customer', fn ($query) => $query->where('is_customer', true))->when($kind === 'supplier', fn ($query) => $query->where('is_supplier', true))->with('account:id,code')->orderBy('name')->paginate(25),
            'kind' => $kind,
            'accounts' => Account::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
            'capabilities' => $this->capabilities($request, ['parties.create', 'parties.update']),
        ]);
    }

    public function accounting(Request $request, AccountingFoundationService $foundation): Response
    {
        $company = $request->attributes->get('company');
        $foundation->seedCompany($company);
        $companyId = (int) $company->id;

        return Inertia::render('Company/Accounting', [
            'accounts' => Account::query()->where('company_id', $companyId)->with('parent:id,code,name')->orderBy('code')->get(),
            'cashboxes' => Cashbox::query()->where('company_id', $companyId)->where('is_active', true)->with('account:id,code')->get(),
            'banks' => Bank::query()->where('company_id', $companyId)->where('is_active', true)->with('account:id,code')->get(),
            'periods' => FiscalPeriod::query()->where('company_id', $companyId)->orderByDesc('starts_on')->get(),
            'parties' => CustomerSupplier::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'is_customer', 'is_supplier']),
            'receipts' => DB::table('receipts')->where('company_id', $companyId)->orderByDesc('receipt_date')->limit(20)->get(),
            'vouchers' => DB::table('payment_vouchers')->where('company_id', $companyId)->orderByDesc('voucher_date')->limit(20)->get(),
            'capabilities' => $this->capabilities($request, ['accounting.create', 'accounting.post', 'accounting.close_period', 'accounting.reverse']),
        ]);
    }

    public function report(Request $request, AccountingReportService $reports, string $report): Response
    {
        $company = $request->attributes->get('company');
        $range = $request->validate(['from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $range['from_date'] ?? $range['from'] ?? null;
        $to = $range['to_date'] ?? $range['to'] ?? null;
        abort_unless(in_array($report, ['journal', 'account-statement', 'ledger', 'trial-balance', 'income-statement', 'balance-sheet', 'debtors'], true), 404);
        $payload = match ($report) {
            'journal' => $reports->journal($company, $from, $to),
            'account-statement' => $reports->accountStatement($company, Account::query()->where('company_id', $company->id)->findOrFail($request->integer('account_id')), $from, $to),
            'ledger' => $reports->ledger($company, $from, $to),
            'trial-balance' => $reports->trialBalance($company, $to),
            'income-statement' => $reports->incomeStatement($company, $from, $to),
            'balance-sheet' => $reports->balanceSheet($company, $to),
            'debtors' => $reports->debtors($company, 50, $from, $to),
        };

        return Inertia::render('Company/AccountingReport', [
            'report' => $report,
            'from' => $from,
            'to' => $to,
            ...$payload,
            'capabilities' => $this->capabilities($request, ['accounting.view', 'accounting.reverse', 'reports.export']),
        ]);
    }

    public function operations(Request $request): Response
    {
        abort_unless($request->user()->can('purchases.view') || $request->user()->can('sales.view') || $request->user()->can('fleet.view'), 403);
        $companyId = (int) $request->user()->company_id;

        return Inertia::render('Company/Operations', [
            'purchases' => PurchaseBill::query()->where('company_id', $companyId)->with('supplier:id,name')->latest()->paginate(15, ['*'], 'purchases_page'),
            'sales' => SalesBill::query()->where('company_id', $companyId)->with('customer:id,name')->latest()->paginate(15, ['*'], 'sales_page'),
            'vehicles' => Vehicle::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('plate')->get(['id', 'plate', 'ownership']),
            'trips' => Trip::query()->where('company_id', $companyId)->with('vehicle:id,plate')->latest('trip_at')->limit(15)->get(),
            'capabilities' => $this->capabilities($request, ['purchases.view', 'purchases.create', 'purchases.update', 'purchases.approve', 'purchases.update_approved', 'purchases.reverse', 'purchases.print', 'sales.view', 'sales.create', 'sales.update', 'sales.approve', 'sales.update_approved', 'sales.reverse', 'sales.print', 'fleet.view', 'fleet.create', 'fleet.expenses', 'fleet.maintenance']),
        ]);
    }

    public function fleet(Request $request): Response
    {
        $companyId = (int) $request->user()->company_id;

        return Inertia::render('Company/Fleet', [
            'vehicles' => Vehicle::query()->where('company_id', $companyId)->latest()->paginate(25, ['*'], 'vehicles_page'),
            'drivers' => Driver::query()->where('company_id', $companyId)->latest()->paginate(25, ['*'], 'drivers_page'),
            'trips' => Trip::query()->where('company_id', $companyId)->with('vehicle:id,plate')->latest('trip_at')->paginate(25, ['*'], 'trips_page'),
            'expenses' => FleetExpense::query()->where('company_id', $companyId)->latest()->paginate(25, ['*'], 'expenses_page'),
            'maintenance' => MaintenanceRecord::query()->where('company_id', $companyId)->latest('starts_on')->paginate(25, ['*'], 'maintenance_page'),
            'capabilities' => $this->capabilities($request, ['fleet.view', 'fleet.create', 'fleet.update', 'fleet.approve', 'fleet.expenses', 'fleet.maintenance', 'fleet.export']),
        ]);
    }

    public function fleetReport(Request $request, string $report): Response
    {
        abort_unless(in_array($report, ['maintenance-period', 'inactive-vehicles'], true), 404);
        $companyId = (int) $request->user()->company_id;
        $data = $report === 'maintenance-period'
            ? MaintenanceRecord::query()->where('company_id', $companyId)->whereBetween('starts_on', [$request->input('from', '2000-01-01'), $request->input('to', '2100-01-01')])->select('vehicle_id', DB::raw('SUM(cost) as total'))->groupBy('vehicle_id')->get()
            : Vehicle::query()->where('company_id', $companyId)->where('status', 'inactive')->get();

        return Inertia::render('Company/FleetReport', ['report' => $report, 'rows' => $data, 'capabilities' => $this->capabilities($request, ['fleet.view', 'fleet.export'])]);
    }

    public function billForm(Request $request, string|int|null $first = null, string|int|null $second = null): Response
    {
        [$type, $bill] = is_string($first) && in_array($first, ['purchase', 'sales'], true) ? [$first, $second === null ? null : (int) $second] : [(string) $second, $first === null ? null : (int) $first];
        abort_unless(in_array($type, ['purchase', 'sales'], true), 404);
        $companyId = (int) $request->user()->company_id;
        $document = $bill === null ? null : ($type === 'purchase'
            ? PurchaseBill::query()->where('company_id', $companyId)->with('lines')->findOrFail($bill)
            : SalesBill::query()->where('company_id', $companyId)->with('lines')->findOrFail($bill));
        if ($document) {
            $editPermission = $type === 'purchase'
                ? ($document->status === 'approved' ? 'purchases.update_approved' : 'purchases.update')
                : ($document->status === 'approved' ? 'sales.update_approved' : 'sales.update');
            abort_unless($request->user()->can($editPermission), 403);
        }

        return Inertia::render('Company/BillForm', [
            'type' => $type,
            'document' => $document,
            'branches' => Branch::query()->where('company_id', $companyId)->whereNull('archived_at')->with('warehouses:id,branch_id,name')->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('company_id', $companyId)->whereNull('archived_at')->orderBy('name')->get(['id', 'branch_id', 'name']),
            'parties' => CustomerSupplier::query()->where('company_id', $companyId)->where('status', 'active')->where($type === 'purchase' ? 'is_supplier' : 'is_customer', true)->orderBy('name')->get(['id', 'name', 'credit_limit']),
            'products' => Product::query()->where('company_id', $companyId)->whereNull('archived_at')->orderBy('name')->get(['id', 'sku', 'name', 'unit']),
            'productTypes' => ProductType::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'diameters' => Diameter::query()->where('company_id', $companyId)->orderBy('millimeters')->get(['id', 'millimeters']),
            'vehicles' => Vehicle::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('plate')->get(['id', 'plate']),
            'capabilities' => $this->capabilities($request, [$type === 'purchase' ? 'purchases.create' : 'sales.create', $type === 'purchase' ? 'purchases.update' : 'sales.update', $type === 'purchase' ? 'purchases.approve' : 'sales.approve', $type === 'purchase' ? 'purchases.update_approved' : 'sales.update_approved', $type === 'purchase' ? 'purchases.reverse' : 'sales.reverse', $type === 'purchase' ? 'purchases.print' : 'sales.print', $type === 'purchase' ? 'purchases.export' : 'sales.export']),
        ]);
    }

    public function printBill(Request $request, string|int $first, string|int|null $second = null): \Illuminate\Http\Response
    {
        [$type, $bill] = in_array((string) $first, ['purchase', 'sales'], true) ? [(string) $first, (int) $second] : [(string) $second, (int) $first];
        abort_unless(in_array($type, ['purchase', 'sales'], true), 404);
        $company = $request->attributes->get('company');
        $document = $type === 'purchase'
            ? PurchaseBill::query()->where('company_id', $company->id)->with(['lines.product', 'supplier'])->findOrFail($bill)
            : SalesBill::query()->where('company_id', $company->id)->with(['lines.product', 'customer'])->findOrFail($bill);

        return response()->view($type === 'purchase' ? 'operations.purchase-print' : 'operations.sales-print', ['document' => $document, 'company' => $company, 'locale' => app()->getLocale()]);
    }

    public function exportBill(Request $request, string|int $first, string|int|null $second = null): \Illuminate\Http\Response
    {
        [$type, $bill] = in_array((string) $first, ['purchase', 'sales'], true) ? [(string) $first, (int) $second] : [(string) $second, (int) $first];
        $response = $this->printBill($request, $type, $bill);
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$type.'-bill-'.$bill.'.html"');

        return $response;
    }

    private function capabilities(Request $request, array $permissions): array
    {
        $result = [];
        foreach ($permissions as $permission) {
            $result[$permission] = $request->user()->can($permission);
        }

        return $result;
    }
}
