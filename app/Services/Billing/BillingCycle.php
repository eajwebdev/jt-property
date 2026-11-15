<?php

namespace App\Services\Billing;

use App\Models\RentalAgreement;
use Carbon\CarbonImmutable;

/** One billing period for one agreement. All dates are Asia/Manila calendar dates; periodEnd is inclusive. */
final readonly class BillingCycle
{
    public function __construct(
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public CarbonImmutable $issueDate,
        public CarbonImmutable $dueDate,
    ) {}

    /** Calendar days in the period, both ends included. */
    public function periodDays(): int
    {
        return (int) $this->periodStart->diffInDays($this->periodEnd) + 1;
    }

    /** Days of the period the agreement is running (start/end inclusive); 0 when it does not overlap. */
    public function coveredDays(RentalAgreement $agreement): int
    {
        $from = $this->periodStart->max(self::day($agreement->start_date->toDateString()));
        $to = $agreement->end_date
            ? $this->periodEnd->min(self::day($agreement->end_date->toDateString()))
            : $this->periodEnd;

        return $to->lt($from) ? 0 : (int) $from->diffInDays($to) + 1;
    }

    public static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay();
    }
}
