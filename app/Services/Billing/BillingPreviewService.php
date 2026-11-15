<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Invoice;
use App\Models\RentalAgreement;
use App\Models\User;
use App\Models\UtilityBill;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/** Dry run of a month's bills. Reads only; generation (Task 3.7) posts exactly the lines built here. */
class BillingPreviewService
{
    public function __construct(
        private BillingCycleResolver $cycles,
        private RecurringChargeService $recurring,
        private OneTimeChargeService $oneTime,
        private UtilityAllocationService $utilities,
        private ReceivableLedgerService $ledger,
    ) {}

    /**
     * @param  list<int>|null  $agreementIds
     * @return array{rows: list<PreviewRow>, summary: array{counts: array<string, int>, total_new_charges_cents: int, total_previous_outstanding_cents: int, estimated_statement_total_cents: int}}
     */
    public function preview(int $year, int $month, ?int $propertyId = null, ?array $agreementIds = null, ?User $for = null): array
    {
        $agreements = $this->agreements($year, $month, $propertyId, $agreementIds, $for);

        $cycles = $agreements->mapWithKeys(fn (RentalAgreement $a) => [$a->id => $this->cycles->cycleFor($a, $year, $month)])->filter();
        $keys = $cycles->mapWithKeys(fn (BillingCycle $c, int $id) => [self::idempotencyKey($id, $c) => $id]);
        $billed = Invoice::whereIn('idempotency_key', $keys->keys())->where('status', InvoiceStatus::Finalized)->pluck('idempotency_key')->flip();

        $rows = [];
        foreach ($agreements as $a) {
            $cycle = $cycles->get($a->id);
            if ($cycle === null) {
                continue;
            }

            $built = $this->linesForAgreement($a, $cycle);
            $flags = $built['flags'];
            $current = array_sum(array_column($built['lines'], 'amount_cents'));
            $previous = $this->ledger->balance($a->billingAccount, $cycle->periodStart->subDay());

            $email = $a->billingAccount->payer?->email;
            if ($email === null || trim($email) === '') {
                $flags[] = 'no_email';
            }
            $isBilled = $billed->has(self::idempotencyKey($a->id, $cycle));
            if ($isBilled) {
                $flags[] = 'existing_invoice';
            }

            $rows[] = new PreviewRow(
                agreementId: $a->id,
                accountId: $a->billing_account_id,
                propertyId: $a->unit->property_id,
                accountLabel: $a->billingAccount->account_no ?? "#{$a->billing_account_id}",
                tenantLabel: $a->billingAccount->payer?->full_name ?? '—',
                unitLabel: $a->unit->code.($a->bed ? ' '.$a->bed->label : ''),
                periodStart: $cycle->periodStart->toDateString(),
                periodEnd: $cycle->periodEnd->toDateString(),
                issueDate: $cycle->issueDate->toDateString(),
                dueDate: $cycle->dueDate->toDateString(),
                lines: $built['lines'],
                currentChargesCents: $current,
                previousBalanceCents: $previous,
                estimatedTotalCents: $previous + $current,
                status: $isBilled ? 'already_billed' : ($built['needs_review'] ? 'needs_review' : 'eligible'),
                flags: $flags,
            );
        }

        return ['rows' => $rows, 'summary' => $this->summary($rows)];
    }

    public static function idempotencyKey(int $agreementId, BillingCycle $cycle): string
    {
        return "agreement:{$agreementId}:cycle:{$cycle->periodStart->toDateString()}";
    }

    /**
     * The one place a bill's lines are assembled: rent, recurring, utilities, one-time. Generation must
     * call this so what is posted equals what was previewed. A missing rent rate or utility input is a
     * flag, never a zero line.
     *
     * @return array{lines: list<array{category: string, label: string, description: ?string, qty_e3: ?int, unit_rate_e4: ?int, amount_cents: int, source: mixed}>, flags: list<string>, needs_review: bool}
     */
    public function linesForAgreement(RentalAgreement $agreement, BillingCycle $cycle): array
    {
        $lines = [];
        $flags = [];

        try {
            $rent = $this->cycles->rentLine($agreement, $cycle);
            $lines[] = $this->line('rent', 'Monthly Rental', $rent['description'], $rent['amount_cents'], null);
        } catch (DomainException) {
            $flags[] = 'no_rent_rate';
        }

        foreach ($this->recurring->linesFor($agreement, $cycle) as $c) {
            $lines[] = $this->line('recurring', $c['label'], null, $c['amount_cents'], $c['source']);
        }

        $utility = $this->utilities->linesFor($agreement, $cycle);
        foreach ($utility['lines'] as $u) {
            $bill = $u['source'];
            $metered = $bill instanceof UtilityBill && $bill->current_reading_e3 !== null;
            $lines[] = $this->line('utility', $u['label'], null, $u['amount_cents'], $bill,
                $metered ? $bill->current_reading_e3 - ($bill->previous_reading_e3 ?? 0) : null, $metered ? $bill->rate_e4 : null);
        }
        foreach ($utility['needs_input'] as $type) {
            $flags[] = "needs_utility_input:{$type}";
        }
        foreach ($utility['no_occupants'] as $type) {
            $flags[] = "no_occupants:{$type}";
        }

        $month = $cycle->periodStart;
        $account = $agreement->billingAccount;
        $first = $account->agreements->min('id') === $agreement->id;
        foreach ($this->oneTime->pendingFor($account, $month->year, $month->month) as $c) {
            if ($c->rental_agreement_id === $agreement->id || ($c->rental_agreement_id === null && $first)) {
                $lines[] = $this->line('one_time', $c->label, null, $c->signed_cents, $c);
            }
        }

        return ['lines' => $lines, 'flags' => $flags, 'needs_review' => $flags !== []];
    }

    private function line(string $category, string $label, ?string $description, int $cents, mixed $source, ?int $qty = null, ?int $rate = null): array
    {
        return ['category' => $category, 'label' => $label, 'description' => $description, 'qty_e3' => $qty, 'unit_rate_e4' => $rate, 'amount_cents' => $cents, 'source' => $source];
    }

    /** @return Collection<int, RentalAgreement> */
    private function agreements(int $year, int $month, ?int $propertyId, ?array $agreementIds, ?User $for): Collection
    {
        $first = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Asia/Manila');

        return RentalAgreement::query()
            ->with(['unit', 'bed', 'replacedBy', 'billingAccount.payer', 'billingAccount.agreements:id,billing_account_id'])
            // Wide date filter only narrows the query; the resolver decides who actually covers days.
            ->whereDate('start_date', '<=', $first->addMonthsNoOverflow(2)->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $first->toDateString()))
            ->when($agreementIds !== null, fn ($q) => $q->whereIn('id', $agreementIds))
            ->whereHas('unit', function ($q) use ($propertyId, $for) {
                $q->when($propertyId, fn ($q) => $q->where('property_id', $propertyId));
                if ($for !== null && $for->role === Role::Staff) {
                    $q->whereIn('property_id', $for->properties()->pluck('properties.id'));
                }
            })
            ->orderBy('unit_id')->orderBy('id')
            ->get();
    }

    /** @param  list<PreviewRow>  $rows */
    private function summary(array $rows): array
    {
        $counts = array_fill_keys(['eligible', 'already_billed', 'needs_review', 'skipped'], 0);
        foreach ($rows as $r) {
            $counts[$r->status]++;
        }

        $new = array_sum(array_map(fn ($r) => $r->currentChargesCents, $rows));
        $previous = array_sum(array_map(fn ($r) => $r->previousBalanceCents, $rows));

        return [
            'counts' => $counts,
            'total_new_charges_cents' => $new,
            'total_previous_outstanding_cents' => $previous,
            'estimated_statement_total_cents' => $new + $previous,
        ];
    }
}
