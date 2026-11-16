<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeRentRequest;
use App\Http\Requests\MoveOutRequest;
use App\Http\Requests\StoreAgreementRequest;
use App\Http\Requests\TransferAgreementRequest;
use App\Models\Bed;
use App\Models\RentalAgreement;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\TenancyService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class RentalAgreementController extends Controller
{
    public function store(StoreAgreementRequest $request, Tenant $tenant, TenancyService $tenancy): RedirectResponse
    {
        Gate::authorize('create', RentalAgreement::class);

        $data = $request->validated();
        $unit = Unit::findOrFail($data['unit_id']);

        $agreement = $tenancy->startAgreement(
            $tenant,
            $unit,
            isset($data['bed_id']) ? Bed::findOrFail($data['bed_id']) : null,
            $request->rentCents(),
            (int) $data['due_day'],
            Carbon::parse($data['start_date']),
            [
                'cycle_start_day' => (int) $data['cycle_start_day'],
                'issue_lead_days' => (int) $data['issue_lead_days'],
                'prorate' => (bool) $data['prorate'],
                'end_date' => $data['end_date'] ?? null,
                'resident_tenant_ids' => $data['resident_tenant_ids'],
                'deposit_cents' => $request->depositCents(),
                'override_reason' => $data['override_reason'] ?? null,
            ],
        );

        return redirect()->route('tenants.show', $tenant)
            ->with('success', "Rental started for {$tenant->full_name} in room {$unit->label}".($agreement->bed ? " / {$agreement->bed->label}" : '').'.');
    }

    public function changeRent(ChangeRentRequest $request, RentalAgreement $agreement, TenancyService $tenancy): RedirectResponse
    {
        Gate::authorize('update', $agreement);

        $rate = $tenancy->changeRent($agreement, $request->rentCents(), Carbon::parse($request->validated('effective_from')), $request->validated('reason'));

        return back()->with('success', 'Rent will be '.Money::format($rate->rent_cents).' from '.$rate->effective_from->format('M j, Y').'.');
    }

    public function moveOut(MoveOutRequest $request, RentalAgreement $agreement, TenancyService $tenancy): RedirectResponse
    {
        Gate::authorize('update', $agreement);

        $date = Carbon::parse($request->validated('move_out_date'));
        $tenancy->moveOut($agreement, $date, $request->validated('reason'));

        return back()->with('success', 'Move-out saved. The last day in the room is '.$date->format('M j, Y').'.');
    }

    public function transfer(TransferAgreementRequest $request, RentalAgreement $agreement, TenancyService $tenancy): RedirectResponse
    {
        Gate::authorize('update', $agreement);

        $data = $request->validated();
        $new = $tenancy->transfer(
            $agreement,
            Unit::findOrFail($data['unit_id']),
            isset($data['bed_id']) ? Bed::findOrFail($data['bed_id']) : null,
            Carbon::parse($data['transfer_date']),
            $request->rentCents(),
            $data['reason'],
        );

        return back()->with('success', "Moved to room {$new->unit->label}".($new->bed ? " / {$new->bed->label}" : '').' from '.$new->start_date->format('M j, Y').'.');
    }
}
