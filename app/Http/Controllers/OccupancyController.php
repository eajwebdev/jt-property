<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveOutResidentRequest;
use App\Models\Occupancy;
use App\Services\TenancyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class OccupancyController extends Controller
{
    public function moveOut(MoveOutResidentRequest $request, Occupancy $occupancy, TenancyService $tenancy): RedirectResponse
    {
        Gate::authorize('update', $occupancy);

        $date = Carbon::parse($request->validated('move_out_date'));
        $tenancy->moveOutResident($occupancy, $date, $request->validated('reason'));

        return back()->with('success', 'Move-out saved. Their last day in the room is '.$date->format('M j, Y').'.');
    }
}
