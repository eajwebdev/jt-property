<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepositRequest;
use App\Models\BillingAccount;
use App\Models\RentalAgreement;
use App\Services\TenancyService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class SecurityDepositController extends Controller
{
    public function store(StoreDepositRequest $request, BillingAccount $account, TenancyService $tenancy): RedirectResponse
    {
        Gate::authorize('recordDeposit', RentalAgreement::class);

        $data = $request->validated();
        $deposit = $tenancy->recordDeposit(
            $account,
            $request->amountCents(),
            Carbon::parse($data['received_on']),
            isset($data['rental_agreement_id']) ? RentalAgreement::findOrFail($data['rental_agreement_id']) : null,
            $data['notes'] ?? null,
        );

        return back()->with('success', 'Deposit of '.Money::format($deposit->amount_cents).' recorded.');
    }
}
