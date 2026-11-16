<?php

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

/** A date-range ledger statement for one billing account, with a running balance. */
final readonly class AccountStatement
{
    /** @param list<array{date:string,type:string,memo:?string,amount_cents:int,balance_cents:int}> $rows */
    public function __construct(
        public int $billingAccountId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $openingCents,
        public array $rows,
        public int $closingCents,
    ) {}
}
