<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Enums\UnitStatus;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use App\Models\UtilityBill;
use App\Services\Billing\AgingReportService;
use App\Services\Billing\PaymentService;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AgingReportService $aging, PaymentService $payments, DashboardService $dashboard): Response
    {
        $user = $request->user();
        $propertyIds = $user->role === Role::Staff ? $user->properties()->pluck('properties.id')->all() : null;
        $today = today()->toDateString();
        $monthStart = today()->startOfMonth()->toDateString();
        $monthEnd = today()->endOfMonth()->toDateString();
        $units = Unit::query()->when($propertyIds !== null, fn ($query) => $query->whereIn('property_id', $propertyIds))->get(['id', 'capacity', 'property_id', 'status']);
        $availableUnits = $units->filter(fn (Unit $unit) => $unit->status === UnitStatus::Available);
        $occupied = Occupancy::activeOn($today)->whereIn('unit_id', $units->pluck('id'))->count();
        $occupiedAvailable = Occupancy::activeOn($today)->whereIn('unit_id', $availableUnits->pluck('id'))->count();
        $report = $aging->forUser($user);
        $openDebits = $payments->openDebits($report['rows']->pluck('account_id')->all());
        $comingDue = $openDebits
            ->filter(fn ($debit) => $debit->due_date >= $today && $debit->due_date <= today()->addDays(7)->toDateString())
            ->sum('remaining_cents');
        $due = $report['rows']->filter(fn (array $row) => $row['open_cents'] > 0 && $row['oldest_due'] !== null)
            ->sortBy('oldest_due')->take(8)->map(fn (array $row) => [
                'account_id' => $row['account_id'], 'account_no' => $row['account_no'],
                'tenant' => $row['tenant'], 'property' => $row['property'],
                'due_date' => $row['oldest_due'], 'open_cents' => $row['open_cents'],
            ])->values();

        return Inertia::render('dashboard', [
            'metrics' => [
                'properties' => Property::query()->when($propertyIds !== null, fn ($query) => $query->whereIn('id', $propertyIds))->whereNull('archived_at')->count(),
                'rooms' => $units->count(),
                'capacity' => $units->sum('capacity'),
                'occupied' => $occupied,
                'vacant' => max(0, $availableUnits->sum('capacity') - $occupiedAvailable),
                'billed_cents' => (int) Invoice::query()->when($propertyIds !== null, fn ($query) => $query->whereIn('property_id', $propertyIds))->where('status', InvoiceStatus::Finalized)->whereBetween('issue_date', [$monthStart, $monthEnd])->sum('current_charges_cents'),
                'collected_cents' => (int) Payment::query()->when($propertyIds !== null, fn ($query) => $query->whereIn('property_id', $propertyIds))->where('status', 'approved')->whereBetween('paid_on', [$monthStart, $monthEnd])->sum('amount_cents'),
                'still_due_cents' => array_sum($report['totals']),
                'overdue_cents' => array_sum(array_diff_key($report['totals'], ['current' => true])),
                'coming_due_cents' => $comingDue,
                'late_accounts' => $report['rows']->filter(fn (array $row) => $row['open_cents'] > 0 && $row['oldest_due'] !== null && $row['oldest_due'] < $today)->count(),
                'pending_utilities' => UtilityBill::query()->where('status', 'draft')->whereBetween('bill_month', [$monthStart, $monthEnd])->whereHas('unit', fn ($query) => $query->when($propertyIds !== null, fn ($q) => $q->whereIn('property_id', $propertyIds)))->count(),
            ],
            'due' => $due,
            // Tabs: loaded lazily (Inertia partial reload) the first time a tab is opened.
            'income' => Inertia::optional(fn () => $dashboard->income($propertyIds)),
            'late' => Inertia::optional(fn () => $dashboard->late($openDebits, $propertyIds)),
            'occupancy' => Inertia::optional(fn () => $dashboard->occupancy($propertyIds)),
            'recentActivity' => ActivityLog::query()->with('user:id,name')
                ->when($propertyIds !== null, fn ($query) => $query->where('user_id', $user->id))
                ->latest('id')->limit(8)->get()->map(fn (ActivityLog $log) => [
                    'id' => $log->id, 'action' => $log->action,
                    'user' => $log->user?->name ?? 'System',
                    'at' => $log->created_at?->toDateTimeString(),
                ]),
        ]);
    }
}
