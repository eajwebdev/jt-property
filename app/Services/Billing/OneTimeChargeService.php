<?php

namespace App\Services\Billing;

use App\Models\BillingAccount;
use App\Models\OneTimeCharge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class OneTimeChargeService
{
    /**
     * One-time charges and credits waiting for the account's bill of that month: not yet on any invoice.
     * Read-only; generation marks them consumed by setting invoice_id.
     *
     * @return Collection<int, OneTimeCharge>
     */
    public function pendingFor(BillingAccount $account, int $year, int $month): Collection
    {
        return OneTimeCharge::query()
            ->where('billing_account_id', $account->id)
            ->whereDate('bill_month', Carbon::create($year, $month, 1)->toDateString())
            ->whereNull('invoice_id')
            ->orderBy('id')
            ->get();
    }
}
