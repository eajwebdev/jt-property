<?php

namespace App\Services\Billing;

use App\Models\RentalAgreement;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DomainException;

/** Pure date/rent rules for a monthly bill. No database writes. */
class BillingCycleResolver
{
    /**
     * The cycle of billing month ($year, $month) for the agreement, or null when the agreement
     * does not overlap the period (liveness is decided by dates, never by the status column).
     */
    public function cycleFor(RentalAgreement $agreement, int $year, int $month): ?BillingCycle
    {
        $start = CarbonImmutable::create($year, $month, $agreement->cycle_start_day, 0, 0, 0, 'Asia/Manila');
        $end = $start->addMonthNoOverflow()->subDay();

        $due = $this->dueDate($agreement->due_day, $start);
        $cycle = new BillingCycle($start, $end, $due->subDays($agreement->issue_lead_days), $due);

        return $cycle->coveredDays($agreement) > 0 ? $cycle : null;
    }

    /** First date on/after $periodStart whose day-of-month is $dueDay, clamped to short months (Feb 31 -> Feb 28/29). */
    public function dueDate(int $dueDay, CarbonImmutable $periodStart): CarbonImmutable
    {
        $due = $this->clamped($periodStart, $dueDay);

        return $due->lt($periodStart) ? $this->clamped($periodStart->startOfMonth()->addMonth(), $dueDay) : $due;
    }

    /** @return array{amount_cents: int, description: string} */
    public function rentLine(RentalAgreement $agreement, BillingCycle $cycle): array
    {
        $start = BillingCycle::day($agreement->start_date->toDateString());
        $rent = $agreement->rentOn($cycle->periodStart->max($start)->toDateString())
            ?? throw new DomainException("Agreement {$agreement->id} has no rent rate in force for this cycle.");

        $covered = $cycle->coveredDays($agreement);
        $partial = $covered < $cycle->periodDays();
        $prorated = $partial && ($agreement->prorate || $this->isTransferCycle($agreement, $cycle));

        $description = 'Monthly Rental – Room '.$agreement->unit->code;
        if ($prorated) {
            $description .= " ({$covered}/{$cycle->periodDays()} days)";
        }
        if ($agreement->bed_id !== null) {
            $description .= ' '.$agreement->bed->label;
        }

        return [
            'amount_cents' => $prorated ? Money::roundHalfUpDiv($rent * $covered, $cycle->periodDays()) : $rent,
            'description' => $description,
        ];
    }

    /** The old agreement's last cycle (a transfer ends it inside the period) or the new one's first (it starts inside). */
    private function isTransferCycle(RentalAgreement $agreement, BillingCycle $cycle): bool
    {
        $inside = fn ($date) => $date !== null
            && BillingCycle::day($date->toDateString())->between($cycle->periodStart, $cycle->periodEnd);

        if ($agreement->replaces_agreement_id !== null && $inside($agreement->start_date)) {
            return true;
        }

        return $agreement->end_date !== null && $inside($agreement->end_date) && $agreement->isReplaced();
    }

    private function clamped(CarbonImmutable $inMonth, int $day): CarbonImmutable
    {
        return $inMonth->setDay(min($day, $inMonth->daysInMonth));
    }
}
