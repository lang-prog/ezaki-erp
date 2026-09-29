<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BillRevision;
use App\Models\Company;
use App\Models\CustomerSupplier;
use App\Models\Driver;
use App\Models\FleetExpense;
use App\Models\JournalEntry;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\SalesBill;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationsService
{
    public function __construct(private readonly AccountingService $accounting, private readonly AuditRecorder $audit) {}

    public function savePurchase(Company $company, User $actor, array $data, bool $approve = false): PurchaseBill
    {
        return DB::transaction(function () use ($company, $actor, $data, $approve): PurchaseBill {
            $supplier = CustomerSupplier::query()->where('company_id', $company->id)->where('is_supplier', true)->findOrFail((int) $data['supplier_id']);
            $this->validateHeader($data, true);
            $this->duplicateCheck($company, 'purchase', $data, $data['id'] ?? null);
            $lines = $this->purchaseLines($company, $data['lines']);
            $totals = $this->purchaseTotals($lines, $data);
            $bill = isset($data['id']) ? PurchaseBill::query()->where('company_id', $company->id)->findOrFail($data['id']) : new PurchaseBill(['company_id' => $company->id, 'created_by' => $actor->id, 'internal_number' => $this->nextNumber($company, 'purchase_bills', 'PB')]);
            abort_if($bill->exists && $bill->status !== 'draft', 409, 'Only draft purchase bills can be edited.');
            $bill->fill([...$totals, 'branch_id' => $this->ownedId('branches', $company, (int) $data['branch_id']), 'warehouse_id' => $this->ownedId('warehouses', $company, (int) $data['warehouse_id']), 'supplier_id' => $supplier->id, 'supplier_bill_number' => $data['supplier_bill_number'], 'supplier_bill_date' => $data['supplier_bill_date'], 'warehouse_entry_date' => $data['warehouse_entry_date'] ?? null, 'vehicle_id' => $this->vehicleId($company, $data), 'external_vehicle_plate' => $data['external_vehicle_plate'] ?? null, 'external_driver_name' => $data['external_driver_name'] ?? null, 'notes' => $data['notes'] ?? null, 'status' => 'draft']);
            $bill->save();
            $bill->lines()->delete();
            foreach ($lines as $line) {
                $bill->lines()->create(['company_id' => $company->id, ...$line]);
            }
            $this->audit->record('purchase_bills.saved', $bill, $company->id, $actor->id);
            if ($approve) {
                return $this->approvePurchase($company, $actor, $bill);
            }

            return $bill->load('lines');
        });
    }

    public function saveSales(Company $company, User $actor, array $data, bool $approve = false): SalesBill
    {
        return DB::transaction(function () use ($company, $actor, $data, $approve): SalesBill {
            $customer = CustomerSupplier::query()->where('company_id', $company->id)->where('is_customer', true)->findOrFail((int) $data['customer_id']);
            $this->validateHeader($data, false);
            $this->duplicateCheck($company, 'sales', $data, $data['id'] ?? null);
            $lines = $this->salesLines($company, $data['lines']);
            $totals = $this->salesTotals($lines, $data);
            if ((float) ($totals['discount'] ?? 0) > 0 && ! $this->canDiscount($actor)) {
                throw ValidationException::withMessages(['discount' => 'Discounts require owner or accounts manager authorization.']);
            }
            $this->creditCheck($company, $customer, $data, $totals['total']);
            $bill = isset($data['id']) ? SalesBill::query()->where('company_id', $company->id)->findOrFail($data['id']) : new SalesBill(['company_id' => $company->id, 'created_by' => $actor->id, 'internal_number' => $this->nextNumber($company, 'sales_bills', 'SB')]);
            abort_if($bill->exists && $bill->status !== 'draft', 409, 'Only draft sales bills can be edited.');
            $bill->fill([...$totals, 'branch_id' => $this->ownedId('branches', $company, (int) $data['branch_id']), 'warehouse_id' => $this->ownedId('warehouses', $company, (int) $data['warehouse_id']), 'customer_id' => $customer->id, 'customer_bill_number' => $data['customer_bill_number'], 'bill_date' => $data['bill_date'], 'vehicle_id' => $this->vehicleId($company, $data), 'external_vehicle_plate' => $data['external_vehicle_plate'] ?? null, 'external_driver_name' => $data['external_driver_name'] ?? null, 'payment_method' => $data['payment_method'], 'due_date' => $data['due_date'] ?? null, 'notes' => $data['notes'] ?? null, 'status' => 'draft']);
            $bill->save();
            $bill->lines()->delete();
            foreach ($lines as $line) {
                $bill->lines()->create(['company_id' => $company->id, ...$line]);
            }
            $this->audit->record('sales_bills.saved', $bill, $company->id, $actor->id);
            if ($approve) {
                return $this->approveSales($company, $actor, $bill);
            }

            return $bill->load('lines');
        });
    }

    public function approvePurchase(Company $company, User $actor, PurchaseBill $bill): PurchaseBill
    {
        return DB::transaction(function () use ($company, $actor, $bill): PurchaseBill {
            $this->assertBill($company, $bill, 'draft');
            $bill->load('lines');
            foreach ($bill->lines as $line) {
                $this->adjustStock($company, $actor, $bill->warehouse_id, $line->product_id, (float) $line->factory_weight, PurchaseBill::class, $bill->id, 'purchase_in');
            }
            $entry = $this->accounting->postOperationalJournal($company, $actor, $bill->supplier_bill_date->toDateString(), 'Purchase '.$bill->internal_number, PurchaseBill::class, $bill->id, [['account_id' => $this->accountId($company, '4.1'), 'party_id' => $bill->supplier_id, 'debit' => $bill->total, 'credit' => 0, 'description' => 'Purchase value'], ['account_id' => $bill->supplier->account_id, 'party_id' => $bill->supplier_id, 'debit' => 0, 'credit' => $bill->total, 'description' => 'Supplier payable']]);
            $bill->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'journal_entry_id' => $entry->id])->save();
            $this->createTrip($company, $actor, $bill, 'purchase');
            $this->audit->record('purchase_bills.approved', $bill, $company->id, $actor->id, ['journal_entry_id' => $entry->id]);

            return $bill->fresh('lines');
        });
    }

    public function approveSales(Company $company, User $actor, SalesBill $bill): SalesBill
    {
        return DB::transaction(function () use ($company, $actor, $bill): SalesBill {
            $this->assertBill($company, $bill, 'draft');
            $bill->load('lines', 'customer');
            foreach ($bill->lines as $line) {
                $this->adjustStock($company, $actor, $bill->warehouse_id, $line->product_id, -(float) $line->actual_weight, SalesBill::class, $bill->id, 'sale_out');
            }
            $entry = $this->accounting->postOperationalJournal($company, $actor, $bill->bill_date->toDateString(), 'Sale '.$bill->internal_number, SalesBill::class, $bill->id, [['account_id' => $bill->customer->account_id, 'party_id' => $bill->customer_id, 'debit' => $bill->total, 'credit' => 0, 'description' => 'Customer receivable'], ['account_id' => $this->accountId($company, '5.1'), 'party_id' => $bill->customer_id, 'debit' => 0, 'credit' => $bill->total, 'description' => 'Sales revenue']]);
            $bill->forceFill(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'journal_entry_id' => $entry->id])->save();
            $this->createTrip($company, $actor, $bill, 'sales');
            $this->audit->record('sales_bills.approved', $bill, $company->id, $actor->id, ['journal_entry_id' => $entry->id]);

            return $bill->fresh('lines');
        });
    }

    public function reversePurchase(Company $company, User $actor, PurchaseBill $bill, string $date): PurchaseBill
    {
        return $this->reverse($company, $actor, $bill, PurchaseBill::class, $date, true);
    }

    public function reverseSales(Company $company, User $actor, SalesBill $bill, string $date): SalesBill
    {
        return $this->reverse($company, $actor, $bill, SalesBill::class, $date, false);
    }

    public function updateVehicle(Company $company, User $actor, Vehicle $vehicle, array $data): Vehicle
    {
        abort_unless((int) $vehicle->company_id === (int) $company->id, 404);
        $data['branch_id'] = $this->fleetRelationId('branches', $company, $data['branch_id'] ?? null, true);
        $vehicle->fill($data)->save();
        $this->audit->record('vehicles.updated', $vehicle, $company->id, $actor->id);

        return $vehicle->fresh();
    }

    public function updateDriver(Company $company, User $actor, Driver $driver, array $data): Driver
    {
        abort_unless((int) $driver->company_id === (int) $company->id, 404);
        $driver->fill($data)->save();
        $this->audit->record('drivers.updated', $driver, $company->id, $actor->id);

        return $driver->fresh();
    }

    public function updateExpense(Company $company, User $actor, FleetExpense $expense, array $data): FleetExpense
    {
        abort_unless((int) $expense->company_id === (int) $company->id, 404);
        abort_if($expense->status !== 'draft', 409, 'Only draft expenses can be edited.');
        $data = $this->expenseRelations($company, $data);
        $expense->fill($data)->save();
        $this->audit->record('fleet_expenses.updated', $expense, $company->id, $actor->id);

        return $expense->fresh();
    }

    public function updateMaintenance(Company $company, User $actor, MaintenanceRecord $record, array $data): MaintenanceRecord
    {
        abort_unless((int) $record->company_id === (int) $company->id, 404);
        abort_if($record->status !== 'draft', 409, 'Only draft maintenance records can be edited.');
        $data['vehicle_id'] = $this->fleetRelationId('vehicles', $company, $data['vehicle_id']);
        $record->fill($data)->save();
        $this->audit->record('maintenance.updated', $record, $company->id, $actor->id);

        return $record->fresh();
    }

    public function createManualTrip(Company $company, User $actor, array $data): Trip
    {
        $data = $this->tripRelations($company, $data);
        $trip = Trip::query()->create(['company_id' => $company->id, ...$data, 'status' => 'draft']);
        $this->audit->record('trips.created', $trip, $company->id, $actor->id);

        return $trip;
    }

    public function updateTrip(Company $company, User $actor, Trip $trip, array $data): Trip
    {
        abort_unless((int) $trip->company_id === (int) $company->id, 404);
        abort_if($trip->status === 'approved', 409, 'Approved trips cannot be edited.');
        $data = $this->tripRelations($company, $data);
        $trip->fill($data)->save();
        $this->audit->record('trips.updated', $trip, $company->id, $actor->id);

        return $trip->fresh();
    }

    public function reverseExpense(Company $company, User $actor, FleetExpense $expense, string $date): FleetExpense
    {
        return DB::transaction(function () use ($company, $actor, $expense, $date): FleetExpense {
            abort_unless((int) $expense->company_id === (int) $company->id, 404);
            abort_if($expense->status !== 'approved' || ! $expense->journal_entry_id, 409, 'Only approved expenses can be reversed.');
            $reversal = $this->accounting->reverseJournal($company, $actor, JournalEntry::query()->where('company_id', $company->id)->findOrFail($expense->journal_entry_id), $date);
            $expense->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('fleet_expenses.reversed', $expense, $company->id, $actor->id, ['reversal_entry_id' => $reversal->id]);

            return $expense->fresh();
        });
    }

    public function reverseMaintenance(Company $company, User $actor, MaintenanceRecord $record, string $date): MaintenanceRecord
    {
        return DB::transaction(function () use ($company, $actor, $record, $date): MaintenanceRecord {
            abort_unless((int) $record->company_id === (int) $company->id, 404);
            abort_if($record->status !== 'approved' || ! $record->journal_entry_id, 409, 'Only approved maintenance can be reversed.');
            $reversal = $this->accounting->reverseJournal($company, $actor, JournalEntry::query()->where('company_id', $company->id)->findOrFail($record->journal_entry_id), $date);
            $record->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('maintenance.reversed', $record, $company->id, $actor->id, ['reversal_entry_id' => $reversal->id]);

            return $record->fresh();
        });
    }

    public function revise(Company $company, User $actor, string $type, int $id, array $data): mixed
    {
        return DB::transaction(function () use ($company, $actor, $type, $id, $data): mixed {
            $bill = $type === 'purchase' ? PurchaseBill::query()->where('company_id', $company->id)->findOrFail($id) : SalesBill::query()->where('company_id', $company->id)->findOrFail($id);
            abort_if($bill->status !== 'approved', 409, 'Only approved bills can be revised.');
            $this->accountingPeriod($company, $type === 'purchase' ? $data['supplier_bill_date'] : $data['bill_date']);
            $before = $bill->load('lines')->toArray();
            if ($type === 'purchase') {
                $lines = $this->purchaseLines($company, $data['lines']);
                $totals = $this->purchaseTotals($lines, $data);
                $bill->fill([...$totals, 'notes' => $data['notes'] ?? null]);
            } else {
                $lines = $this->salesLines($company, $data['lines']);
                $totals = $this->salesTotals($lines, $data);
                if ((float) ($totals['discount'] ?? 0) > 0 && ! $this->canDiscount($actor)) {
                    throw ValidationException::withMessages(['discount' => 'Discounts require owner or accounts manager authorization.']);
                }
                $bill->fill([...$totals, 'notes' => $data['notes'] ?? null]);
            }
            $bill->save();
            $bill->lines()->delete();
            foreach ($lines as $line) {
                $bill->lines()->create(['company_id' => $company->id, ...$line]);
            }
            $updated = $bill->fresh('lines');
            $revision = BillRevision::query()->create(['company_id' => $company->id, 'document_type' => $type, 'document_id' => $id, 'user_id' => $actor->id, 'reason' => $data['revision_reason'], 'before_data' => $before, 'after_data' => $updated->toArray()]);
            $this->audit->record('bills.revised', $revision, $company->id, $actor->id, ['document_type' => $type, 'document_id' => $id]);

            return $updated;
        });
    }

    public function createVehicle(Company $company, User $actor, array $data): Vehicle
    {
        return DB::transaction(function () use ($company, $actor, $data): Vehicle {
            $data['branch_id'] = $this->fleetRelationId('branches', $company, $data['branch_id'] ?? null, true);
            $vehicle = Vehicle::query()->create(['company_id' => $company->id, ...$data]);
            $this->audit->record('vehicles.created', $vehicle, $company->id, $actor->id);

            return $vehicle;
        });
    }

    public function createDriver(Company $company, User $actor, array $data): Driver
    {
        $driver = Driver::query()->create(['company_id' => $company->id, ...$data]);
        $this->audit->record('drivers.created', $driver, $company->id, $actor->id);

        return $driver;
    }

    public function createExpense(Company $company, User $actor, array $data, bool $approve = false): FleetExpense
    {
        $data = $this->expenseRelations($company, $data);
        $expense = FleetExpense::query()->create(['company_id' => $company->id, ...$data, 'status' => 'draft']);
        if ($approve) {
            return $this->approveExpense($company, $actor, $expense);
        }

        return $expense;
    }

    public function approveExpense(Company $company, User $actor, FleetExpense $expense): FleetExpense
    {
        return DB::transaction(function () use ($company, $actor, $expense): FleetExpense {
            abort_unless((int) $expense->company_id === (int) $company->id, 404);
            abort_if($expense->status !== 'draft', 409);
            $entry = $this->accounting->postOperationalJournal($company, $actor, $expense->expense_date->toDateString(), 'Fleet expense '.$expense->category, FleetExpense::class, $expense->id, [['account_id' => $this->accountId($company, '4.2'), 'debit' => $expense->amount, 'credit' => 0, 'description' => $expense->category], ['account_id' => $this->accountId($company, '1.2'), 'debit' => 0, 'credit' => $expense->amount, 'description' => 'Fleet expense payment']]);
            $expense->forceFill(['status' => 'approved', 'journal_entry_id' => $entry->id])->save();
            $this->audit->record('fleet_expenses.approved', $expense, $company->id, $actor->id, ['journal_entry_id' => $entry->id]);

            return $expense;
        });
    }

    public function createMaintenance(Company $company, User $actor, array $data, bool $approve = false): MaintenanceRecord
    {
        $data['vehicle_id'] = $this->fleetRelationId('vehicles', $company, $data['vehicle_id']);
        $record = MaintenanceRecord::query()->create(['company_id' => $company->id, ...$data, 'status' => 'draft']);
        if ($approve) {
            return $this->approveMaintenance($company, $actor, $record);
        }

        return $record;
    }

    public function approveMaintenance(Company $company, User $actor, MaintenanceRecord $record): MaintenanceRecord
    {
        return DB::transaction(function () use ($company, $actor, $record): MaintenanceRecord {
            abort_unless((int) $record->company_id === (int) $company->id, 404);
            abort_if($record->status !== 'draft', 409);
            $entry = $this->accounting->postOperationalJournal($company, $actor, $record->starts_on->toDateString(), 'Vehicle maintenance '.$record->issue, MaintenanceRecord::class, $record->id, [['account_id' => $this->accountId($company, '4.2'), 'debit' => $record->cost, 'credit' => 0, 'description' => 'Maintenance'], ['account_id' => $this->accountId($company, '1.2'), 'debit' => 0, 'credit' => $record->cost, 'description' => 'Maintenance payment']]);
            $record->forceFill(['status' => 'approved', 'journal_entry_id' => $entry->id])->save();
            $this->audit->record('maintenance.approved', $record, $company->id, $actor->id, ['journal_entry_id' => $entry->id]);

            return $record;
        });
    }

    public function nearDuplicates(Company $company, string $type, int $partyId, string $date, ?int $ignore = null): array
    {
        $table = $type === 'purchase' ? 'purchase_bills' : 'sales_bills';
        $party = $type === 'purchase' ? 'supplier_id' : 'customer_id';
        $query = DB::table($table)->where('company_id', $company->id)->where($party, $partyId)->whereBetween($type === 'purchase' ? 'supplier_bill_date' : 'bill_date', [date('Y-m-d', strtotime($date.' -7 days')), date('Y-m-d', strtotime($date.' +7 days'))]);
        if ($ignore) {
            $query->where('id', '<>', $ignore);
        }

        return $query->orderByDesc('id')->limit(5)->get(['id', 'internal_number', $type === 'purchase' ? 'supplier_bill_number' : 'customer_bill_number', 'status'])->all();
    }

    public function transportAllocation(Company $company, string $type, int $id): array
    {
        $bill = $type === 'purchase' ? PurchaseBill::query()->where('company_id', $company->id)->with('lines')->findOrFail($id) : SalesBill::query()->where('company_id', $company->id)->with('lines')->findOrFail($id);
        $weight = $type === 'purchase' ? (float) $bill->total_factory_weight : (float) $bill->total_actual_weight;
        $extras = (float) $bill->transport_cost + (float) $bill->loading_cost + (float) $bill->extra_cost;

        return ['total_extra_cost' => $extras, 'basis_weight' => $weight, 'lines' => $bill->lines->map(fn ($line): array => ['line_id' => $line->id, 'weight' => (float) ($type === 'purchase' ? $line->factory_weight : $line->actual_weight), 'allocated_extra_cost' => $weight > 0 ? round($extras * (float) ($type === 'purchase' ? $line->factory_weight : $line->actual_weight) / $weight, 2) : 0])->all()];
    }

    private function validateHeader(array $data, bool $purchase): void
    {
        throw_if(empty($data['lines']), ValidationException::withMessages(['lines' => 'At least one bill line is required.']));
        if (! $purchase && ($data['payment_method'] ?? null) === 'credit') {
            throw_if(empty($data['due_date']), ValidationException::withMessages(['due_date' => 'A due date is required for credit sales.']));
        }
    }

    private function purchaseLines(Company $company, array $lines): array
    {
        return collect($lines)->map(function (array $line) use ($company): array {
            $product = $this->lineProduct($company, $line);
            $factory = (float) $line['factory_weight'];
            $price = (float) $line['unit_price'];

            return ['product_id' => $product->id, 'factory_weight' => $factory, 'actual_weight' => null, 'packages' => (float) ($line['packages'] ?? 0), 'unit_price' => $price, 'line_total' => round($factory * $price, 2), 'notes' => $line['notes'] ?? null];
        })->all();
    }

    private function salesLines(Company $company, array $lines): array
    {
        return collect($lines)->map(function (array $line) use ($company): array {
            $product = $this->lineProduct($company, $line);
            $weight = (float) $line['actual_weight'];
            $price = (float) $line['unit_price'];

            return ['product_id' => $product->id, 'actual_weight' => $weight, 'packages' => isset($line['packages']) && $line['packages'] !== '' ? (float) $line['packages'] : null, 'unit_price' => $price, 'line_total' => round($weight * $price, 2), 'notes' => $line['notes'] ?? null];
        })->all();
    }

    private function purchaseTotals(array $lines, array $data): array
    {
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $this->match($data, 'total_factory_weight', array_sum(array_column($lines, 'factory_weight')));
        $this->match($data, 'total_packages', array_sum(array_column($lines, 'packages')));

        return $this->totals($data, $subtotal) + ['total_factory_weight' => (float) $data['total_factory_weight'], 'total_actual_weight' => isset($data['total_actual_weight']) ? (float) $data['total_actual_weight'] : null, 'total_packages' => (float) $data['total_packages']];
    }

    private function salesTotals(array $lines, array $data): array
    {
        $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
        $this->match($data, 'total_actual_weight', array_sum(array_column($lines, 'actual_weight')));

        return $this->totals($data, $subtotal) + ['total_actual_weight' => (float) $data['total_actual_weight']];
    }

    private function totals(array $data, float $subtotal): array
    {
        $discount = (float) ($data['discount'] ?? 0);
        $extras = (float) ($data['transport_cost'] ?? 0) + (float) ($data['loading_cost'] ?? 0) + (float) ($data['extra_cost'] ?? 0);
        $base = round($subtotal - $discount + $extras, 2);
        $vat = round($base * (float) ($data['vat_rate'] ?? 0) / 100, 2);

        return ['subtotal' => $subtotal, 'discount' => $discount, 'transport_cost' => (float) ($data['transport_cost'] ?? 0), 'loading_cost' => (float) ($data['loading_cost'] ?? 0), 'extra_cost' => (float) ($data['extra_cost'] ?? 0), 'vat_rate' => $data['vat_rate'] ?? null, 'vat_amount' => $vat, 'total' => round($base + $vat, 2)];
    }

    private function match(array $data, string $field, float $expected): void
    {
        throw_if(abs((float) ($data[$field] ?? 0) - $expected) > 0.001, ValidationException::withMessages([$field => 'Header total does not match line totals.']));
    }

    private function duplicateCheck(Company $company, string $type, array $data, mixed $ignore): void
    {
        $table = $type === 'purchase' ? 'purchase_bills' : 'sales_bills';
        $column = $type === 'purchase' ? 'supplier_bill_number' : 'customer_bill_number';
        $query = DB::table($table)->where('company_id', $company->id)->where($column, $data[$column]);
        if ($ignore) {
            $query->where('id', '<>', $ignore);
        } throw_if($query->exists(), ValidationException::withMessages([$column => 'Duplicate bill number.']));
    }

    private function creditCheck(Company $company, CustomerSupplier $customer, array $data, float $total): void
    {
        if (($data['payment_method'] ?? null) !== 'credit' || $customer->credit_limit === null) {
            return;
        } $balance = (float) $customer->opening_balance + (float) DB::table('journal_lines')->where('company_id', $company->id)->where('party_id', $customer->id)->sum(DB::raw('debit-credit'));
        throw_if($balance + $total > (float) $customer->credit_limit, ValidationException::withMessages(['customer_id' => 'Customer credit limit exceeded.']));
    }

    private function adjustStock(Company $company, User $actor, int $warehouseId, int $productId, float $delta, string $source, int $sourceId, string $type): void
    {
        $balance = StockBalance::query()->where('company_id', $company->id)->where('warehouse_id', $this->ownedId('warehouses', $company, $warehouseId))->where('product_id', $productId)->lockForUpdate()->first();
        if (! $balance) {
            $balance = StockBalance::query()->create(['company_id' => $company->id, 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 0]);
        } throw_if((float) $balance->quantity + $delta < 0, ValidationException::withMessages(['lines' => 'Insufficient stock for sale.']));
        $balance->increment('quantity', $delta);
        StockMovement::query()->create(['company_id' => $company->id, 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'user_id' => $actor->id, 'movement_type' => $type, 'quantity_delta' => $delta, 'source_type' => $source, 'source_id' => $sourceId]);
    }

    private function reverse(Company $company, User $actor, mixed $bill, string $class, string $date, bool $purchase): mixed
    {
        return DB::transaction(function () use ($company, $actor, $bill, $class, $date, $purchase) {
            $this->assertBill($company, $bill, 'approved');
            $bill->load('lines');
            foreach ($bill->lines as $line) {
                $this->adjustStock($company, $actor, $bill->warehouse_id, $line->product_id, $purchase ? -(float) $line->factory_weight : (float) $line->actual_weight, $class, $bill->id, $purchase ? 'purchase_reverse' : 'sale_reverse');
            } $reversal = $this->accounting->reverseJournal($company, $actor, JournalEntry::query()->where('company_id', $company->id)->findOrFail($bill->journal_entry_id), $date);
            $bill->forceFill(['status' => 'reversed', 'reversal_journal_entry_id' => $reversal->id, 'reversed_by' => $actor->id, 'cancelled_at' => now()])->save();
            $this->audit->record('bills.reversed', $bill, $company->id, $actor->id, ['reversal_journal_entry_id' => $reversal->id]);

            return $bill->fresh('lines');
        });
    }

    private function assertBill(Company $company, mixed $bill, string $status): void
    {
        abort_unless((int) $bill->company_id === (int) $company->id, 404);
        abort_if($bill->status !== $status, 409, 'Invalid bill status.');
    }

    private function accountId(Company $company, string $code): int
    {
        return (int) DB::table('accounts')->where('company_id', $company->id)->where('code', $code)->value('id');
    }

    private function lineProduct(Company $company, array $line): Product
    {
        if (! empty($line['product_id'])) {
            return Product::query()->where('company_id', $company->id)->whereNull('archived_at')->findOrFail($line['product_id']);
        }

        $typeId = (int) ($line['product_type_id'] ?? 0);
        $diameterId = (int) ($line['diameter_id'] ?? 0);
        $type = DB::table('product_types')->where('company_id', $company->id)->where('id', $typeId)->first();
        $diameter = DB::table('diameters')->where('company_id', $company->id)->where('id', $diameterId)->first();
        abort_unless($type && $diameter, 422, 'Type and diameter are required for steel lines.');

        return Product::query()->firstOrCreate(
            ['company_id' => $company->id, 'product_type_id' => $typeId, 'diameter_id' => $diameterId, 'name' => $type->name.' '.$diameter->millimeters.'mm'],
            ['sku' => 'INTERNAL-'.$typeId.'-'.$diameterId, 'unit' => 'ton', 'minimum_stock' => 0, 'status' => 'active'],
        );
    }

    private function ownedId(string $table, Company $company, int $id): int
    {
        return (int) DB::table($table)->where('company_id', $company->id)->whereNull('archived_at')->where('id', $id)->value('id') ?: abort(404);
    }

    private function vehicleId(Company $company, array $data): ?int
    {
        return empty($data['vehicle_id']) ? null : (int) Vehicle::query()->where('company_id', $company->id)->where('status', 'active')->findOrFail((int) $data['vehicle_id'])->id;
    }

    private function tripRelations(Company $company, array $data): array
    {
        $data['vehicle_id'] = $this->fleetRelationId('vehicles', $company, $data['vehicle_id']);
        $data['driver_id'] = $this->fleetRelationId('drivers', $company, $data['driver_id'] ?? null, true);
        $data['branch_id'] = $this->fleetRelationId('branches', $company, $data['branch_id'] ?? null, true);

        return $data;
    }

    private function expenseRelations(Company $company, array $data): array
    {
        $data['vehicle_id'] = $this->fleetRelationId('vehicles', $company, $data['vehicle_id']);
        $data['trip_id'] = $this->fleetRelationId('trips', $company, $data['trip_id'] ?? null, true);
        if ($data['trip_id']) {
            $tripVehicle = (int) DB::table('trips')->where('id', $data['trip_id'])->value('vehicle_id');
            abort_unless($tripVehicle === (int) $data['vehicle_id'], 422, 'The selected trip does not belong to the selected vehicle.');
        }

        return $data;
    }

    private function fleetRelationId(string $table, Company $company, mixed $id, bool $nullable = false): ?int
    {
        if ($nullable && ($id === null || $id === '')) {
            return null;
        }

        $query = DB::table($table)->where('company_id', $company->id)->where('id', (int) $id);
        if ($table === 'branches') {
            $query->whereNull('archived_at');
        }

        return ($resolved = $query->value('id')) ? (int) $resolved : abort(404);
    }

    private function createTrip(Company $company, User $actor, mixed $bill, string $type): void
    {
        if (! $bill->vehicle_id) {
            return;
        } $trip = Trip::query()->create(['company_id' => $company->id, 'vehicle_id' => $bill->vehicle_id, 'branch_id' => $bill->branch_id, 'trip_type' => $type, 'trip_at' => now(), 'bill_type' => $type, 'bill_id' => $bill->id, 'revenue' => $type === 'sales' ? $bill->total : 0, 'status' => 'approved']);
        $this->audit->record('trips.created_from_bill', $trip, $company->id, $actor->id);
    }

    private function nextNumber(Company $company, string $table, string $prefix): string
    {
        return sprintf('%s-%s-%06d', $prefix, now()->format('Y'), DB::table($table)->where('company_id', $company->id)->lockForUpdate()->count() + 1);
    }

    private function accountingPeriod(Company $company, string $date): void
    {
        app(AccountingFoundationService::class)->openPeriodFor($company, $date);
    }

    private function canDiscount(User $actor): bool
    {
        return $actor->hasRole('Company Owner') || $actor->hasRole('Accounts Manager') || $actor->can('sales.discount');
    }
}
