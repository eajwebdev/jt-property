<?php

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

/**
 * Everything a monthly billing statement shows. Money is integer centavos; entries that reduce
 * what is owed are negative. Dates inside list rows are Y-m-d strings so the whole thing can be stored as JSON.
 */
final readonly class Statement
{
    /**
     * @param  list<array{category:string,label:string,description:?string,qty_e3:?int,unit_rate_e4:?int,amount_cents:int}>  $lines
     * @param  list<array{date:string,memo:?string,type:string,amount_cents:int}>  $payments  non-charge entries since period start
     * @param  list<array{date:string,memo:?string,type:string,amount_cents:int}>  $recentPayments  last payments before the period
     * @param  ?array<string, mixed>  $openingBreakdown
     */
    public function __construct(
        public int $invoiceId,
        public string $number,
        public bool $isVoid,
        public ?string $voidReason,
        public CarbonImmutable $issueDate,
        public CarbonImmutable $dueDate,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public CarbonImmutable $asOf,
        public string $accountNo,
        public string $tenantName,
        public string $propertyName,
        public ?string $propertyAddress,
        public string $unitLabel,
        public array $lines,
        public int $previousCents,
        public array $payments,
        public int $paymentsSinceCents,
        public int $currentCents,
        public int $totalDueCents,
        public int $pastDueCents,
        public bool $isCredit,
        public array $recentPayments = [],
        public ?array $openingBreakdown = null,
    ) {}

    /** The numbers that are frozen into a statement snapshot. */
    public function totals(): array
    {
        return [
            'previous' => $this->previousCents,
            'payments_since' => $this->paymentsSinceCents,
            'current' => $this->currentCents,
            'total_due' => $this->totalDueCents,
            'past_due' => $this->pastDueCents,
            'as_of' => $this->asOf->toDateString(),
            'payments' => $this->payments,
            'recent_payments' => $this->recentPayments,
            'opening_breakdown' => $this->openingBreakdown,
        ];
    }
}
