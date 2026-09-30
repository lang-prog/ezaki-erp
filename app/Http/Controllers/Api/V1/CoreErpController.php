<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\Diameter;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Warehouse;
use App\Services\AccountingFoundationService;
use App\Services\AccountingReportService;
use App\Services\AccountingService;
use App\Services\AuditRecorder;
use App\Services\CompanyStructureService;
use App\Services\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CoreErpController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->only([
            'id', 'name', 'first_name', 'second_name', 'email', 'account_type', 'company_id', 'status',
        ])]);
    }

    public function dashboard(Request $request, AccountingReportService $reports): JsonResponse
    {
        $company = $this->company($request);
        $companyId = (int) $company->id;
        $from = now()->startOfMonth()->toDateString();
        $to = now()->endOfMonth()->toDateString();
        $debtors = $reports->debtors($company);

        return response()->json(['data' => [
            'metrics' => [
                'branches' => Branch::query()->where('company_id', $companyId)->whereNull('archived_at')->count(),
                'warehouses' => Warehouse::query()->where('company_id', $companyId)->whereNull('archived_at')->count(),
                'parties' => CustomerSupplier::query()->where('company_id', $companyId)->where('status', 'active')->count(),
                'debtors' => $debtors['total_outstanding'],
                'debtor_count' => $debtors['rows']->total(),
                'monthly_sales' => DB::table('sales_bills')->where('company_id', $companyId)->where('status', 'approved')->whereBetween('bill_date', [$from, $to])->sum('total'),
                'monthly_purchases' => DB::table('purchase_bills')->where('company_id', $companyId)->where('status', 'approved')->whereBetween('supplier_bill_date', [$from, $to])->sum('total'),
            ],
            'daily_sales' => DB::table('sales_bills')->where('company_id', $companyId)->where('status', 'approved')->whereBetween('bill_date', [$from, $to])->select('bill_date', DB::raw('SUM(total) as total'))->groupBy('bill_date')->orderBy('bill_date')->get(),
            'payments_receipts' => [
                'payments' => (float) DB::table('payment_vouchers')->where('company_id', $companyId)->where('status', 'posted')->whereBetween('voucher_date', [$from, $to])->sum('amount'),
                'receipts' => (float) DB::table('receipts')->where('company_id', $companyId)->where('status', 'posted')->whereBetween('receipt_date', [$from, $to])->sum('amount'),
            ],
            'top_sold_products' => DB::table('sales_bill_lines')->join('sales_bills', 'sales_bills.id', '=', 'sales_bill_lines.sales_bill_id')->join('products', 'products.id', '=', 'sales_bill_lines.product_id')->where('sales_bills.company_id', $companyId)->where('sales_bills.status', 'approved')->whereBetween('sales_bills.bill_date', [$from, $to])->select('products.name', DB::raw('SUM(sales_bill_lines.actual_weight) as quantity'))->groupBy('products.id', 'products.name')->orderByDesc('quantity')->limit(5)->get(),
            'sales_by_customer' => DB::table('sales_bills')->join('customer_suppliers', 'customer_suppliers.id', '=', 'sales_bills.customer_id')->where('sales_bills.company_id', $companyId)->where('sales_bills.status', 'approved')->whereBetween('sales_bills.bill_date', [$from, $to])->select('customer_suppliers.name', DB::raw('SUM(sales_bills.total) as total'))->groupBy('customer_suppliers.id', 'customer_suppliers.name')->orderByDesc('total')->limit(5)->get(),
        ]]);
    }

    public function branches(Request $request): JsonResponse
    {
        return response()->json(['data' => Branch::query()->where('company_id', $this->company($request)->id)->whereNull('archived_at')->with('warehouses')->orderBy('name')->paginate(25)]);
    }

    public function createBranch(Request $request, CompanyStructureService $structure): JsonResponse
    {
        $companyId = (int) $this->company($request)->id;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('branches')->where('company_id', $companyId)], 'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:2000'],
            'warehouse_code' => ['nullable', 'string', 'max:40', Rule::unique('warehouses')->where('company_id', $companyId)], 'warehouse_name' => ['nullable', 'string', 'max:255'],
        ]);
        $branch = $structure->createBranch($this->company($request), $request->user(), $data);

        return response()->json(['data' => $branch], 201);
    }

    public function updateBranch(Request $request, Branch $branch, CompanyStructureService $structure): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'address' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $structure->updateBranch($this->company($request), $branch, $request->user(), $data)]);
    }

    public function archiveBranch(Request $request, Branch $branch, CompanyStructureService $structure): JsonResponse
    {
        $structure->archiveBranch($this->company($request), $branch, $request->user());

        return response()->json(['message' => 'Branch and its warehouses archived.']);
    }

    public function warehouses(Request $request): JsonResponse
    {
        return response()->json(['data' => Warehouse::query()->where('company_id', $this->company($request)->id)->whereNull('archived_at')->with('branch:id,name')->orderBy('name')->paginate(25)]);
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse, CompanyStructureService $structure): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'details' => ['nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $structure->updateWarehouse($this->company($request), $warehouse, $request->user(), $data)]);
    }

    public function archiveWarehouse(Request $request, Warehouse $warehouse, CompanyStructureService $structure): JsonResponse
    {
        $structure->archiveWarehouse($this->company($request), $warehouse, $request->user());

        return response()->json(['message' => 'Warehouse archived.']);
    }

    public function productTypes(Request $request): JsonResponse
    {
        return response()->json(['data' => ProductType::query()->where('company_id', $this->company($request)->id)->where('status', 'active')->orderBy('name')->get()]);
    }

    public function createProductType(Request $request, AuditRecorder $audit): JsonResponse
    {
        $companyId = (int) $this->company($request)->id;
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('product_types')->where('company_id', $companyId)], 'kind' => ['nullable', 'in:steel,other']]);
        $data['kind'] ??= 'steel';
        $type = ProductType::query()->create(['company_id' => $companyId, ...$data, 'status' => 'active']);
        $audit->record('product_types.created', $type, $type->company_id, $request->user()->id, [], $request);

        return response()->json(['data' => $type], 201);
    }

    public function updateProductType(Request $request, ProductType $productType, AuditRecorder $audit): JsonResponse
    {
        abort_unless((int) $productType->company_id === (int) $this->company($request)->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique('product_types')->where('company_id', $productType->company_id)->ignore($productType->id)], 'kind' => ['required', 'in:steel,other'], 'status' => ['required', 'in:active,archived']]);
        $productType->forceFill($data)->save();
        $audit->record('product_types.updated', $productType, $productType->company_id, $request->user()->id, ['fields' => array_keys($data)], $request);

        return response()->json(['data' => $productType]);
    }

    public function diameters(Request $request): JsonResponse
    {
        $company = $this->company($request);
        app(InventoryService::class)->ensureStandardDiameters($company);

        return response()->json(['data' => Diameter::query()->where('company_id', $company->id)->orderBy('millimeters')->get()]);
    }

    public function createDiameter(Request $request, AuditRecorder $audit): JsonResponse
    {
        $companyId = (int) $this->company($request)->id;
        $data = $request->validate(['millimeters' => ['required', 'numeric', 'gt:0', 'max:1000', Rule::unique('diameters')->where('company_id', $companyId)]]);
        $diameter = Diameter::query()->create(['company_id' => $this->company($request)->id, 'millimeters' => $data['millimeters']]);
        $audit->record('diameters.created', $diameter, $diameter->company_id, $request->user()->id, [], $request);

        return response()->json(['data' => $diameter], 201);
    }

    public function updateDiameter(Request $request, Diameter $diameter, AuditRecorder $audit): JsonResponse
    {
        abort_unless((int) $diameter->company_id === (int) $this->company($request)->id, 404);
        abort_if(Product::query()->where('company_id', $diameter->company_id)->where('diameter_id', $diameter->id)->exists(), 409, 'A diameter referenced by products cannot be changed.');
        $data = $request->validate(['millimeters' => ['required', 'numeric', 'gt:0', 'max:1000', Rule::unique('diameters')->where('company_id', $diameter->company_id)->ignore($diameter->id)]]);
        $diameter->forceFill($data)->save();
        $audit->record('diameters.updated', $diameter, $diameter->company_id, $request->user()->id, [], $request);

        return response()->json(['data' => $diameter]);
    }

    public function products(Request $request): JsonResponse
    {
        return response()->json(['data' => Product::query()->where('company_id', $this->company($request)->id)->whereNull('archived_at')->with(['type:id,name', 'diameter:id,millimeters'])->orderBy('name')->paginate(25)]);
    }

    public function createProduct(Request $request, InventoryService $inventory): JsonResponse
    {
        $companyId = (int) $this->company($request)->id;
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'product_type_id' => ['nullable', 'integer'],
            'diameter_id' => ['nullable', 'integer'], 'sku' => ['required', 'string', 'max:80', Rule::unique('products')->where('company_id', $companyId)],
            'name' => ['required', 'string', 'max:255'], 'unit' => ['required', 'in:ton,kilogram'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'], 'opening_balance' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(['data' => $inventory->createProduct($this->company($request), $request->user(), $data)], 201);
    }

    public function saveTypeDiameterProfile(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'], 'product_type_id' => ['required', 'integer'], 'diameter_id' => ['required', 'integer'],
            'opening_balance' => ['nullable', 'numeric', 'min:0'], 'minimum_stock' => ['nullable', 'numeric', 'min:0'],
        ]);

        return response()->json(['data' => $inventory->saveTypeDiameterProfile($this->company($request), $request->user(), $data)], 201);
    }

    public function updateProduct(Request $request, Product $product, AuditRecorder $audit): JsonResponse
    {
        abort_unless((int) $product->company_id === (int) $this->company($request)->id, 404);
        $data = $request->validate([
            'product_type_id' => ['nullable', 'integer'], 'diameter_id' => ['nullable', 'integer'],
            'sku' => ['required', 'string', 'max:80', Rule::unique('products')->where('company_id', $product->company_id)->ignore($product->id)], 'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'in:ton,kilogram'], 'minimum_stock' => ['required', 'numeric', 'min:0'],
        ]);
        if ($data['product_type_id']) {
            abort_unless(DB::table('product_types')->where('company_id', $product->company_id)->where('id', $data['product_type_id'])->exists(), 404);
        }
        if ($data['diameter_id']) {
            abort_unless(Diameter::query()->where('company_id', $product->company_id)->whereKey($data['diameter_id'])->exists(), 404);
        }
        $product->forceFill($data)->save();
        $audit->record('products.updated', $product, $product->company_id, $request->user()->id, ['fields' => array_keys($data)], $request);

        return response()->json(['data' => $product->fresh(['type', 'diameter'])]);
    }

    public function archiveProduct(Request $request, Product $product, AuditRecorder $audit): JsonResponse
    {
        abort_unless((int) $product->company_id === (int) $this->company($request)->id, 404);
        abort_if(DB::table('stock_balances')->where('company_id', $product->company_id)->where('product_id', $product->id)->where('quantity', '!=', 0)->exists(), 409, 'A product with stock cannot be archived.');
        $product->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
        $audit->record('products.archived', $product, $product->company_id, $request->user()->id, [], $request);

        return response()->json(['message' => 'Product archived.']);
    }

    public function inventoryMatrix(Request $request, InventoryService $inventory): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['nullable', 'integer']]);

        $warehouseId = isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null;

        return response()->json($inventory->matrix($this->company($request), $warehouseId));
    }

    public function transfers(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('stock_transfers')->where('company_id', $this->company($request)->id)->orderByDesc('transferred_at')->paginate(25)]);
    }

    public function createTransfer(Request $request, InventoryService $inventory): JsonResponse
    {
        $companyId = (int) $this->company($request)->id;
        $data = $request->validate([
            'from_warehouse_id' => ['required', 'integer'], 'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id'],
            'transfer_number' => ['required', 'string', 'max:80', Rule::unique('stock_transfers')->where('company_id', $companyId)], 'transferred_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        return response()->json(['data' => $inventory->transfer($this->company($request), $request->user(), $data)], 201);
    }

    public function parties(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('customer_suppliers')->where('company_id', $this->company($request)->id)->where('status', 'active')->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->toString().'%'))->orderBy('name')->paginate(25)]);
    }

    public function createParty(Request $request, AccountingService $accounting, AccountingFoundationService $foundation): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'parent_account_id' => ['required', 'integer'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
            'is_customer' => ['required', 'boolean'], 'is_supplier' => ['required', 'boolean'],
            'opening_balance' => ['nullable', 'numeric'], 'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);
        abort_unless($data['is_customer'] || $data['is_supplier'], 422, 'Select customer, supplier, or both.');
        $foundation->seedCompany($this->company($request));

        return response()->json(['data' => $accounting->createParty($this->company($request), $request->user(), $data)], 201);
    }

    public function updateParty(Request $request, CustomerSupplier $party, AccountingService $accounting): JsonResponse
    {
        abort_unless((int) $party->company_id === (int) $this->company($request)->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'parent_account_id' => ['required', 'integer'],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:255'], 'address' => ['nullable', 'string', 'max:2000'],
            'is_customer' => ['required', 'boolean'], 'is_supplier' => ['required', 'boolean'],
            'opening_balance' => ['nullable', 'numeric'], 'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);
        abort_unless($data['is_customer'] || $data['is_supplier'], 422, 'Select customer, supplier, or both.');

        return response()->json(['data' => $accounting->updateParty($this->company($request), $party, $request->user(), $data)]);
    }

    public function accounts(Request $request): JsonResponse
    {
        $company = $this->company($request);
        app(AccountingFoundationService::class)->seedCompany($company);

        return response()->json(['data' => Account::query()->where('company_id', $company->id)->with('parent:id,code,name')->orderBy('code')->get()]);
    }

    public function createAccount(Request $request, AccountingFoundationService $foundation, AuditRecorder $audit): JsonResponse
    {
        $data = $request->validate(['parent_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255']]);
        $parent = Account::query()->where('company_id', $this->company($request)->id)->findOrFail((int) $data['parent_id']);

        $account = $foundation->createChild($this->company($request), $parent, $data['name']);
        $audit->record('accounts.created', $account, $account->company_id, $request->user()->id, ['code' => $account->code], $request);

        return response()->json(['data' => $account], 201);
    }

    public function updateAccount(Request $request, Account $account, AuditRecorder $audit): JsonResponse
    {
        abort_unless((int) $account->company_id === (int) $this->company($request)->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'parent_id' => ['nullable', 'integer']]);
        if (array_key_exists('parent_id', $data) && (int) $account->parent_id !== (int) $data['parent_id']) {
            $parent = $data['parent_id'] === null ? null : Account::query()->where('company_id', $account->company_id)->findOrFail((int) $data['parent_id']);
            app(AccountingFoundationService::class)->moveAccount($this->company($request), $account, $parent);
        }
        $account->forceFill(['name' => $data['name']])->save();
        $audit->record('accounts.updated', $account, $account->company_id, $request->user()->id, ['code' => $account->code, 'parent_id' => $account->parent_id], $request);

        return response()->json(['data' => $account]);
    }

    public function cashboxes(Request $request): JsonResponse
    {
        return response()->json(['data' => Cashbox::query()->where('company_id', $this->company($request)->id)->where('is_active', true)->with('account:id,code')->get()]);
    }

    public function createCashbox(Request $request, AccountingService $accounting, AccountingFoundationService $foundation): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $foundation->seedCompany($this->company($request));

        return response()->json(['data' => $accounting->createCashbox($this->company($request), $request->user(), $data['name'])], 201);
    }

    public function banks(Request $request): JsonResponse
    {
        return response()->json(['data' => Bank::query()->where('company_id', $this->company($request)->id)->where('is_active', true)->with('account:id,code')->get()]);
    }

    public function createBank(Request $request, AccountingService $accounting, AccountingFoundationService $foundation): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'account_number' => ['nullable', 'string', 'max:100']]);
        $foundation->seedCompany($this->company($request));

        return response()->json(['data' => $accounting->createBank($this->company($request), $request->user(), $data['name'], $data['account_number'] ?? null)], 201);
    }

    public function receipts(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('receipts')->where('company_id', $this->company($request)->id)->orderByDesc('receipt_date')->paginate(25)]);
    }

    public function createReceipt(Request $request, AccountingService $accounting): JsonResponse
    {
        $data = $request->validate([
            'counterparty_type' => ['nullable', 'in:customer,other'], 'party_id' => ['required_without:other_account_id', 'nullable', 'integer'], 'other_account_id' => ['required_if:counterparty_type,other', 'nullable', 'integer'], 'cashbox_id' => ['nullable', 'integer'], 'bank_id' => ['nullable', 'integer'],
            'receipt_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'],
            'delivered_by' => ['nullable', 'string', 'max:255'], 'received_by' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $accounting->receive($this->company($request), $request->user(), $data)], 201);
    }

    public function vouchers(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('payment_vouchers')->where('company_id', $this->company($request)->id)->orderByDesc('voucher_date')->paginate(25)]);
    }

    public function createVoucher(Request $request, AccountingService $accounting): JsonResponse
    {
        $data = $request->validate([
            'counterparty_type' => ['nullable', 'in:supplier,other'], 'party_id' => ['required_without:other_account_id', 'nullable', 'integer'], 'other_account_id' => ['required_if:counterparty_type,other', 'nullable', 'integer'], 'cashbox_id' => ['nullable', 'integer'], 'bank_id' => ['nullable', 'integer'],
            'voucher_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'],
            'paid_by' => ['nullable', 'string', 'max:255'], 'received_by' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $accounting->paySupplier($this->company($request), $request->user(), $data)], 201);
    }

    public function periods(Request $request): JsonResponse
    {
        return response()->json(['data' => FiscalPeriod::query()->where('company_id', $this->company($request)->id)->orderByDesc('starts_on')->get()]);
    }

    public function createPeriod(Request $request, AccountingFoundationService $foundation): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);

        return response()->json(['data' => $foundation->createPeriod($this->company($request), $data['name'], $data['starts_on'], $data['ends_on'])], 201);
    }

    public function closePeriod(Request $request, FiscalPeriod $period, AccountingService $accounting): JsonResponse
    {
        return response()->json(['data' => $accounting->closePeriod($this->company($request), $period, $request->user())]);
    }

    public function reverseJournal(Request $request, JournalEntry $entry, AccountingService $accounting): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date']]);

        return response()->json(['data' => $accounting->reverseJournal($this->company($request), $request->user(), $entry, $data['date'])], 201);
    }

    public function report(Request $request, AccountingReportService $reports, string $report): JsonResponse
    {
        $range = $request->validate(['from_date' => ['nullable', 'date'], 'to_date' => ['nullable', 'date'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $company = $this->company($request);
        $from = $range['from_date'] ?? $range['from'] ?? null;
        $to = $range['to_date'] ?? $range['to'] ?? null;

        return response()->json(match ($report) {
            'journal' => $reports->journal($company, $from, $to),
            'ledger' => $reports->ledger($company, $from, $to),
            'account-statement' => $reports->accountStatement($company, Account::query()->where('company_id', $company->id)->findOrFail($request->integer('account_id')), $from, $to),
            'trial-balance' => $reports->trialBalance($company, $to),
            'income-statement' => $reports->incomeStatement($company, $from, $to),
            'balance-sheet' => $reports->balanceSheet($company, $to),
            'debtors' => $reports->debtors($company, 50, $from, $to),
            'receivables' => $reports->receivables($company, 0, $from, $to),
            'payables' => $reports->payables($company, 0, $from, $to),
            'creditors' => $reports->payables($company, 0, $from, $to),
            default => abort(404),
        });
    }

    public function accountStatement(Request $request, Account $account, AccountingReportService $reports): JsonResponse
    {
        $range = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return response()->json($reports->accountStatement($this->company($request), $account, $range['from'] ?? null, $range['to'] ?? null));
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }
}
