<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FleetReportService
{
    public const REPORTS = [
        'vehicle-pl', 'trip-cost', 'fuel', 'driver-performance', 'expenses-by-category',
        'maintenance-period', 'inactive-vehicles', 'branch-performance',
    ];

    public function report(Company $company, string $report, ?string $from = null, ?string $to = null, ?int $branchId = null): array
    {
        abort_unless(in_array($report, self::REPORTS, true), 404);
        if ($branchId) {
            abort_unless(DB::table('branches')->where('company_id', $company->id)->where('id', $branchId)->exists(), 404);
        }

        $trips = $this->trips($company, $from, $to, $branchId);
        $expenses = $this->expenses($company, $from, $to, $branchId);
        $maintenance = $this->maintenance($company, $from, $to, $branchId);

        $rows = match ($report) {
            'vehicle-pl' => $this->vehicleProfitLoss($company, $trips, $expenses, $maintenance, $branchId),
            'trip-cost' => $trips->map(function ($trip) use ($expenses): array {
                $direct = (float) $expenses->where('trip_id', $trip->id)->sum('amount');
                $operating = (float) $trip->fuel_cost + (float) $trip->road_fees + (float) $trip->loading_fees;

                return ['id' => $trip->id, 'label' => $trip->vehicle_plate.' · '.$trip->trip_type, 'date' => $trip->trip_at, 'revenue' => (float) $trip->revenue, 'cost' => round($operating + $direct, 2), 'profit' => round((float) $trip->revenue - $operating - $direct, 2)];
            })->values(),
            'fuel' => $trips->groupBy('vehicle_id')->map(function (Collection $items): array {
                $distance = (float) $items->sum('distance');
                $fuel = (float) $items->sum('fuel_cost');

                return ['id' => $items->first()->vehicle_id, 'label' => $items->first()->vehicle_plate, 'distance' => $distance, 'fuel_cost' => $fuel, 'cost_per_distance' => $distance > 0 ? round($fuel / $distance, 3) : 0];
            })->values(),
            'driver-performance' => $trips->groupBy('driver_id')->map(fn (Collection $items): array => ['id' => $items->first()->driver_id ?: 'unassigned', 'label' => $items->first()->driver_name ?: 'Unassigned', 'trips' => $items->count(), 'distance' => (float) $items->sum('distance'), 'revenue' => (float) $items->sum('revenue')])->values(),
            'expenses-by-category' => $expenses->groupBy('category')->map(fn (Collection $items, string $category): array => ['id' => $category, 'label' => $category, 'count' => $items->count(), 'total' => (float) $items->sum('amount')])->values(),
            'maintenance-period' => $maintenance->groupBy('vehicle_id')->map(fn (Collection $items): array => ['id' => $items->first()->vehicle_id, 'label' => $items->first()->vehicle_plate, 'count' => $items->count(), 'total' => (float) $items->sum('cost')])->values(),
            'inactive-vehicles' => DB::table('vehicles')->where('company_id', $company->id)->where('status', 'inactive')->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->orderBy('plate')->get()->map(fn ($vehicle): array => ['id' => $vehicle->id, 'label' => $vehicle->plate, 'type' => $vehicle->type, 'status' => $vehicle->status])->values(),
            'branch-performance' => $trips->groupBy('branch_id')->map(function (Collection $items) use ($expenses, $maintenance): array {
                $branchId = $items->first()->branch_id;
                $tripCosts = (float) $items->sum(fn ($trip) => (float) $trip->fuel_cost + (float) $trip->road_fees + (float) $trip->loading_fees);
                $other = (float) $expenses->whereIn('vehicle_id', $items->pluck('vehicle_id')->unique())->sum('amount') + (float) $maintenance->whereIn('vehicle_id', $items->pluck('vehicle_id')->unique())->sum('cost');
                $revenue = (float) $items->sum('revenue');

                return ['id' => $branchId ?: 'unassigned', 'label' => $items->first()->branch_name ?: 'Unassigned', 'trips' => $items->count(), 'revenue' => $revenue, 'cost' => round($tripCosts + $other, 2), 'profit' => round($revenue - $tripCosts - $other, 2)];
            })->values(),
        };

        return ['report' => $report, 'from' => $from, 'to' => $to, 'branch_id' => $branchId, 'rows' => $rows];
    }

    private function trips(Company $company, ?string $from, ?string $to, ?int $branchId): Collection
    {
        return DB::table('trips')->join('vehicles', 'vehicles.id', '=', 'trips.vehicle_id')->leftJoin('drivers', 'drivers.id', '=', 'trips.driver_id')->leftJoin('branches', 'branches.id', '=', 'trips.branch_id')
            ->where('trips.company_id', $company->id)->where('trips.status', 'approved')
            ->when($from, fn ($query) => $query->whereDate('trips.trip_at', '>=', $from))->when($to, fn ($query) => $query->whereDate('trips.trip_at', '<=', $to))->when($branchId, fn ($query) => $query->where('trips.branch_id', $branchId))
            ->select('trips.*', 'vehicles.plate as vehicle_plate', 'drivers.name as driver_name', 'branches.name as branch_name')->orderBy('trips.trip_at')->get();
    }

    private function expenses(Company $company, ?string $from, ?string $to, ?int $branchId): Collection
    {
        return DB::table('fleet_expenses')->join('vehicles', 'vehicles.id', '=', 'fleet_expenses.vehicle_id')->where('fleet_expenses.company_id', $company->id)->where('fleet_expenses.status', 'approved')
            ->when($from, fn ($query) => $query->whereDate('fleet_expenses.expense_date', '>=', $from))->when($to, fn ($query) => $query->whereDate('fleet_expenses.expense_date', '<=', $to))->when($branchId, fn ($query) => $query->where('vehicles.branch_id', $branchId))
            ->select('fleet_expenses.*', 'vehicles.plate as vehicle_plate')->get();
    }

    private function maintenance(Company $company, ?string $from, ?string $to, ?int $branchId): Collection
    {
        return DB::table('maintenance_records')->join('vehicles', 'vehicles.id', '=', 'maintenance_records.vehicle_id')->where('maintenance_records.company_id', $company->id)->where('maintenance_records.status', 'approved')
            ->when($from, fn ($query) => $query->whereDate('maintenance_records.starts_on', '>=', $from))->when($to, fn ($query) => $query->whereDate('maintenance_records.starts_on', '<=', $to))->when($branchId, fn ($query) => $query->where('vehicles.branch_id', $branchId))
            ->select('maintenance_records.*', 'vehicles.plate as vehicle_plate')->get();
    }

    private function vehicleProfitLoss(Company $company, Collection $trips, Collection $expenses, Collection $maintenance, ?int $branchId): Collection
    {
        return DB::table('vehicles')->where('company_id', $company->id)->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->orderBy('plate')->get()->map(function ($vehicle) use ($trips, $expenses, $maintenance): array {
            $vehicleTrips = $trips->where('vehicle_id', $vehicle->id);
            $revenue = (float) $vehicleTrips->sum('revenue');
            $tripCosts = (float) $vehicleTrips->sum(fn ($trip) => (float) $trip->fuel_cost + (float) $trip->road_fees + (float) $trip->loading_fees);
            $cost = $tripCosts + (float) $expenses->where('vehicle_id', $vehicle->id)->sum('amount') + (float) $maintenance->where('vehicle_id', $vehicle->id)->sum('cost');

            return ['id' => $vehicle->id, 'label' => $vehicle->plate, 'revenue' => $revenue, 'cost' => round($cost, 2), 'profit' => round($revenue - $cost, 2)];
        });
    }
}
