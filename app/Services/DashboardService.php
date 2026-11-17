<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\UnitStatus;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\LedgerEntry;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Billing\PaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the dashboard's Income, Late dues and Occupancy tabs. Everything is scoped to
 * $propertyIds (null = every property, for the owner). Money is integer centavos.
 */
class DashboardService
{
    /** The latest month that has bills (e.g. "2026-10"), or null when nothing is billed yet. */
    public function latestBillMonth(?array $propertyIds): ?string
    {
        $start = Invoice::where('status', InvoiceStatus::Finalized)
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->max('period_start');

        return $start ? Carbon::parse($start)->format('Y-m') : null;
    }

    /**
     * "This month's bills": for every bill of the latest billed month, how much of it is paid.
     * unpaid = nothing paid on it · partial = part paid · paid = paid in full · advance = paid in full and the account
     * has credit left (paid ahead). A bill still owing after its due date is overdue. Unpaid rows come first.
     *
     * @return array{month: ?string, due_date: ?string, counts: array<string, int>, billed_cents: int, collected_cents: int, unpaid_cents: int, rows: list<array<string, mixed>>}
     */
    public function billPayments(?array $propertyIds): array
    {
        $empty = ['month' => null, 'due_date' => null, 'counts' => ['unpaid' => 0, 'partial' => 0, 'paid' => 0, 'advance' => 0], 'billed_cents' => 0, 'collected_cents' => 0, 'unpaid_cents' => 0, 'rows' => []];
        $month = $this->latestBillMonth($propertyIds);
        if ($month === null) {
            return $empty;
        }

        $invoices = Invoice::where('status', InvoiceStatus::Finalized)
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->whereDate('period_start', '>=', $month.'-01')->whereDate('period_start', '<=', Carbon::parse($month.'-01')->endOfMonth()->toDateString())
            // Nothing to collect on a ₱0 bill (free stay) or a credit-only bill: not listed.
            ->where('current_charges_cents', '>', 0)
            ->with(['billingAccount.payer:id,full_name,email', 'rentalAgreement.unit:id,code,building', 'rentalAgreement.bed:id,label', 'property:id,name'])
            ->get();
        $accountIds = $invoices->pluck('billing_account_id')->unique()->values()->all();
        // What is still unpaid on each bill (bills not listed here are paid in full).
        $open = app(PaymentService::class)->openDebits($accountIds)->whereNotNull('invoice_id')->groupBy('invoice_id')->map->sum('remaining_cents');
        $balances = LedgerEntry::whereIn('billing_account_id', $accountIds)->selectRaw('billing_account_id, SUM(amount_cents) as cents')
            ->groupBy('billing_account_id')->pluck('cents', 'billing_account_id');
        $lastPaid = Payment::whereIn('billing_account_id', $accountIds)->where('status', 'approved')
            ->selectRaw('billing_account_id, MAX(paid_on) as paid_on')->groupBy('billing_account_id')->pluck('paid_on', 'billing_account_id');
        $today = today()->toDateString();

        $rows = $invoices->map(function (Invoice $invoice) use ($open, $balances, $lastPaid, $today) {
            $amount = max(0, $invoice->current_charges_cents);
            $remaining = min($amount, (int) ($open[$invoice->id] ?? 0));
            $balance = (int) ($balances[$invoice->billing_account_id] ?? 0);
            $status = match (true) {
                $remaining <= 0 && $balance < 0 => 'advance',
                $remaining <= 0 => 'paid',
                $remaining < $amount => 'partial',
                default => 'unpaid',
            };
            $agreement = $invoice->rentalAgreement;

            return [
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'account_id' => $invoice->billing_account_id,
                'account_no' => $invoice->billingAccount->account_no,
                'tenant' => $invoice->billingAccount->payer->full_name,
                'email' => $invoice->billingAccount->payer->email,
                'room' => $agreement->unit->label.($agreement->bed ? ' · '.$agreement->bed->label : ''),
                'property' => $invoice->property->name,
                'due_date' => $invoice->due_date->toDateString(),
                'bill_cents' => $amount,
                'paid_cents' => $amount - $remaining,
                'remaining_cents' => $remaining,
                'balance_cents' => $balance,
                'status' => $status,
                'overdue' => $remaining > 0 && $invoice->due_date->toDateString() < $today,
                'last_paid_on' => isset($lastPaid[$invoice->billing_account_id]) ? Carbon::parse($lastPaid[$invoice->billing_account_id])->toDateString() : null,
            ];
        });

        $order = ['unpaid' => 0, 'partial' => 1, 'paid' => 2, 'advance' => 3];
        $rows = $rows->sortBy(fn ($r) => sprintf('%d|%s', $order[$r['status']], mb_strtolower($r['tenant'])))->values();

        return [
            'month' => $month,
            'due_date' => $rows->min('due_date'),
            'counts' => array_merge($empty['counts'], $rows->countBy('status')->all()),
            'billed_cents' => (int) $rows->sum('bill_cents'),
            'collected_cents' => (int) $rows->sum('paid_cents'),
            'unpaid_cents' => (int) $rows->sum('remaining_cents'),
            'rows' => $rows->all(),
        ];
    }

    /** How many bills of the latest billed month are not fully paid (for the tab label). */
    public function billsNotFullyPaid(?array $propertyIds, ?string $month, Collection $openDebits): int
    {
        if ($month === null) {
            return 0;
        }
        $ids = Invoice::where('status', InvoiceStatus::Finalized)
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('property_id', $propertyIds))
            ->whereDate('period_start', '>=', $month.'-01')->whereDate('period_start', '<=', Carbon::parse($month.'-01')->endOfMonth()->toDateString())
            ->pluck('id');

        return $openDebits->whereNotNull('invoice_id')->whereIn('invoice_id', $ids)->pluck('invoice_id')->unique()->count();
    }

    /** Billed vs collected for the last 12 months, plus this month's split by method, property and charge type. */
    public function income(?array $propertyIds): array
    {
        $from = today()->startOfMonth()->subMonths(11);
        $to = today()->endOfMonth();
        $scope = fn ($query) => $query->when($propertyIds !== null, fn ($q) => $q->whereIn('property_id', $propertyIds));

        $billed = Invoice::query()->tap($scope)->where('status', InvoiceStatus::Finalized)->whereBetween('issue_date', [$from->toDateString(), $to->toDateString()])
            ->get(['issue_date', 'current_charges_cents'])->groupBy(fn (Invoice $i) => $i->issue_date->format('Y-m'))->map->sum('current_charges_cents');
        $collected = Payment::query()->tap($scope)->where('status', 'approved')->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])
            ->get(['paid_on', 'amount_cents'])->groupBy(fn (Payment $p) => Carbon::parse($p->paid_on)->format('Y-m'))->map->sum('amount_cents');

        $months = collect(range(0, 11))->map(function (int $i) use ($from, $billed, $collected) {
            $month = $from->copy()->addMonths($i);
            $key = $month->format('Y-m');

            return ['month' => $key, 'label' => $month->format('M'), 'billed_cents' => (int) ($billed[$key] ?? 0), 'collected_cents' => (int) ($collected[$key] ?? 0)];
        })->values();

        $monthStart = today()->startOfMonth()->toDateString();
        $monthEnd = today()->endOfMonth()->toDateString();
        $paymentsThisMonth = Payment::query()->tap($scope)->where('status', 'approved')->whereBetween('paid_on', [$monthStart, $monthEnd]);
        $invoicesThisMonth = Invoice::query()->tap($scope)->where('status', InvoiceStatus::Finalized)->whereBetween('issue_date', [$monthStart, $monthEnd]);
        $properties = Property::query()->when($propertyIds !== null, fn ($q) => $q->whereIn('id', $propertyIds))->orderBy('name')->get(['id', 'name']);
        $billedByProperty = (clone $invoicesThisMonth)->selectRaw('property_id, SUM(current_charges_cents) as cents')->groupBy('property_id')->pluck('cents', 'property_id');
        $collectedByProperty = (clone $paymentsThisMonth)->selectRaw('property_id, SUM(amount_cents) as cents')->groupBy('property_id')->pluck('cents', 'property_id');

        return [
            'months' => $months,
            'by_method' => (clone $paymentsThisMonth)->selectRaw('method, COUNT(*) as count, SUM(amount_cents) as cents')->groupBy('method')->orderByDesc('cents')->get()
                ->map(fn ($r) => ['method' => $r->method, 'count' => (int) $r->count, 'cents' => (int) $r->cents])->values(),
            'by_property' => $properties->map(fn (Property $p) => [
                'id' => $p->id, 'name' => $p->name,
                'billed_cents' => (int) ($billedByProperty[$p->id] ?? 0),
                'collected_cents' => (int) ($collectedByProperty[$p->id] ?? 0),
            ])->filter(fn ($r) => $r['billed_cents'] > 0 || $r['collected_cents'] > 0)->values(),
            'by_category' => InvoiceLineItem::query()->whereIn('invoice_id', (clone $invoicesThisMonth)->select('id'))
                ->selectRaw('category, SUM(amount_cents) as cents')->groupBy('category')->orderByDesc('cents')->get()
                ->map(fn ($r) => ['category' => $r->category, 'cents' => (int) $r->cents])->values(),
            'payments_count' => (clone $paymentsThisMonth)->count(),
        ];
    }

    /**
     * Late dues: open charges past their due date, counted by account and by bill, bucketed by days late.
     *
     * @param  Collection<int, object>  $debits  open debits (PaymentService::openDebits) of the accounts in scope
     */
    public function late(Collection $debits, ?array $propertyIds): array
    {
        $today = today();
        $pastDue = $debits->filter(fn ($d) => $d->due_date < $today->toDateString());
        $accounts = BillingAccount::whereIn('id', $pastDue->pluck('billing_account_id')->unique())->with(['payer:id,full_name', 'property:id,name'])->get()->keyBy('id');
        $bucketOf = fn (int $days) => match (true) {
            $days <= 30 => '1-30',
            $days <= 60 => '31-60',
            $days <= 90 => '61-90',
            default => '90+',
        };

        $rows = $pastDue->groupBy('billing_account_id')->map(function (Collection $items, $accountId) use ($accounts, $debits, $today) {
            $oldest = $items->min('due_date');
            $account = $accounts->get($accountId);

            return [
                'account_id' => (int) $accountId,
                'account_no' => $account?->account_no,
                'tenant' => $account?->payer?->full_name ?? '—',
                'property' => $account?->property?->name ?? '—',
                'oldest_due' => $oldest,
                'days_late' => (int) Carbon::parse($oldest)->diffInDays($today),
                'late_bills' => $items->count(),
                'overdue_cents' => (int) $items->sum('remaining_cents'),
                'open_cents' => (int) $debits->where('billing_account_id', $accountId)->sum('remaining_cents'),
            ];
        })->sortByDesc('days_late')->values();

        $buckets = collect(['1-30', '31-60', '61-90', '90+'])->mapWithKeys(fn ($b) => [$b => ['accounts' => 0, 'cents' => 0]])->all();
        foreach ($rows as $row) {
            $bucket = $bucketOf($row['days_late']);
            $buckets[$bucket]['accounts']++;
            $buckets[$bucket]['cents'] += $row['overdue_cents'];
        }

        $soon = $debits->filter(fn ($d) => $d->due_date >= $today->toDateString() && $d->due_date <= $today->copy()->addDays(7)->toDateString());

        // Payments approved this month that settled a bill after its due date.
        $paidLate = DB::table('payment_allocations as pa')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('ledger_entries as le', 'le.id', '=', 'pa.ledger_entry_id')
            ->join('invoices as i', 'i.id', '=', 'le.invoice_id')
            ->whereNull('pa.reversed_at')->where('p.status', 'approved')
            ->whereBetween('p.paid_on', [$today->copy()->startOfMonth()->toDateString(), $today->copy()->endOfMonth()->toDateString()])
            ->whereColumn('p.paid_on', '>', 'i.due_date')
            ->when($propertyIds !== null, fn ($q) => $q->whereIn('p.property_id', $propertyIds))
            ->distinct()->count('p.id');

        return [
            'late_accounts' => $rows->count(),
            'late_bills' => $pastDue->count(),
            'overdue_cents' => (int) $pastDue->sum('remaining_cents'),
            'due_soon_count' => $soon->count(),
            'due_soon_cents' => (int) $soon->sum('remaining_cents'),
            'paid_late_this_month' => $paidLate,
            'buckets' => $buckets,
            'rows' => $rows->take(50)->values(),
        ];
    }

    /** Places per property and the move-ins / move-outs coming in the next 30 days. */
    public function occupancy(?array $propertyIds): array
    {
        $today = today()->toDateString();
        $soon = today()->addDays(30)->toDateString();
        $units = Unit::query()->when($propertyIds !== null, fn ($q) => $q->whereIn('property_id', $propertyIds))->get(['id', 'property_id', 'capacity', 'status']);
        $occupied = Occupancy::activeOn($today)->whereIn('unit_id', $units->pluck('id'))->selectRaw('unit_id, COUNT(*) as n')->groupBy('unit_id')->pluck('n', 'unit_id');
        $properties = Property::query()->when($propertyIds !== null, fn ($q) => $q->whereIn('id', $propertyIds))->whereNull('archived_at')->orderBy('name')->get(['id', 'name']);

        $moves = fn (string $column) => Occupancy::query()->whereIn('unit_id', $units->pluck('id'))->whereBetween($column, [$today, $soon])
            ->with(['tenant:id,full_name', 'unit:id,code,building,property_id'])->orderBy($column)->limit(20)->get()
            ->map(fn (Occupancy $o) => ['id' => $o->id, 'tenant' => $o->tenant?->full_name, 'unit' => $o->unit?->label, 'date' => Carbon::parse($o->{$column})->toDateString()]);

        return [
            'properties' => $properties->map(function (Property $p) use ($units, $occupied) {
                $mine = $units->where('property_id', $p->id);
                $capacity = (int) $mine->sum('capacity');
                $taken = (int) $mine->sum(fn (Unit $u) => (int) ($occupied[$u->id] ?? 0));
                $vacant = (int) $mine->filter(fn (Unit $u) => $u->status === UnitStatus::Available)->sum(fn (Unit $u) => max(0, $u->capacity - (int) ($occupied[$u->id] ?? 0)));

                return ['id' => $p->id, 'name' => $p->name, 'rooms' => $mine->count(), 'capacity' => $capacity, 'occupied' => $taken, 'vacant' => $vacant];
            })->values(),
            'move_ins' => $moves('move_in_date'),
            'move_outs' => $moves('move_out_date'),
        ];
    }
}
