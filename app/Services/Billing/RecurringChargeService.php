<?php

namespace App\Services\Billing;

use App\Models\RecurringCharge;
use App\Models\RentalAgreement;
use Illuminate\Support\Collection;

class RecurringChargeService
{
    /**
     * Add-ons for one agreement and cycle: each active charge that started by the period end and has not
     * ended before the period start appears exactly once. Amounts are copied, so later edits never reach
     * a bill already made. Read-only.
     *
     * @return Collection<int, array{label: string, amount_cents: int, category_id: int, source: RecurringCharge}>
     */
    public function linesFor(RentalAgreement $agreement, BillingCycle $cycle): Collection
    {
        // A transfer ends the carried charge on the old agreement and restarts it on the new one:
        // in the cycle holding that date only the new agreement bills it.
        $replaced = $agreement->isReplaced();
        $agreementEnd = $agreement->end_date?->toDateString();

        return RecurringCharge::query()
            ->where('rental_agreement_id', $agreement->id)
            ->where('active', true)
            ->whereDate('start_date', '<=', $cycle->periodEnd->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $cycle->periodStart->toDateString()))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->reject(fn (RecurringCharge $c) => $replaced
                && $c->end_date?->toDateString() === $agreementEnd
                && $agreementEnd >= $cycle->periodStart->toDateString()
                && $agreementEnd <= $cycle->periodEnd->toDateString())
            ->values()
            ->map(fn (RecurringCharge $c) => [
                'label' => $c->label,
                'amount_cents' => $c->amount_cents,
                'category_id' => $c->charge_category_id,
                'source' => $c,
            ]);
    }
}
