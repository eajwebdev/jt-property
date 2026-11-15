<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAgreementRequest;
use App\Models\Bed;
use App\Models\RentalAgreement;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\TenancyService;
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
            ->with('success', "Rental started for {$tenant->full_name} in room {$unit->code}".($agreement->bed ? " / {$agreement->bed->label}" : '').'.');
    }
}
