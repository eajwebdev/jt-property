<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\RecurringCharge;
use App\Models\RentalAgreement;
use App\Rules\Pesos;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecurringChargeController extends Controller
{
    public function store(Request $request, RentalAgreement $agreement): RedirectResponse
    {
        Gate::authorize('owner');

        $data = $request->validate([
            'charge_category_id' => ['required', 'integer', Rule::exists('charge_categories', 'id')],
            'label' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'string', new Pesos('monthly amount')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        if ($agreement->isReplaced() || ($agreement->end_date !== null && $agreement->end_date->toDateString() < today()->toDateString())) {
            throw ValidationException::withMessages(['agreement' => 'This rental has ended. Add the charge to the current rental instead.']);
        }

        if ($agreement->end_date !== null && $data['start_date'] > $agreement->end_date->toDateString()) {
            throw ValidationException::withMessages(['start_date' => 'The rental ends on '.$agreement->end_date->toDateString().'. Pick a start date on or before that.']);
        }

        $charge = DB::transaction(function () use ($agreement, $data) {
            $charge = $agreement->recurringCharges()->create([
                'charge_category_id' => $data['charge_category_id'],
                'label' => trim($data['label']),
                'amount_cents' => Money::toCents($data['amount']),
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
            ]);
            Audit::log('recurring_charge.created', $charge, ['new' => $charge->only(['rental_agreement_id', 'charge_category_id', 'label', 'amount_cents', 'start_date', 'end_date'])]);

            return $charge;
        });

        return back()->with('success', "{$charge->label} will be added to every monthly bill from ".$charge->start_date->format('M j, Y').'.');
    }

    /** Edits change future bills only: invoices keep the amounts they were made with. */
    public function update(Request $request, RecurringCharge $charge): RedirectResponse
    {
        Gate::authorize('owner');

        $data = $request->validate([
            'charge_category_id' => ['required', 'integer', Rule::exists('charge_categories', 'id')],
            'label' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'string', new Pesos('monthly amount')],
        ]);

        DB::transaction(function () use ($charge, $data) {
            $charge = RecurringCharge::lockForUpdate()->findOrFail($charge->id);
            $before = $charge->only(['charge_category_id', 'label', 'amount_cents']);
            $charge->update([
                'charge_category_id' => $data['charge_category_id'],
                'label' => trim($data['label']),
                'amount_cents' => Money::toCents($data['amount']),
            ]);

            $changes = array_intersect_key($charge->getChanges(), $before);
            if ($changes) {
                Audit::log('recurring_charge.updated', $charge, ['old' => array_intersect_key($before, $changes), 'new' => $changes]);
            }
        });

        return back()->with('success', 'Saved. The new amount applies to bills made from now on.');
    }

    /** Ending sets the last day; the row stays so past bills keep their source. */
    public function end(Request $request, RecurringCharge $charge): RedirectResponse
    {
        Gate::authorize('owner');

        $data = $request->validate(['end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$charge->start_date->toDateString()]]);

        DB::transaction(function () use ($charge, $data) {
            $charge = RecurringCharge::lockForUpdate()->findOrFail($charge->id);
            $old = $charge->end_date?->toDateString();
            if (Invoice::finalizedInPeriod($charge->rental_agreement_id, $data['end_date'], $data['end_date'])) {
                throw ValidationException::withMessages(['end_date' => Invoice::INVOICED_PERIOD_MESSAGE]);
            }
            if ($old !== null && $data['end_date'] > $old) {
                throw ValidationException::withMessages(['end_date' => "This charge already ends on {$old}. Pick that date or earlier."]);
            }

            $charge->update(['end_date' => $data['end_date']]);
            Audit::log('recurring_charge.ended', $charge, ['old' => ['end_date' => $old], 'new' => ['end_date' => $data['end_date']]]);
        });

        return back()->with('success', 'The charge now ends on '.$data['end_date'].'.');
    }
}
