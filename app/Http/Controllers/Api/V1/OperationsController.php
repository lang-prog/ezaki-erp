<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Company;
use App\Models\Driver;
use App\Models\FleetExpense;
use App\Models\MaintenanceRecord;
use App\Models\PurchaseBill;
use App\Models\SalesBill;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Services\FleetReportService;
use App\Services\OperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OperationsController
{
    public function __construct(private readonly OperationsService $operations) {}

    public function purchases(Request $request): JsonResponse
    {
        return response()->json(['data' => PurchaseBill::query()->where('company_id', $this->company($request)->id)->with('lines')->latest()->paginate(25)]);
    }

    public function sales(Request $request): JsonResponse
    {
        return response()->json(['data' => SalesBill::query()->where('company_id', $this->company($request)->id)->with('lines')->latest()->paginate(25)]);
    }

    public function storePurchase(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->savePurchase($this->company($request), $request->user(), $this->purchaseData($request), false)], 201);
    }

    public function storeSales(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->operations->saveSales($this->company($request), $request->user(), $this->salesData($request), false)], 201);
    }

    public function purchase(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        $this->owned($purchaseBill, $request);

        return response()->json(['data' => $purchaseBill->load('lines')]);
    }

    public function salesBill(Request $request, SalesBill $salesBill): JsonResponse
    {
        $this->owned($salesBill, $request);

        return response()->json(['data' => $salesBill->load('lines')]);
    }

    public function updatePurchase(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        $this->owned($purchaseBill, $request);

        return response()->json(['data' => $this->operations->savePurchase($this->company($request), $request->user(), $this->purchaseData($request) + ['id' => $purchaseBill->id], false)]);
    }

    public function updateSales(Request $request, SalesBill $salesBill): JsonResponse
    {
        $this->owned($salesBill, $request);

        return response()->json(['data' => $this->operations->saveSales($this->company($request), $request->user(), $this->salesData($request) + ['id' => $salesBill->id], false)]);
    }

    public function purchaseWarnings(Request $request): JsonResponse
    {
        $data = $request->validate(['supplier_id' => ['required', 'integer'], 'supplier_bill_date' => ['required', 'date'], 'id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->operations->nearDuplicates($this->company($request), 'purchase', (int) $data['supplier_id'], $data['supplier_bill_date'], isset($data['id']) ? (int) $data['id'] : null)]);
    }

    public function salesWarnings(Request $request): JsonResponse
    {
        $data = $request->validate(['customer_id' => ['required', 'integer'], 'bill_date' => ['required', 'date'], 'id' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->operations->nearDuplicates($this->company($request), 'sales', (int) $data['customer_id'], $data['bill_date'], isset($data['id']) ? (int) $data['id'] : null)]);
    }

    public function allocation(Request $request, string $type, int $bill): JsonResponse
    {
        abort_unless(in_array($type, ['purchase', 'sales'], true), 404);

        return response()->json(['data' => $this->operations->transportAllocation($this->company($request), $type, $bill)]);
    }

    public function approvePurchase(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        $this->owned($purchaseBill, $request);

        return response()->json(['data' => $this->operations->approvePurchase($this->company($request), $request->user(), $purchaseBill)]);
    }

    public function approveSales(Request $request, SalesBill $salesBill): JsonResponse
    {
        $this->owned($salesBill, $request);

        return response()->json(['data' => $this->operations->approveSales($this->company($request), $request->user(), $salesBill)]);
    }

    public function reversePurchase(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        $this->owned($purchaseBill, $request);

        return response()->json(['data' => $this->operations->reversePurchase($this->company($request), $request->user(), $purchaseBill, $request->validate(['date' => ['required', 'date']])['date'])]);
    }

    public function reverseSales(Request $request, SalesBill $salesBill): JsonResponse
    {
        $this->owned($salesBill, $request);

        return response()->json(['data' => $this->operations->reverseSales($this->company($request), $request->user(), $salesBill, $request->validate(['date' => ['required', 'date']])['date'])]);
    }

    public function revisePurchase(Request $request, PurchaseBill $purchaseBill): JsonResponse
    {
        $this->owned($purchaseBill, $request);

        return response()->json(['data' => $this->operations->revise($this->company($request), $request->user(), 'purchase', $purchaseBill->id, $this->purchaseData($request) + ['revision_reason' => $request->input('revision_reason')])]);
    }

    public function reviseSales(Request $request, SalesBill $salesBill): JsonResponse
    {
        $this->owned($salesBill, $request);

        return response()->json(['data' => $this->operations->revise($this->company($request), $request->user(), 'sales', $salesBill->id, $this->salesData($request) + ['revision_reason' => $request->input('revision_reason')])]);
    }

    public function vehicles(Request $request): JsonResponse
    {
        return response()->json(['data' => Vehicle::query()->where('company_id', $this->company($request)->id)->latest()->paginate(25)]);
    }

    public function storeVehicle(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'plate' => ['required', 'string', 'max:50', Rule::unique('vehicles')->where('company_id', $company->id)], 'type' => ['nullable', 'string'], 'make_model' => ['nullable', 'string'], 'year' => ['nullable', 'integer'], 'ownership' => ['required', 'in:company,rented,external'], 'capacity' => ['nullable', 'numeric'], 'fuel_type' => ['nullable', 'string'], 'status' => ['required', 'in:active,inactive'], 'license_expires_at' => ['nullable', 'date'], 'insurance_expires_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->createVehicle($company, $request->user(), $data)], 201);
    }

    public function drivers(Request $request): JsonResponse
    {
        return response()->json(['data' => Driver::query()->where('company_id', $this->company($request)->id)->latest()->paginate(25)]);
    }

    public function storeDriver(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string'], 'phone' => ['nullable', 'string'], 'license_number' => ['nullable', 'string'], 'license_type' => ['nullable', 'string'], 'license_expires_at' => ['nullable', 'date'], 'status' => ['required', 'in:active,inactive'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->createDriver($this->company($request), $request->user(), $data)], 201);
    }

    public function trips(Request $request): JsonResponse
    {
        return response()->json(['data' => Trip::query()->where('company_id', $this->company($request)->id)->with('vehicle')->latest('trip_at')->paginate(25)]);
    }

    public function storeTrip(Request $request): JsonResponse
    {
        $data = $request->validate(['vehicle_id' => ['required', 'integer'], 'driver_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'], 'trip_type' => ['required', 'string'], 'origin' => ['nullable', 'string'], 'destination' => ['nullable', 'string'], 'trip_at' => ['required', 'date'], 'distance' => ['nullable', 'numeric'], 'odometer_in' => ['nullable', 'numeric'], 'odometer_out' => ['nullable', 'numeric'], 'fuel_cost' => ['nullable', 'numeric'], 'road_fees' => ['nullable', 'numeric'], 'loading_fees' => ['nullable', 'numeric'], 'revenue' => ['nullable', 'numeric'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->createManualTrip($this->company($request), $request->user(), $data)], 201);
    }

    public function updateTrip(Request $request, Trip $trip): JsonResponse
    {
        $this->owned($trip, $request);
        $data = $request->validate(['vehicle_id' => ['required', 'integer'], 'driver_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'], 'trip_type' => ['required', 'string'], 'origin' => ['nullable', 'string'], 'destination' => ['nullable', 'string'], 'trip_at' => ['required', 'date'], 'distance' => ['nullable', 'numeric'], 'odometer_in' => ['nullable', 'numeric'], 'odometer_out' => ['nullable', 'numeric'], 'fuel_cost' => ['nullable', 'numeric'], 'road_fees' => ['nullable', 'numeric'], 'loading_fees' => ['nullable', 'numeric'], 'revenue' => ['nullable', 'numeric'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->updateTrip($this->company($request), $request->user(), $trip, $data)]);
    }

    public function expenses(Request $request): JsonResponse
    {
        return response()->json(['data' => FleetExpense::query()->where('company_id', $this->company($request)->id)->latest()->paginate(25)]);
    }

    public function storeExpense(Request $request): JsonResponse
    {
        $data = $request->validate(['vehicle_id' => ['required', 'integer'], 'trip_id' => ['nullable', 'integer'], 'expense_date' => ['required', 'date'], 'category' => ['required', 'in:fuel,maintenance,parts,oil,tires,tolls,loading,driver_wages,insurance,licensing,fines,other'], 'amount' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->createExpense($this->company($request), $request->user(), $data, false)], 201);
    }

    public function approveExpense(Request $request, FleetExpense $expense): JsonResponse
    {
        $this->owned($expense, $request);

        return response()->json(['data' => $this->operations->approveExpense($this->company($request), $request->user(), $expense)]);
    }

    public function maintenance(Request $request): JsonResponse
    {
        return response()->json(['data' => MaintenanceRecord::query()->where('company_id', $this->company($request)->id)->latest()->paginate(25)]);
    }

    public function storeMaintenance(Request $request): JsonResponse
    {
        $data = $request->validate(['vehicle_id' => ['required', 'integer'], 'maintenance_type' => ['required', 'in:preventive,emergency'], 'issue' => ['required', 'string'], 'parts' => ['nullable', 'string'], 'labor_cost' => ['nullable', 'numeric'], 'parts_cost' => ['nullable', 'numeric'], 'vendor' => ['nullable', 'string'], 'cost' => ['required', 'numeric', 'gt:0'], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date'], 'next_due_on' => ['nullable', 'date'], 'vehicle_status' => ['required', 'in:in_service,out_of_service'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->createMaintenance($this->company($request), $request->user(), $data, false)], 201);
    }

    public function approveMaintenance(Request $request, MaintenanceRecord $record): JsonResponse
    {
        $this->owned($record, $request);

        return response()->json(['data' => $this->operations->approveMaintenance($this->company($request), $request->user(), $record)]);
    }

    public function updateVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->owned($vehicle, $request);
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'plate' => ['required', 'string'], 'ownership' => ['required', 'in:company,rented,external'], 'status' => ['required', 'in:active,inactive'], 'type' => ['nullable', 'string'], 'make_model' => ['nullable', 'string'], 'year' => ['nullable', 'integer'], 'capacity' => ['nullable', 'numeric'], 'fuel_type' => ['nullable', 'string'], 'license_expires_at' => ['nullable', 'date'], 'insurance_expires_at' => ['nullable', 'date'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->updateVehicle($this->company($request), $request->user(), $vehicle, $data)]);
    }

    public function updateDriver(Request $request, Driver $driver): JsonResponse
    {
        $this->owned($driver, $request);
        $data = $request->validate(['name' => ['required', 'string'], 'phone' => ['nullable', 'string'], 'license_number' => ['nullable', 'string'], 'license_type' => ['nullable', 'string'], 'license_expires_at' => ['nullable', 'date'], 'status' => ['required', 'in:active,inactive'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->updateDriver($this->company($request), $request->user(), $driver, $data)]);
    }

    public function updateExpense(Request $request, FleetExpense $expense): JsonResponse
    {
        $this->owned($expense, $request);
        $data = $request->validate(['vehicle_id' => ['required', 'integer'], 'trip_id' => ['nullable', 'integer'], 'expense_date' => ['required', 'date'], 'category' => ['required', 'in:fuel,maintenance,parts,oil,tires,tolls,loading,driver_wages,insurance,licensing,fines,other'], 'amount' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->updateExpense($this->company($request), $request->user(), $expense, $data)]);
    }

    public function updateMaintenance(Request $request, MaintenanceRecord $record): JsonResponse
    {
        $this->owned($record, $request);
        $data = $request->validate(['vehicle_id' => ['required', 'integer'], 'maintenance_type' => ['required', 'in:preventive,emergency'], 'issue' => ['required', 'string'], 'parts' => ['nullable', 'string'], 'labor_cost' => ['nullable', 'numeric'], 'parts_cost' => ['nullable', 'numeric'], 'vendor' => ['nullable', 'string'], 'cost' => ['required', 'numeric', 'gt:0'], 'starts_on' => ['required', 'date'], 'ends_on' => ['nullable', 'date'], 'next_due_on' => ['nullable', 'date'], 'vehicle_status' => ['required', 'in:in_service,out_of_service'], 'notes' => ['nullable', 'string']]);

        return response()->json(['data' => $this->operations->updateMaintenance($this->company($request), $request->user(), $record, $data)]);
    }

    public function reverseExpense(Request $request, FleetExpense $expense): JsonResponse
    {
        $this->owned($expense, $request);

        return response()->json(['data' => $this->operations->reverseExpense($this->company($request), $request->user(), $expense, $request->validate(['date' => ['required', 'date']])['date'])]);
    }

    public function reverseMaintenance(Request $request, MaintenanceRecord $record): JsonResponse
    {
        $this->owned($record, $request);

        return response()->json(['data' => $this->operations->reverseMaintenance($this->company($request), $request->user(), $record, $request->validate(['date' => ['required', 'date']])['date'])]);
    }

    public function report(Request $request, FleetReportService $reports, string $report): JsonResponse
    {
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'branch_id' => ['nullable', 'integer']]);
        $data = $reports->report($this->company($request), $report, $filters['from'] ?? null, $filters['to'] ?? null, isset($filters['branch_id']) ? (int) $filters['branch_id'] : null);

        return response()->json(['data' => $data]);
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }

    private function owned(mixed $model, Request $request): void
    {
        abort_unless((int) $model->company_id === (int) $this->company($request)->id, 404);
    }

    private function purchaseData(Request $request): array
    {
        return $request->validate(['branch_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'], 'supplier_id' => ['required', 'integer'], 'supplier_bill_number' => ['required', 'string', 'max:100'], 'supplier_bill_date' => ['required', 'date'], 'warehouse_entry_date' => ['nullable', 'date'], 'total_factory_weight' => ['required', 'numeric', 'gte:0'], 'total_actual_weight' => ['nullable', 'numeric', 'gte:0'], 'total_packages' => ['required', 'numeric', 'gte:0'], 'transport_cost' => ['nullable', 'numeric', 'gte:0'], 'loading_cost' => ['nullable', 'numeric', 'gte:0'], 'extra_cost' => ['nullable', 'numeric', 'gte:0'], 'discount' => ['nullable', 'numeric', 'gte:0'], 'vat_rate' => ['nullable', 'numeric', 'gte:0'], 'payment_method' => ['nullable', 'in:cash,credit,partial'], 'cashbox_id' => ['nullable', 'integer'], 'bank_id' => ['nullable', 'integer'], 'paid_amount' => ['nullable', 'numeric', 'gte:0'], 'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string'], 'vehicle_id' => ['nullable', 'integer'], 'external_vehicle_plate' => ['nullable', 'string'], 'external_driver_name' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['nullable', 'integer'], 'lines.*.product_type_id' => ['nullable', 'integer'], 'lines.*.diameter_id' => ['nullable', 'integer'], 'lines.*.factory_weight' => ['required', 'numeric', 'gt:0'], 'lines.*.actual_weight' => ['nullable', 'numeric', 'gt:0'], 'lines.*.packages' => ['nullable', 'numeric', 'gte:0'], 'lines.*.unit_price' => ['required', 'numeric', 'gte:0']]);
    }

    private function salesData(Request $request): array
    {
        return $request->validate(['branch_id' => ['required', 'integer'], 'warehouse_id' => ['required', 'integer'], 'customer_id' => ['required', 'integer'], 'customer_bill_number' => ['required', 'string', 'max:100'], 'bill_date' => ['required', 'date'], 'total_actual_weight' => ['required', 'numeric', 'gt:0'], 'transport_cost' => ['nullable', 'numeric', 'gte:0'], 'loading_cost' => ['nullable', 'numeric', 'gte:0'], 'extra_cost' => ['nullable', 'numeric', 'gte:0'], 'discount' => ['nullable', 'numeric', 'gte:0'], 'vat_rate' => ['nullable', 'numeric', 'gte:0'], 'payment_method' => ['required', 'in:cash,credit,partial'], 'cashbox_id' => ['nullable', 'integer'], 'bank_id' => ['nullable', 'integer'], 'paid_amount' => ['nullable', 'numeric', 'gte:0'], 'due_date' => ['nullable', 'date'], 'notes' => ['nullable', 'string'], 'vehicle_id' => ['nullable', 'integer'], 'external_vehicle_plate' => ['nullable', 'string'], 'external_driver_name' => ['nullable', 'string'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_id' => ['nullable', 'integer'], 'lines.*.product_type_id' => ['nullable', 'integer'], 'lines.*.diameter_id' => ['nullable', 'integer'], 'lines.*.actual_weight' => ['required', 'numeric', 'gt:0'], 'lines.*.packages' => ['nullable', 'numeric', 'gte:0'], 'lines.*.unit_price' => ['required', 'numeric', 'gte:0']]);
    }
}
