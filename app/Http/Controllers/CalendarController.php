<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Services\Billing\BillingCycleResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function __invoke(Request $request, BillingCycleResolver $cycles): Response
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'property_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $properties = Property::query()->when($user->role === Role::Staff, fn ($query) => $query->whereIn('id', $user->properties()->select('properties.id')))->orderBy('name')->get(['id', 'name']);
        $allowed = $properties->pluck('id')->all();
        $propertyId = isset($data['property_id']) && in_array((int) $data['property_id'], $allowed, true) ? (int) $data['property_id'] : null;
        $month = $data['month'] ?? today()->format('Y-m');
        $start = Carbon::parse($month.'-01');
        $end = $start->copy()->endOfMonth();
        $ids = $propertyId === null ? $allowed : [$propertyId];
        $events = collect();

        $agreements = RentalAgreement::query()->whereHas('billingAccount', fn ($query) => $query->whereIn('property_id', $ids))
            ->whereDate('start_date', '<=', $end->copy()->addMonth()->toDateString())
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $start->copy()->subMonth()->toDateString()))
            ->with(['billingAccount.payer:id,full_name', 'unit:id,code,building,property_id'])->get();

        foreach ($agreements as $agreement) {
            $cycle = $cycles->cycleFor($agreement, (int) $start->year, (int) $start->month);
            if ($cycle !== null && $cycle->dueDate->betweenIncluded($start, $end)) {
                $events->push(['date' => $cycle->dueDate->toDateString(), 'type' => 'due', 'label' => $agreement->billingAccount->payer->full_name.' · '.$agreement->unit->label, 'account_id' => $agreement->billing_account_id]);
            }
        }

        $occupancies = Occupancy::query()->whereHas('unit', fn ($query) => $query->whereIn('property_id', $ids))
            ->where(fn ($query) => $query->whereBetween('move_in_date', [$start->toDateString(), $end->toDateString()])->orWhereBetween('move_out_date', [$start->toDateString(), $end->toDateString()]))
            ->with(['tenant:id,full_name', 'unit:id,code,building,property_id'])->get();
        foreach ($occupancies as $occupancy) {
            foreach (['move_in_date' => 'move-in', 'move_out_date' => 'move-out'] as $field => $type) {
                $date = $occupancy->{$field}?->toDateString();
                if ($date !== null && $date >= $start->toDateString() && $date <= $end->toDateString()) {
                    $events->push(['date' => $date, 'type' => $type, 'label' => $occupancy->tenant->full_name.' · '.$occupancy->unit->label, 'account_id' => null]);
                }
            }
        }

        return Inertia::render('calendar/index', [
            'month' => $month,
            'propertyId' => $propertyId,
            'properties' => $properties,
            'events' => $events->sortBy('date')->values(),
        ]);
    }
}
