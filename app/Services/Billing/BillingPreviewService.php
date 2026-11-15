<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\BillingAccount;
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
        // Every invoice (any status) of these agreements for their cycle: finalized ones make the row
        // already_billed; all of them are left out of the previous balance so a bill never counts itself.
        $invoices = Invoice::with('lineItems')->whereIn('rental_agreement_id', $cycles->keys())->get()
            ->filter(fn (Invoice $i) => $cycles->get($i->rental_agreement_id)?->periodStart->toDateString() === $i->period_start->toDateString())
            ->groupBy('rental_agreement_id');

        $rows = [];
        foreach ($agreements as $a) {
            $cycle = $cycles->get($a->id);
            if ($cycle === null) {
                continue;
            }

            $own = $invoices->get($a->id, collect());
            $stored = $own->first(fn (Invoice $i) => $i->status === InvoiceStatus::Finalized);
            $previous = $this->ledger->previousBalance($a->billingAccount, $cycle->periodStart, $own->pluck('id')->all());

            if ($stored !== null) {
                $lines = $stored->lineItems->map(fn ($l) => $this->line($l->category, $l->label, $l->description, $l->amount_cents, null, $l->qty_e3, $l->unit_rate_e4))->all();
                $current = $stored->current_charges_cents;
                $flags = ['existing_invoice'];
                $review = false;
            } else {
                $built = $this->linesForAgreement($a, $cycle);
                $lines = $built['lines'];
                $current = array_sum(array_column($lines, 'amount_cents'));
                $flags = $built['flags'];
                $review = $built['needs_review'];
            }

            $email = $a->billingAccount->payer?->email;
            if ($email === null || trim($email) === '') {
                $flags[] = 'no_email';
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
                lines: $lines,
                currentChargesCents: $current,
                previousBalanceCents: $previous,
                estimatedTotalCents: $previous + $current,
                status: $stored !== null ? 'already_billed' : ($review ? 'needs_review' : 'eligible'),
                flags: $flags,
                invoiceNumber: $stored?->number,
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
        $owner = $this->accountChargeAgreementId($account, $month->year, $month->month);
        foreach ($this->oneTime->pendingFor($account, $month->year, $month->month) as $c) {
            if ($c->rental_agreement_id === $agreement->id || ($c->rental_agreement_id === null && $owner === $agreement->id)) {
                $lines[] = $this->line('one_time', $c->label, null, $c->signed_cents, $c);
            }
        }

        return ['lines' => $lines, 'flags' => $flags, 'needs_review' => $flags !== []];
    }

    /**
     * The agreement that carries an account's one-time charges not tied to an agreement: the lowest-id
     * agreement of the account with a cycle in this month, whatever the preview filters. In a transfer
     * month that is the old agreement (it was created first), so the charge is billed once, on it.
     */
    public function accountChargeAgreementId(BillingAccount $account, int $year, int $month): ?int
    {
        return RentalAgreement::where('billing_account_id', $account->id)->orderBy('id')->get()
            ->first(fn (RentalAgreement $a) => $this->cycles->cycleFor($a, $year, $month) !== null)?->id;
    }

    private function line(string $category, string $label, ?string $description, int $cents, mixed $source, ?int $qty = null, ?int $rate = null): array
    {
        return ['category' => $category, 'label' => $label, 'description' => $description, 'qty_e3' => $qty, 'unit_rate_e4' => $rate, 'amount_cents' => $cents, 'source' => $source];
    }

    /** @return Collection<int, RentalAgreement> */
    public function agreements(int $year, int $month, ?int $propertyId, ?array $agreementIds, ?User $for): Collection
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
        // Rows of one billing account share one balance; count it once.
        $previous = array_sum(array_column(array_map(fn ($r) => ['a' => $r->accountId, 'p' => $r->previousBalanceCents], $rows), 'p', 'a'));

        return [
            'counts' => $counts,
            'total_new_charges_cents' => $new,
            'total_previous_outstanding_cents' => $previous,
            'estimated_statement_total_cents' => $new + $previous,
        ];
    }
}
