<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\OpeningBalance;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Builds the numbers of a monthly statement.
 *
 *   total_due = previous + current charges + payments/credits since period start
 *
 * Previous balance counts only entries BEFORE the period; payments since counts only entries ON/AFTER it,
 * so a payment is never subtracted twice. This cycle's own charge (and its reversal) is left out of both.
 */
class StatementComposer
{
    /** Ledger entry types that reduce (or restore) what is owed, as opposed to new charges. */
    private const NON_CHARGE = [
        LedgerEntryType::Payment, LedgerEntryType::Credit, LedgerEntryType::Adjustment,
        LedgerEntryType::Refund, LedgerEntryType::Writeoff, LedgerEntryType::Reversal,
    ];

    public function __construct(private ReceivableLedgerService $ledger) {}

    public function forInvoice(Invoice $invoice, ?CarbonInterface $asOf = null): Statement
    {
        $asOf = CarbonImmutable::parse(($asOf ?? now())->toDateString());
        $account = $invoice->billingAccount;
        $periodStart = $invoice->period_start;

        $cycleIds = Invoice::where('billing_account_id', $account->id)
            ->whereDate('period_start', $periodStart->toDateString())
            ->pluck('id')->all();

        $previous = $this->ledger->previousBalance($account, $periodStart, $cycleIds);

        $payments = LedgerEntry::where('billing_account_id', $account->id)
            ->whereIn('type', self::NON_CHARGE)
            ->whereDate('effective_date', '>=', $periodStart->toDateString())
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->where(fn ($q) => $q->whereNull('invoice_id')->orWhereNotIn('invoice_id', $cycleIds))
            ->orderBy('effective_date')->orderBy('id')
            ->get()
            ->map(fn (LedgerEntry $e) => $this->row($e))->all();

        $recent = LedgerEntry::where('billing_account_id', $account->id)
            ->where('type', LedgerEntryType::Payment)
            ->whereDate('effective_date', '<', $periodStart->toDateString())
            ->orderByDesc('effective_date')->orderByDesc('id')->limit(3)
            ->get()->map(fn (LedgerEntry $e) => $this->row($e))->all();

        $opening = OpeningBalance::where('billing_account_id', $account->id)
            ->whereNotNull('ledger_entry_id')->whereNull('reversed_at')->whereNotNull('breakdown')
            ->latest('id')->first()?->breakdown;

        return $this->build($invoice, $asOf, $previous, array_sum(array_column($payments, 'amount_cents')), $payments, $recent, $opening);
    }

    /**
     * Rebuild a historical statement from the totals frozen in its snapshot, never from the live ledger.
     *
     * @param  array<string, mixed>  $totals  StatementSnapshot::totals
     */
    public function fromSnapshot(Invoice $invoice, array $totals): Statement
    {
        $s = $this->build(
            $invoice, CarbonImmutable::parse($totals['as_of']), $totals['previous'], $totals['payments_since'],
            $totals['payments'] ?? [], $totals['recent_payments'] ?? [], $totals['opening_breakdown'] ?? null,
        );

        return new Statement(...array_merge(get_object_vars($s), [
            'currentCents' => $totals['current'],
            'totalDueCents' => $totals['total_due'],
            'pastDueCents' => $totals['past_due'],
            'isCredit' => $totals['total_due'] < 0,
        ]));
    }

    public function forPeriod(BillingAccount $account, CarbonInterface $from, CarbonInterface $to): AccountStatement
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());

        $opening = $this->ledger->balance($account, $from->subDay());
        $balance = $opening;
        $rows = [];

        $entries = LedgerEntry::where('billing_account_id', $account->id)
            ->whereDate('effective_date', '>=', $from->toDateString())
            ->whereDate('effective_date', '<=', $to->toDateString())
            ->orderBy('effective_date')->orderBy('id')->get();

        foreach ($entries as $e) {
            $balance += $e->amount_cents;
            $rows[] = $this->row($e) + ['balance_cents' => $balance];
        }

        return new AccountStatement($account->id, $from, $to, $opening, $rows, $balance);
    }

    /** @param list<array<string, mixed>> $payments @param list<array<string, mixed>> $recent */
    private function build(Invoice $invoice, CarbonImmutable $asOf, int $previous, int $paymentsSince, array $payments, array $recent, ?array $opening): Statement
    {
        $invoice->loadMissing(['billingAccount.payer', 'property', 'lineItems', 'rentalAgreement.unit', 'rentalAgreement.bed']);
        $total = $previous + $invoice->current_charges_cents + $paymentsSince;
        $unit = $invoice->rentalAgreement->unit;
        $bed = $invoice->rentalAgreement->bed;

        return new Statement(
            invoiceId: $invoice->id,
            number: $invoice->number,
            isVoid: $invoice->status === InvoiceStatus::Void,
            voidReason: $invoice->void_reason,
            issueDate: CarbonImmutable::parse($invoice->issue_date),
            dueDate: CarbonImmutable::parse($invoice->due_date),
            periodStart: CarbonImmutable::parse($invoice->period_start),
            periodEnd: CarbonImmutable::parse($invoice->period_end),
            asOf: $asOf,
            accountNo: $invoice->billingAccount->account_no,
            tenantName: $invoice->billingAccount->payer->full_name,
            propertyName: $invoice->property->name,
            propertyAddress: $invoice->property->address,
            unitLabel: $unit->label.($bed ? ' / '.$bed->label : ''),
            lines: $invoice->lineItems->map(fn ($l) => [
                'category' => $l->category, 'label' => $l->label, 'description' => $l->description,
                'qty_e3' => $l->qty_e3, 'unit_rate_e4' => $l->unit_rate_e4, 'amount_cents' => $l->amount_cents,
            ])->all(),
            previousCents: $previous,
            payments: $payments,
            paymentsSinceCents: $paymentsSince,
            currentCents: $invoice->current_charges_cents,
            totalDueCents: $total,
            // What is still unpaid from before this bill; payments are applied to the oldest money first.
            pastDueCents: max(0, min($previous, $previous + $paymentsSince)),
            isCredit: $total < 0,
            recentPayments: $recent,
            openingBreakdown: $opening,
        );
    }

    /** @return array{date:string,type:string,memo:?string,amount_cents:int} */
    private function row(LedgerEntry $e): array
    {
        return [
            'date' => $e->effective_date->toDateString(),
            'type' => $e->type->value,
            'memo' => $e->memo,
            'amount_cents' => $e->amount_cents,
        ];
    }
}
