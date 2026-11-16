<?php

namespace App\Services\Billing;

/** One agreement's bill for one month, as the owner will see it before posting. Nothing here is stored. */
final readonly class PreviewRow
{
    /**
     * @param  list<array{category: string, label: string, description: ?string, qty_e3: ?int, unit_rate_e4: ?int, amount_cents: int, source: mixed}>  $lines
     * @param  list<string>  $flags  needs_utility_input:{type}, no_occupants:{type}, no_rent_rate, covered_by_opening_balance:{Y-m-d}, no_email, existing_invoice
     */
    public function __construct(
        public int $agreementId,
        public int $accountId,
        public int $propertyId,
        public string $accountLabel,
        public string $tenantLabel,
        public string $unitLabel,
        public string $periodStart,
        public string $periodEnd,
        public string $issueDate,
        public string $dueDate,
        public array $lines,
        public int $currentChargesCents,
        public int $previousBalanceCents,
        public int $paymentsSinceCents,
        public int $estimatedTotalCents,
        public string $status,
        public array $flags,
        public ?string $invoiceNumber = null,
    ) {}
}
