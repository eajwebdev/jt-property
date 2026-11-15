<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\OneTimeCharge;
use App\Rules\Pesos;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OneTimeChargeController extends Controller
{
    /** Waits for the first bill of that account and month, then is marked used by invoice generation. */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('owner');

        $data = $request->validate([
            'billing_account_id' => ['required', 'integer', Rule::exists('billing_accounts', 'id')],
            'rental_agreement_id' => ['nullable', 'integer', Rule::exists('rental_agreements', 'id')->where('billing_account_id', $request->input('billing_account_id'))],
            'charge_category_id' => ['required', 'integer', Rule::exists('charge_categories', 'id')],
            'label' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(['charge', 'credit'])],
            'amount' => ['required', 'string', new Pesos('amount')],
            'bill_month' => ['required', 'date_format:Y-m'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $month = Carbon::createFromFormat('!Y-m', $data['bill_month']);
        if (Invoice::where('billing_account_id', $data['billing_account_id'])->where('status', InvoiceStatus::Finalized)
            ->whereBetween('period_start', [$month->toDateString(), $month->copy()->endOfMonth()->toDateString()])->exists()) {
            throw ValidationException::withMessages(['bill_month' => Invoice::INVOICED_PERIOD_MESSAGE]);
        }

        $charge = DB::transaction(function () use ($data) {
            $charge = OneTimeCharge::create([
                'billing_account_id' => $data['billing_account_id'],
                'rental_agreement_id' => $data['rental_agreement_id'] ?? null,
                'charge_category_id' => $data['charge_category_id'],
                'label' => trim($data['label']),
                'kind' => $data['kind'],
                'amount_cents' => Money::toCents($data['amount']),
                'bill_month' => Carbon::createFromFormat('!Y-m', $data['bill_month'])->toDateString(),
                'created_by' => auth()->id(),
                'reason' => ($data['reason'] ?? null) ?: null,
            ]);
            Audit::log('one_time_charge.created', $charge, [
                'new' => $charge->only(['billing_account_id', 'rental_agreement_id', 'charge_category_id', 'label', 'kind', 'amount_cents']) + ['bill_month' => $data['bill_month']],
                'reason' => $charge->reason,
            ]);

            return $charge;
        });

        return back()->with('success', ($charge->kind === 'credit' ? 'Credit' : 'Charge').' of '.Money::format($charge->amount_cents).' will be on the '.$charge->bill_month->format('F Y').' bill.');
    }
}
