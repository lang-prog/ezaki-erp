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
use App\Models\PaymentVoucher;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\PurchaseBill;
use App\Models\Receipt;
use App\Models\SalesBill;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\AccountingFoundationService;
use App\Services\AccountingReportService;
use App\Services\CompanyAccountingPolicyService;
use App\Services\FleetReportService;
use App\Services\InventoryService;
use App\Services\InvoiceSettlementService;
use App\Services\PaymentAllocationService;
use App\Support\SystemAccountCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CoreErpPageController extends Controller
{
    public function dashboard(Request $request, AccountingReportService $reports): Response
    {
        $company = $request->attributes->get('company');
        $companyId = (int) $company->id;
        $debtors = $reports->debtors($company);

        return Inertia::render('Company/Dashboard', [
            'capabilities' => $this->capabilities($request, ['dashboard.view', 'branches.view', 'inventory.view', 'parties.view', 'accounting.view', 'reports.view', 'purchases.view', 'sales.view', 'fleet.view', 'users.view']),
            'metrics' => [
                'branches' => DB::table('branches')->where('company_id', $companyId)->whereNull('archived_at')->count(),
                'warehouses' => DB::table('warehouses')->where('company_id', $companyId)->whereNull('archived_at')->count(),
                'products' => DB::table('products')->where('company_id', $companyId)->whereNull('archived_at')->count(),
                'stock_quantity' => (float) DB::table('stock_balances')->where('company_id', $companyId)->sum('quantity'),
                'parties' => DB::table('customer_suppliers')->where('company_id', $companyId)->where('status', 'active')->count(),
                'debtors' => $debtors['total_outstanding'],
                'debtor_count' => $debtors['rows']->total(),
                'monthly_sales' => DB::table('sales_bills')->where('company_id', $companyId)->where('status', 'approved')->whereDate('bill_date', '>=', now()->startOfMonth()->toDateString())->whereDate('bill_date', '<=', now()->endOfMonth()->toDateString())->sum('total'),
                'monthly_purchases' => DB::table('purchase_bills')->where('company_id', $companyId)->where('status', 'approved')->whereDate('supplier_bill_date', '>=', now()->startOfMonth()->toDateString())->whereDate('supplier_bill_date', '<=', now()->endOfMonth()->toDateString())->sum('total'),
            ],
            'daily_sales' => DB::table('sales_bills')->where('company_id', $companyId)->where('status', 'approved')->whereDate('bill_date', '>=', now()->startOfMonth()->toDateString())->whereDate('bill_date', '<=', now()->endOfMonth()->toDateString())->select('bill_date', DB::raw('SUM(total) as total'))->groupBy('bill_date')->orderBy('bill_date')->get(),
            'payments_receipts' => ['payments' => (float) DB::table('payment_vouchers')->where('company_id', $companyId)->where('status', 'posted')->whereDate('voucher_date', '>=', now()->startOfMonth()->toDateString())->whereDate('voucher_date', '<=', now()->endOfMonth()->toDateString())->sum('amount'), 'receipts' => (float) DB::table('receipts')->where('company_id', $companyId)->where('status', 'posted')->whereDate('receipt_date', '>=', now()->startOfMonth()->toDateString())->whereDate('receipt_date', '<=', now()->endOfMonth()->toDateString())->sum('amount')],
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
            'capabilities' => $this->capabilities($request, ['products.create', 'products.update', 'inventory.transfer', 'inventory.export']),
        ]);
    }

    public function exportInventory(Request $request, InventoryService $inventory): StreamedResponse
    {
        $filters = $request->validate(['warehouse_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:255']]);
        $matrix = $inventory->matrix($request->attributes->get('company'), isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null);
        $query = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $rows = collect($matrix['rows'])->when($query !== '', fn ($items) => $items->filter(fn ($row) => str_contains(mb_strtolower($row['type']), $query)))->values();

        return response()->streamDownload(function () use ($rows, $matrix): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Type', ...array_map(fn ($diameter) => $diameter.' mm', $matrix['diameter_columns']), 'Total']);
            foreach ($rows as $row) {
                fputcsv($output, [$row['type'], ...array_map(fn ($diameter) => $row['cells'][$diameter] ?? 0, $matrix['diameter_columns']), $row['total']]);
            }
            fclose($output);
        }, 'inventory-matrix.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function productMovementReport(Request $request): Response
    {
        $filters = $request->validate(['kind' => ['nullable', 'in:sales,purchases'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'product_type_id' => ['nullable', 'integer'], 'diameter_id' => ['nullable', 'integer']]);
        $company = $request->attributes->get('company');
        $kind = $filters['kind'] ?? 'sales';
        $isSales = $kind === 'sales';
        $billsTable = $isSales ? 'sales_bills' : 'purchase_bills';
        $linesTable = $isSales ? 'sales_bill_lines' : 'purchase_bill_lines';
        $billKey = $isSales ? 'sales_bill_id' : 'purchase_bill_id';
        $dateColumn = $isSales ? 'bill_date' : 'supplier_bill_date';
        $weight = $isSales ? 'COALESCE(lines.actual_weight, 0)' : 'COALESCE(lines.actual_weight, lines.factory_weight, 0)';
        $query = DB::table($linesTable.' as lines')->join($billsTable.' as bills', 'bills.id', '=', 'lines.'.$billKey)->join('products', 'products.id', '=', 'lines.product_id')->leftJoin('product_types', 'product_types.id', '=', 'products.product_type_id')->leftJoin('diameters', 'diameters.id', '=', 'products.diameter_id')->where('bills.company_id', $company->id)->where('bills.status', 'approved')->when($filters['from'] ?? null, fn ($q, $value) => $q->whereDate('bills.'.$dateColumn, '>=', $value))->when($filters['to'] ?? null, fn ($q, $value) => $q->whereDate('bills.'.$dateColumn, '<=', $value))->when($filters['product_type_id'] ?? null, fn ($q, $value) => $q->where('products.product_type_id', $value))->when($filters['diameter_id'] ?? null, fn ($q, $value) => $q->where('products.diameter_id', $value))->select('product_types.name as type_name', 'diameters.millimeters as diameter')->selectRaw('COUNT(DISTINCT bills.id) as document_count')->selectRaw('SUM('.$weight.') as weight')->selectRaw('SUM(lines.line_total) as value')->groupBy('product_types.name', 'diameters.millimeters')->orderBy('product_types.name')->orderBy('diameters.millimeters');

        return Inertia::render('Company/ProductMovementReport', ['kind' => $kind, 'rows' => $query->get(), 'productTypes' => ProductType::query()->where('company_id', $company->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']), 'diameters' => Diameter::query()->where('company_id', $company->id)->orderBy('millimeters')->get(['id', 'millimeters']), 'filters' => $filters]);
    }

    public function parties(Request $request, AccountingFoundationService $foundation, AccountingReportService $reports): Response
    {
        return $this->partyPage($request, $foundation, $reports, null);
    }

    public function customers(Request $request, AccountingFoundationService $foundation, AccountingReportService $reports): Response
    {
        return $this->partyPage($request, $foundation, $reports, 'customer');
    }

    public function suppliers(Request $request, AccountingFoundationService $foundation, AccountingReportService $reports): Response
    {
        return $this->partyPage($request, $foundation, $reports, 'supplier');
    }

    public function customerProfile(Request $request, CustomerSupplier $party): Response
    {
        return $this->partyProfile($request, $party, 'customer');
    }

    public function supplierProfile(Request $request, CustomerSupplier $party): Response
    {
        return $this->partyProfile($request, $party, 'supplier');
    }

    private function partyProfile(Request $request, CustomerSupplier $party, string $kind): Response
    {
        $matchesKind = $kind === 'customer' ? $party->is_customer : $party->is_supplier;
        abort_unless((int) $party->company_id === (int) $request->attributes->get('company')->id && $matchesKind, 404);
        $companyId = (int) $request->attributes->get('company')->id;

        return Inertia::render('Company/PartyProfile', [
            'party' => $party->load('account:id,code,name'),
            'kind' => $kind,
            'cashboxes' => Cashbox::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'banks' => Bank::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'capabilities' => $this->capabilities($request, ['accounting.view', 'accounting.post']),
            'canPost' => $request->user()->isCompanyOwner() || $request->user()->can('accounting.post'),
        ]);
    }

    private function partyPage(Request $request, AccountingFoundationService $foundation, AccountingReportService $reports, ?string $kind): Response
    {
        $company = $request->attributes->get('company');
        $companyId = (int) $company->id;
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $range['from'] ?? now()->startOfMonth()->toDateString();
        $to = $range['to'] ?? now()->endOfMonth()->toDateString();
        abort_if($from > $to, 422, 'The report start date must be before the end date.');
        $summary = $reports->partySummary($company, $kind, $from, $to);

        return Inertia::render('Company/Parties', [
            'parties' => CustomerSupplier::query()->where('company_id', $companyId)->where('status', 'active')->when($kind === 'customer', fn ($query) => $query->where('is_customer', true))->when($kind === 'supplier', fn ($query) => $query->where('is_supplier', true))->with('account:id,code')->orderBy('name')->paginate(25),
            'reportRows' => $summary['rows'],
            'reportSummary' => $summary['summary'],
            'from' => $from,
            'to' => $to,
            'kind' => $kind,
            'accounts' => Account::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'account_type']),
            'capabilities' => $this->capabilities($request, ['parties.create', 'parties.update']),
        ]);
    }

    public function accounting(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('company')->id;

        return Inertia::render('Company/Accounting', [
            'stats' => [
                'accounts' => Account::query()->where('company_id', $companyId)->count(),
                'cashboxes' => Cashbox::query()->where('company_id', $companyId)->where('is_active', true)->count(),
                'banks' => Bank::query()->where('company_id', $companyId)->where('is_active', true)->count(),
                'open_periods' => FiscalPeriod::query()->where('company_id', $companyId)->where('status', 'open')->count(),
                'receipts' => Receipt::query()->where('company_id', $companyId)->count(),
                'vouchers' => PaymentVoucher::query()->where('company_id', $companyId)->count(),
            ],
            'capabilities' => $this->capabilities($request, ['accounting.view', 'accounting.create', 'accounting.post', 'reports.view']),
        ]);
    }

    public function chartOfAccounts(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('company')->id;

        return Inertia::render('Company/ChartOfAccounts', [
            'accounts' => Account::query()->where('company_id', $companyId)->orderBy('code')->get(['id', 'parent_id', 'code', 'name', 'name_ar', 'name_en', 'account_type', 'system_key', 'is_system', 'is_active'])
                ->map(fn (Account $account): array => $account->toArray() + ['description' => SystemAccountCatalog::forCode((string) $account->code)]),
            'capabilities' => $this->capabilities($request, ['accounting.create', 'reports.view']),
        ]);
    }

    public function fiscalPeriods(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('company')->id;

        return Inertia::render('Company/FiscalPeriods', [
            'periods' => FiscalPeriod::query()->where('company_id', $companyId)->orderByDesc('starts_on')->get(),
            'capabilities' => $this->capabilities($request, ['accounting.create', 'accounting.close_period']),
        ]);
    }

    public function cashboxes(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('company')->id;

        return Inertia::render('Company/Cashboxes', [
            'cashboxes' => Cashbox::query()->where('company_id', $companyId)->where('is_active', true)->with('account:id,code')->orderBy('name')->get(),
            'capabilities' => $this->capabilities($request, ['accounting.create']),
        ]);
    }

    public function banks(Request $request): Response
    {
        $companyId = (int) $request->attributes->get('company')->id;

        return Inertia::render('Company/Banks', [
            'banks' => Bank::query()->where('company_id', $companyId)->where('is_active', true)->with('account:id,code')->orderBy('name')->get(),
            'capabilities' => $this->capabilities($request, ['accounting.create']),
        ]);
    }

    public function receiptsList(Request $request, PaymentAllocationService $allocations): Response
    {
        return $this->moneyList($request, 'receipt', $allocations);
    }

    public function paymentVouchersList(Request $request, PaymentAllocationService $allocations): Response
    {
        return $this->moneyList($request, 'voucher', $allocations);
    }

    public function exportReceipts(Request $request): StreamedResponse
    {
        return $this->exportMoney($request, 'receipt');
    }

    public function exportVouchers(Request $request): StreamedResponse
    {
        return $this->exportMoney($request, 'voucher');
    }

    private function moneyQuery(Request $request, string $kind)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'status' => ['nullable', 'in:posted,reversed']]);
        $receipt = $kind === 'receipt';
        $model = $receipt ? Receipt::class : PaymentVoucher::class;
        $number = $receipt ? 'receipt_number' : 'voucher_number';
        $date = $receipt ? 'receipt_date' : 'voucher_date';
        $term = isset($filters['q']) ? addcslashes((string) $filters['q'], '%_\\') : null;

        return $model::query()->where('company_id', (int) $request->attributes->get('company')->id)
            ->with(['party:id,name', 'otherAccount:id,code,name,name_ar,name_en', 'cashbox:id,name', 'bank:id,name', 'createdBy:id,name'])
            ->when($term, fn ($query) => $query->where(fn ($inner) => $inner->where($number, 'like', "%{$term}%")->orWhereHas('party', fn ($party) => $party->where('name', 'like', "%{$term}%"))->orWhereHas('otherAccount', fn ($account) => $account->where('name', 'like', "%{$term}%")->orWhere('name_ar', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))))
            ->when($filters['from'] ?? null, fn ($query, $value) => $query->whereDate($date, '>=', $value))
            ->when($filters['to'] ?? null, fn ($query, $value) => $query->whereDate($date, '<=', $value))
            ->when($filters['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc($date)->orderByDesc('id');
    }

    private function moneyList(Request $request, string $kind, PaymentAllocationService $allocations): Response
    {
        $company = $request->attributes->get('company');
        $companyId = (int) $company->id;
        $receipt = $kind === 'receipt';
        $canPost = $request->user()->can('accounting.post');
        $filters = $request->only(['q', 'from', 'to', 'status']);

        return Inertia::render($receipt ? 'Company/ReceiptsList' : 'Company/PaymentVouchersList', [
            'documents' => $this->moneyQuery($request, $kind)->paginate(25)->withQueryString(),
            'filters' => $filters,
            'form' => $canPost ? [
                'parties' => CustomerSupplier::query()->where('company_id', $companyId)->where('status', 'active')->where($receipt ? 'is_customer' : 'is_supplier', true)->orderBy('name')->get(['id', 'name']),
                'accounts' => Account::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('code')->get(['id', 'parent_id', 'code', 'name', 'name_ar', 'name_en']),
                'cashboxes' => Cashbox::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'account_id']),
                'banks' => Bank::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'account_id']),
                'invoices' => $allocations->openInvoices($company, $receipt ? 'sales' : 'purchase'),
            ] : null,
            'capabilities' => $this->capabilities($request, ['accounting.view', 'accounting.post', 'accounting.reverse']),
        ]);
    }

    private function exportMoney(Request $request, string $kind): StreamedResponse
    {
        $receipt = $kind === 'receipt';
        $rows = $this->moneyQuery($request, $kind)->limit(5000)->get();
        $arabic = app()->getLocale() === 'ar';

        return response()->streamDownload(function () use ($rows, $receipt, $arabic): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, [$arabic ? 'الرقم' : 'Number', $arabic ? 'التاريخ' : 'Date', $arabic ? 'الطرف / الحساب' : 'Counterparty / Account', $arabic ? 'المبلغ' : 'Amount', $arabic ? 'الحالة' : 'Status', $receipt ? ($arabic ? 'سلّم بواسطة' : 'Delivered by') : ($arabic ? 'دفع بواسطة' : 'Paid by'), $arabic ? 'استلم بواسطة' : 'Received by']);
            foreach ($rows as $row) {
                $account = $row->otherAccount ? $row->otherAccount->code.' · '.($arabic ? ($row->otherAccount->name_ar ?: $row->otherAccount->name) : ($row->otherAccount->name_en ?: $row->otherAccount->name)) : null;
                fputcsv($output, [$receipt ? $row->receipt_number : $row->voucher_number, ($receipt ? $row->receipt_date : $row->voucher_date)?->toDateString(), $row->party?->name ?? $account ?? '', $row->amount, $row->status, $receipt ? $row->delivered_by : $row->paid_by, $row->received_by]);
            }
            fclose($output);
        }, ($receipt ? 'receipts' : 'payment-vouchers').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function invoiceSettlementReport(Request $request, InvoiceSettlementService $settlements): Response
    {
        $company = $request->attributes->get('company');
        $filters = $request->validate([
            'tab' => ['nullable', 'in:receivables,payables'], 'party_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'due_from' => ['nullable', 'date'], 'due_to' => ['nullable', 'date'],
            'state' => ['nullable', 'in:all,unpaid,partial,paid,overdue,reversed'], 'overdue_only' => ['nullable', 'boolean'], 'branch_id' => ['nullable', 'integer'], 'as_of' => ['nullable', 'date'],
        ]);
        $tab = $filters['tab'] ?? 'receivables';
        $kind = $tab === 'payables' ? 'purchase' : 'sales';
        $companyId = (int) $company->id;

        return Inertia::render('Company/InvoiceSettlementReport', [
            'tab' => $tab,
            'filters' => $filters,
            'report' => $settlements->report($company, $kind, $filters),
            'parties' => CustomerSupplier::query()->where('company_id', $companyId)->where($kind === 'sales' ? 'is_customer' : 'is_supplier', true)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->where('company_id', $companyId)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'capabilities' => $this->capabilities($request, ['reports.view', 'accounting.view', 'sales.view', 'sales.print', 'purchases.view', 'purchases.print']),
        ]);
    }

    public function accountingSettings(Request $request, CompanyAccountingPolicyService $policies): Response
    {
        abort_unless($request->user()->isCompanyOwner(), 403);
        $company = $request->attributes->get('company');

        return Inertia::render('Company/AccountingSettings', ['policy' => $policies->get($company)]);
    }

    public function report(Request $request, AccountingReportService $reports, string $report): Response|RedirectResponse
    {
        // Creditors and payables were the same calculation; one canonical page remains.
        if ($report === 'creditors') {
            return redirect()->route('company.accounting.report', ['report' => 'payables'] + $request->query(), 301);
        }
        $company = $request->attributes->get('company');
        $range = $request->validate(['from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'account_id' => ['nullable', 'integer', 'min:1']]);
        $from = $range['from_date'] ?? $range['from'] ?? null;
        $to = $range['to_date'] ?? $range['to'] ?? null;
        $view = $request->query('view', 'operational');
        abort_unless(in_array($report, ['journal', 'account-statement', 'ledger', 'trial-balance', 'income-statement', 'balance-sheet', 'debtors', 'receivables', 'payables', 'creditors'], true), 404);
        $statementAccount = $report === 'account-statement' && $request->filled('account_id')
            ? Account::query()->where('company_id', $company->id)->findOrFail($request->integer('account_id'))
            : null;
        $payload = match ($report) {
            'journal' => $reports->journal($company, $from, $to),
            'account-statement' => $statementAccount ? $reports->accountStatement($company, $statementAccount, $from, $to, $view) : ['account' => null, 'lines' => [], 'opening_balance' => 0],
            'ledger' => $reports->ledger($company, $from, $to),
            'trial-balance' => $reports->trialBalance($company, $to),
            'income-statement' => $reports->incomeStatement($company, $from, $to),
            'balance-sheet' => $reports->balanceSheet($company, $to),
            'debtors' => $reports->debtors($company, 50, $from, $to),
            'receivables' => $reports->receivables($company, 0, $from, $to),
            'payables' => $reports->payables($company, 0, $from, $to),
            'creditors' => $reports->payables($company, 0, $from, $to),
        };

        return Inertia::render('Company/AccountingReport', [
            'report' => $report,
            'availableAccounts' => $report === 'account-statement' ? Account::query()->where('company_id', $company->id)->orderBy('code')->get(['id', 'code', 'name']) : [],
            'from' => $from,
            'to' => $to,
            'view' => $view,
            ...$payload,
            'capabilities' => $this->capabilities($request, ['accounting.view', 'accounting.reverse', 'reports.export', 'sales.view', 'sales.print', 'purchases.view', 'purchases.print']),
        ]);
    }

    public function operations(Request $request): Response
    {
        abort_unless($request->user()->can('purchases.view') || $request->user()->can('sales.view') || $request->user()->can('fleet.view'), 403);
        $companyId = (int) $request->user()->company_id;

        return Inertia::render('Company/Operations', [
            'purchases' => $request->user()->can('purchases.view') ? PurchaseBill::query()->where('company_id', $companyId)->with('supplier:id,name')->orderByDesc('id')->paginate(15, ['*'], 'purchases_page') : ['data' => []],
            'sales' => $request->user()->can('sales.view') ? SalesBill::query()->where('company_id', $companyId)->with('customer:id,name')->orderByDesc('id')->paginate(15, ['*'], 'sales_page') : ['data' => []],
            'vehicles' => $request->user()->can('fleet.view') ? Vehicle::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('plate')->get(['id', 'plate', 'ownership']) : [],
            'trips' => $request->user()->can('fleet.view') ? Trip::query()->where('company_id', $companyId)->with('vehicle:id,plate')->latest('trip_at')->limit(15)->get() : [],
            'capabilities' => $this->capabilities($request, ['purchases.view', 'purchases.create', 'purchases.update', 'purchases.approve', 'purchases.update_approved', 'purchases.reverse', 'purchases.print', 'purchases.export', 'sales.view', 'sales.create', 'sales.update', 'sales.approve', 'sales.update_approved', 'sales.reverse', 'sales.print', 'sales.export', 'fleet.view', 'fleet.create', 'fleet.expenses', 'fleet.maintenance']),
        ]);
    }

    public function fleet(Request $request): Response
    {
        $companyId = (int) $request->user()->company_id;

        return Inertia::render('Company/Fleet', [
            'branches' => Branch::query()->where('company_id', $companyId)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
            'vehicles' => Vehicle::query()->where('company_id', $companyId)->latest()->paginate(25, ['*'], 'vehicles_page'),
            'drivers' => Driver::query()->where('company_id', $companyId)->latest()->paginate(25, ['*'], 'drivers_page'),
            'trips' => Trip::query()->where('company_id', $companyId)->with('vehicle:id,plate')->latest('trip_at')->paginate(25, ['*'], 'trips_page'),
            'expenses' => FleetExpense::query()->where('company_id', $companyId)->latest()->paginate(25, ['*'], 'expenses_page'),
            'maintenance' => MaintenanceRecord::query()->where('company_id', $companyId)->latest('starts_on')->paginate(25, ['*'], 'maintenance_page'),
            'cashboxes' => Cashbox::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'banks' => Bank::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'settlementAccounts' => Account::query()->where('company_id', $companyId)->where('is_active', true)->where('account_type', 'liability')->whereDoesntHave('children')->orderBy('code')->get(['id', 'code', 'name']),
            'capabilities' => $this->capabilities($request, ['fleet.view', 'fleet.create', 'fleet.update', 'fleet.approve', 'fleet.expenses', 'fleet.maintenance', 'fleet.export']),
        ]);
    }

    public function fleetReport(Request $request, FleetReportService $reports, string $report): Response
    {
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']]);
        $company = $request->attributes->get('company');
        $data = $reports->report($company, $report, $filters['from'] ?? null, $filters['to'] ?? null, isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);

        return Inertia::render('Company/FleetReport', [...$data, 'branches' => Branch::query()->where('company_id', $company->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name']), 'capabilities' => $this->capabilities($request, ['fleet.view', 'fleet.export'])]);
    }

    public function exportFleetReport(Request $request, FleetReportService $reports, string $report): StreamedResponse
    {
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']]);
        $data = $reports->report($request->attributes->get('company'), $report, $filters['from'] ?? null, $filters['to'] ?? null, isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);
        $rows = collect($data['rows']);
        $columns = $rows->flatMap(fn ($row) => array_keys($row))->unique()->values()->all();

        return response()->streamDownload(function () use ($rows, $columns): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, $columns);
            foreach ($rows as $row) {
                fputcsv($output, array_map(fn ($column) => $row[$column] ?? '', $columns));
            }
            fclose($output);
        }, $report.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
            'cashboxes' => Cashbox::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'banks' => Bank::query()->where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'accountingPolicy' => app(CompanyAccountingPolicyService::class)->get($request->attributes->get('company')),
            'capabilities' => $this->capabilities($request, [$type === 'purchase' ? 'purchases.create' : 'sales.create', $type === 'purchase' ? 'purchases.update' : 'sales.update', $type === 'purchase' ? 'purchases.approve' : 'sales.approve', $type === 'purchase' ? 'purchases.update_approved' : 'sales.update_approved', $type === 'purchase' ? 'purchases.reverse' : 'sales.reverse', $type === 'purchase' ? 'purchases.print' : 'sales.print', $type === 'purchase' ? 'purchases.export' : 'sales.export']),
        ]);
    }

    public function printBill(Request $request, string|int $first, string|int|null $second = null): \Illuminate\Http\Response
    {
        [$type, $bill] = in_array((string) $first, ['purchase', 'sales'], true) ? [(string) $first, (int) $second] : [(string) $second, (int) $first];
        abort_unless(in_array($type, ['purchase', 'sales'], true), 404);
        $company = $request->attributes->get('company');
        $mode = $request->string('mode', 'invoice')->toString();
        abort_unless(in_array($mode, ['invoice', 'delivery'], true), 422);
        $document = $type === 'purchase'
            ? PurchaseBill::query()->where('company_id', $company->id)->with(['lines.product.type', 'lines.product.diameter', 'supplier', 'branch', 'warehouse', 'vehicle'])->findOrFail($bill)
            : SalesBill::query()->where('company_id', $company->id)->with(['lines.product.type', 'lines.product.diameter', 'customer', 'branch', 'warehouse', 'vehicle'])->findOrFail($bill);

        return response()->view($type === 'purchase' ? 'operations.purchase-print' : 'operations.sales-print', ['document' => $document, 'company' => $company, 'locale' => app()->getLocale(), 'mode' => $mode, 'printBackgroundUrl' => $company->print_background_path ? Storage::disk('public')->url($company->print_background_path) : null]);
    }

    public function showBill(Request $request, string|int $first, string|int|null $second = null): Response
    {
        [$type, $bill] = is_string($first) && in_array($first, ['purchase', 'sales'], true) ? [$first, $second === null ? null : (int) $second] : [(string) $second, $first === null ? null : (int) $first];
        abort_unless(in_array($type, ['purchase', 'sales'], true) && $bill, 404);
        $companyId = (int) $request->user()->company_id;
        $document = $type === 'purchase'
            ? PurchaseBill::query()->where('company_id', $companyId)->with(['lines.product.type', 'lines.product.diameter', 'supplier', 'branch', 'warehouse', 'vehicle', 'createdBy:id,name', 'approvedBy:id,name', 'revisions.user:id,name'])->findOrFail($bill)
            : SalesBill::query()->where('company_id', $companyId)->with(['lines.product.type', 'lines.product.diameter', 'customer', 'branch', 'warehouse', 'vehicle', 'createdBy:id,name', 'approvedBy:id,name', 'revisions.user:id,name'])->findOrFail($bill);
        $editPermission = $type === 'purchase' ? ($document->status === 'approved' ? 'purchases.update_approved' : 'purchases.update') : ($document->status === 'approved' ? 'sales.update_approved' : 'sales.update');

        return Inertia::render('Company/BillView', [
            'type' => $type,
            'document' => $document,
            'revisions' => $document->revisions->sortByDesc('created_at')->values(),
            'capabilities' => $this->capabilities($request, [$editPermission, $type === 'purchase' ? 'purchases.print' : 'sales.print', $type === 'purchase' ? 'purchases.export' : 'sales.export']),
        ]);
    }

    public function showReceipt(Request $request, string|int $document): Response
    {
        return $this->showMoneyDocument($request, 'receipt', $document);
    }

    public function showVoucher(Request $request, string|int $document): Response
    {
        return $this->showMoneyDocument($request, 'voucher', $document);
    }

    private function showMoneyDocument(Request $request, string $kind, string|int $document): Response
    {
        abort_unless(in_array($kind, ['receipt', 'voucher'], true), 404);
        $companyId = (int) $request->attributes->get('company')->id;
        $model = $kind === 'receipt' ? Receipt::class : PaymentVoucher::class;
        $query = $model::query()->where('company_id', $companyId)->with(['party:id,name', 'otherAccount:id,code,name,name_ar,name_en', 'cashbox:id,name', 'bank:id,name', 'createdBy:id,name']);
        $record = $query->findOrFail((int) $document);
        $paymentType = $kind === 'receipt' ? 'receipt' : 'voucher';
        $billTable = $kind === 'receipt' ? 'sales_bills' : 'purchase_bills';
        $allocations = DB::table('party_payment_allocations as allocations')
            ->join($billTable.' as bills', fn ($join) => $join->on('bills.id', '=', 'allocations.bill_id')->on('bills.company_id', '=', 'allocations.company_id'))
            ->where('allocations.company_id', $companyId)->where('allocations.payment_type', $paymentType)->where('allocations.payment_id', $record->id)
            ->orderBy('allocations.id')
            ->get(['allocations.bill_id', 'allocations.amount', 'allocations.status', 'allocations.reversal_reason', 'bills.internal_number as invoice_number'])
            ->map(fn ($row): array => ['invoice_number' => $row->invoice_number, 'amount' => round((float) $row->amount, 2), 'status' => $row->status, 'reversal_reason' => $row->reversal_reason, 'view_url' => route($kind === 'receipt' ? 'company.sales.show' : 'company.purchase.show', ['bill' => $row->bill_id])]);

        return Inertia::render('Company/MoneyDocumentView', [
            'kind' => $kind,
            'document' => $record,
            'allocations' => $allocations,
            'capabilities' => $this->capabilities($request, ['accounting.view', 'accounting.post', 'accounting.reverse', $kind === 'receipt' ? 'sales.view' : 'purchases.view']),
        ]);
    }

    public function printReceipt(Request $request, string|int $document): \Illuminate\Http\Response
    {
        return $this->printMoneyDocument($request, 'receipt', $document);
    }

    public function printVoucher(Request $request, string|int $document): \Illuminate\Http\Response
    {
        return $this->printMoneyDocument($request, 'voucher', $document);
    }

    private function printMoneyDocument(Request $request, string $kind, string|int $document): \Illuminate\Http\Response
    {
        abort_unless(in_array($kind, ['receipt', 'voucher'], true), 404);
        $company = $request->attributes->get('company');
        $model = $kind === 'receipt' ? Receipt::class : PaymentVoucher::class;
        $record = $model::query()->where('company_id', $company->id)->with(['party:id,name', 'otherAccount:id,code,name,name_ar,name_en', 'cashbox:id,name', 'bank:id,name', 'createdBy:id,name'])->findOrFail((int) $document);

        return response()->view('operations.money-document-print', ['kind' => $kind, 'document' => $record, 'company' => $company, 'locale' => app()->getLocale(), 'printBackgroundUrl' => $company->print_background_path ? Storage::disk('public')->url($company->print_background_path) : null]);
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
