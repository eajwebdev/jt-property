<?php

namespace App\Http\Controllers;

use App\Models\RentalAgreement;
use App\Models\UtilityExclusion;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UtilityExclusionController extends Controller
{
    /** The owner chooses not to bill a utility to this agreement for this month (instead of waiting for a reading). */
    public function store(Request $request, RentalAgreement $agreement): RedirectResponse
    {
        Gate::authorize('owner');

        $data = $request->validate([
            'type' => ['required', Rule::in(['electricity', 'water'])],
            'bill_month' => ['required', 'date_format:Y-m'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $month = Carbon::createFromFormat('!Y-m', $data['bill_month'])->toDateString();

        if (UtilityExclusion::where('rental_agreement_id', $agreement->id)->where('type', $data['type'])->whereDate('bill_month', $month)->exists()) {
            throw ValidationException::withMessages(['type' => 'That utility is already excluded for this month.']);
        }

        DB::transaction(function () use ($agreement, $data, $month) {
            $exclusion = UtilityExclusion::create([
                'rental_agreement_id' => $agreement->id, 'type' => $data['type'], 'bill_month' => $month,
                'reason' => trim($data['reason']), 'created_by' => auth()->id(),
            ]);
            Audit::log('utility_exclusion.created', $exclusion, ['new' => $exclusion->only(['rental_agreement_id', 'type']) + ['bill_month' => $data['bill_month'], 'reason' => $exclusion->reason]]);
        });

        return back()->with('success', ucfirst($data['type']).' will not be billed to this tenant for '.Carbon::parse($month)->format('F Y').'.');
    }

    public function destroy(UtilityExclusion $exclusion): RedirectResponse
    {
        Gate::authorize('owner');

        DB::transaction(function () use ($exclusion) {
            Audit::log('utility_exclusion.removed', $exclusion, ['old' => $exclusion->only(['rental_agreement_id', 'type', 'reason']) + ['bill_month' => $exclusion->bill_month->format('Y-m')]]);
            $exclusion->delete();
        });

        return back()->with('success', 'The exclusion was removed.');
    }
}
