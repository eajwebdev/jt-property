<?php

namespace App\Services\Billing;

use App\Enums\DepositStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\LedgerEntry;
use App\Models\OneTimeCharge;
use App\Models\OpeningBalance;
use App\Models\Property;
use App\Models\RecurringCharge;
use App\Models\RentalAgreement;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The owner's "Statement of Accounts" sheet: every boarder of one property on one line, with what is
 * still unpaid split into rent (BH), actual electric consumption (EB), one month advance, one month
 * deposit, door key deposit and others, plus a remark naming the unpaid months.
 *
 * A bill is one ledger charge, so the paid part of a bill is applied to its lines in the order they
 * are printed on the bill (rent first). Columns therefore always add up to what the boarder owes.
 */
class StatementOfAccountsReport
{
    public const COLUMNS = ['rent', 'electric', 'advance', 'deposit', 'key', 'others'];

    public function __construct(private PaymentService $payments) {}

    /**
     * @return array{property: Property, as_of: Carbon, sections: array<string, Collection<int, array<string, mixed>>>, totals: array<string, int>}
     */
    public function build(Property $property, ?Carbon $asOf = null): array
    {
        $asOf ??= $this->defaultAsOf($property);
        $accounts = BillingAccount::where('property_id', $property->id)
            ->with(['payer:id,full_name', 'agreements.unit:id,code,building', 'agreements.bed:id,label'])
            ->get();
        $ids = $accounts->pluck('id')->all();

        // Balances as they stood at the end of $asOf: later bills and payments are left out.
        $debits = $this->payments->openDebitsAsOf($ids, $asOf);
        $invoices = Invoice::whereIn('id', $debits->pluck('invoice_id')->filter()->unique())
            ->with(['lineItems' => fn ($q) => $q->orderBy('sort'), 'lineItems.source' => fn (MorphTo $m) => $m->morphWith([
                OneTimeCharge::class => ['category'], RecurringCharge::class => ['category'],
            ])])->get()->keyBy('id');
        $openings = OpeningBalance::whereIn('ledger_entry_id', $debits->where('type', LedgerEntryType::Opening)->pluck('id'))->get()->keyBy('ledger_entry_id');
        $balances = LedgerEntry::whereIn('billing_account_id', $ids)->where('effective_date', '<=', $asOf->toDateString())->selectRaw('billing_account_id, SUM(amount_cents) as cents')
            ->groupBy('billing_account_id')->pluck('cents', 'billing_account_id');
        $withDeposit = BillingAccount::whereIn('id', $ids)->whereHas('deposits', fn ($q) => $q->where('status', DepositStatus::Held))->pluck('id')->flip();

        $active = $accounts->flatMap->agreements->filter(fn (RentalAgreement $a) => $a->start_date->lte($asOf) && ($a->end_date === null || $a->end_date->gte($asOf)));
        $rents = RentalAgreement::currentRents($active, $asOf);

        // Current boarders only (owner's choice): accounts without an active rental are not listed on the sheet.
        // Their unpaid balances stay on their accounts (Collections, aging report) and are not lost.
        $current = collect();
        foreach ($accounts as $account) {
            if ($active->where('billing_account_id', $account->id)->isEmpty()) {
                continue;
            }
            $row = $this->row($account, $debits->where('billing_account_id', $account->id), $invoices, $openings);
            $credit = max(0, $row['total'] - (int) ($balances[$account->id] ?? 0));
            $mine = $active->where('billing_account_id', $account->id);

            $remarks = $row['remarks'];
            if ($credit > 0) {
                $remarks[] = 'less '.Money::format($credit).' unapplied payment';
            }
            if ($mine->isNotEmpty() && ! $withDeposit->has($account->id)) {
                $remarks[] = '(no deposit)';
            }
            // A remark written on a paper sheet (e.g. the imported October 2026 sheet) is printed word for word on that
            // month's sheet; other months write their own from the unpaid months.
            if ($account->soa_remarks !== null && $account->soa_remarks_as_of?->format('Y-m') === $asOf->format('Y-m')) {
                $remarks = [$account->soa_remarks];
            }

            $shown = $mine->isNotEmpty() ? $mine : $account->agreements->sortByDesc('start_date')->take(1);
            $line = [
                'account_no' => $account->account_no,
                'name' => $account->payer->full_name,
                'room' => $shown->map(fn (RentalAgreement $a) => $this->room($a))->join('; '),
                'rate_cents' => $mine->isNotEmpty() ? $mine->sum(fn (RentalAgreement $a) => (int) ($rents[$a->id] ?? 0)) : null,
                'columns' => $row['columns'],
                'total_cents' => $row['total'],
                'remarks' => implode(' ', $remarks),
            ];

            $current->push($line);
        }

        $sections = array_filter(['Current Boarders' => $current->sortBy(fn ($r) => mb_strtolower($r['name']))->values()], fn ($rows) => $rows->isNotEmpty());
        $totals = collect(self::COLUMNS)->mapWithKeys(fn ($c) => [$c => $current->sum("columns.$c")])->all();
        $totals['rate'] = $current->sum('rate_cents');
        $totals['total'] = $current->sum('total_cents');

        return ['property' => $property, 'as_of' => $asOf, 'sections' => $sections, 'totals' => $totals];
    }

    /**
     * @param  Collection<int, LedgerEntry>  $debits
     * @return array{columns: array<string, int>, total: int, remarks: list<string>}
     */
    private function row(BillingAccount $account, Collection $debits, Collection $invoices, Collection $openings): array
    {
        $columns = array_fill_keys(self::COLUMNS, 0);
        $months = ['rent' => [], 'electric' => []];
        $labels = [];

        foreach ($debits as $debit) {
            $invoice = $debit->invoice_id ? $invoices->get($debit->invoice_id) : null;
            if ($invoice === null) {
                $opening = $openings->get($debit->id);
                $lines = collect($opening?->breakdown ?? [])->filter(fn ($l) => in_array($l['category'] ?? null, ['rent', 'electric'], true));
                if ($lines->isEmpty()) {
                    $columns['others'] += $debit->remaining_cents;
                    $labels[] = $this->openingLabel($opening);

                    continue;
                }
                // Categorised brought-forward balance (e.g. imported from the paper Statement of Accounts): payments
                // settle the oldest month first, what is left lands in the BH / EB column with its months.
                $rest = collect($opening->breakdown)->reject(fn ($l) => in_array($l['category'] ?? null, ['rent', 'electric'], true));
                $covered = (int) $opening->amount_cents - $debit->remaining_cents;
                foreach ($lines->sortBy(fn ($l) => ($l['from'] ?? $l['month']).($l['category'] === 'rent' ? '0' : '1'))->concat($rest) as $line) {
                    $amount = (int) $line['amount_cents'];
                    $unpaid = $amount - min($covered, $amount);
                    $covered = max(0, $covered - $amount);
                    if ($unpaid <= 0) {
                        continue;
                    }
                    $column = $line['category'] ?? 'others';
                    if (! isset($months[$column])) {
                        $columns['others'] += $unpaid;
                        $labels[] = $line['note'] ?? 'Balance brought forward';

                        continue;
                    }
                    $columns[$column] += $unpaid;
                    $span = $this->monthSpan($line['from'] ?? $line['month'], $line['month']);
                    foreach ($span as $i => $m) {
                        $months[$column][$m] = ($months[$column][$m] ?? false) || ($i === 0 && (($line['partial'] ?? false) || $unpaid < $amount));
                    }
                }

                continue;
            }

            $month = $invoice->period_start->format('Y-m');
            $lines = $invoice->lineItems->where('amount_cents', '>', 0);
            // Paid part (including credit lines on the bill) is consumed in printed order.
            $covered = $lines->sum('amount_cents') - $debit->remaining_cents;
            foreach ($lines as $line) {
                $unpaid = $line->amount_cents - min($covered, $line->amount_cents);
                $covered = max(0, $covered - $line->amount_cents);
                if ($unpaid <= 0) {
                    continue;
                }
                $column = $this->column($line);
                $columns[$column] += $unpaid;
                // Electricity is billed a month in arrears: the October bill carries September's consumption ("September 2026 EB").
                $period = $column === 'electric' ? $invoice->period_start->copy()->subMonthNoOverflow()->format('Y-m') : $month;
                if (isset($months[$column])) {
                    $months[$column][$period] = ($months[$column][$period] ?? false) || $unpaid < $line->amount_cents;
                } else {
                    $labels[] = $line->label;
                }
            }
        }

        $parts = array_filter([
            $months['rent'] ? $this->periods($months['rent']).' BH' : null,
            $months['electric'] ? $this->periods($months['electric']).' EB' : null,
        ]);
        $remarks = $parts ? [implode(' & ', $parts).' accounts'] : [];
        if ($labels !== []) {
            $remarks[] = ($remarks ? '+ ' : '').implode(', ', array_unique($labels));
        }

        return ['columns' => $columns, 'total' => array_sum($columns), 'remarks' => $remarks];
    }

    private function column(InvoiceLineItem $line): string
    {
        if ($line->category === 'rent') {
            return 'rent';
        }
        $source = $line->source;
        if ($line->category === 'utility') {
            return $source?->type === 'electricity' ? 'electric' : 'others';
        }
        $name = mb_strtolower(($source instanceof OneTimeCharge || $source instanceof RecurringCharge ? $source->category?->name : null) ?? $line->label);

        return match (true) {
            str_contains($name, 'electric') => 'electric', // e.g. a one-time "Electricity" charge (imported or manual EB)
            str_contains($name, 'advance') => 'advance',
            str_contains($name, 'key') && str_contains($name, 'deposit') => 'key',
            str_contains($name, 'deposit') => 'deposit',
            default => 'others',
        };
    }

    /**
     * The date the sheet is "as of" when none is chosen: the due date of the latest bill already due (5 Oct 2026
     * while October is the last month billed), so a statement printed mid-November without November bills still
     * reads like the October sheet. Today when nothing has been billed yet.
     */
    public function defaultAsOf(Property $property): Carbon
    {
        $due = Invoice::where('property_id', $property->id)->where('status', InvoiceStatus::Finalized)
            ->whereDate('due_date', '<=', today()->toDateString())->max('due_date');

        return $due ? Carbon::parse($due)->startOfDay() : today();
    }

    /** @return list<string> every 'Y-m' from $from to $to inclusive (just $to when they are reversed) */
    private function monthSpan(string $from, string $to): array
    {
        $cursor = Carbon::createFromFormat('!Y-m', min($from, $to));
        $end = Carbon::createFromFormat('!Y-m', $to);
        $out = [];
        while ($cursor->lte($end) && count($out) < 120) {
            $out[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        return $out;
    }

    private function openingLabel(?OpeningBalance $opening): string
    {
        $months = collect($opening?->breakdown ?? [])->pluck('month')->filter()->mapWithKeys(fn ($m) => [$m => false])->all();

        return 'Balance brought forward'.($months ? ' ('.$this->periods($months).')' : '');
    }

    /**
     * "September-October 2026", "Balance of September + October 2026", "December 2025-January 2026".
     *
     * @param  array<string, bool>  $months  'Y-m' => partly paid
     */
    public function periods(array $months): string
    {
        ksort($months);
        $keys = array_keys($months);
        $prefix = '';
        if (count($keys) > 1 && $months[$keys[0]]) {
            $first = Carbon::createFromFormat('!Y-m', array_shift($keys));
            $sameYear = $first->year === Carbon::createFromFormat('!Y-m', end($keys))->year;
            $prefix = 'Balance of '.$first->format($sameYear ? 'F' : 'F Y').' + ';
        } elseif (count($keys) === 1 && $months[$keys[0]]) {
            $prefix = 'Balance of ';
        }

        $runs = [];
        foreach ($keys as $key) {
            $date = Carbon::createFromFormat('!Y-m', $key);
            $last = $runs === [] ? null : $runs[count($runs) - 1][1];
            if ($last !== null && $last->copy()->addMonth()->eq($date)) {
                $runs[count($runs) - 1][1] = $date;
            } else {
                $runs[] = [$date, $date];
            }
        }

        return $prefix.collect($runs)->map(fn ($r) => match (true) {
            $r[0]->eq($r[1]) => $r[0]->format('F Y'),
            $r[0]->year === $r[1]->year => $r[0]->format('F').'-'.$r[1]->format('F Y'),
            default => $r[0]->format('F Y').'-'.$r[1]->format('F Y'),
        })->join(', ');
    }

    private function room(RentalAgreement $agreement): string
    {
        // As on the paper sheet: building and room only ("Creekside House - Room #5"), no bed.
        return $agreement->unit->label;
    }
}
