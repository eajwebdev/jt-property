<?php

namespace App\Services\Billing;

use App\Enums\Role;
use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AgingReportService
{
    public function __construct(private PaymentService $payments) {}

    /** @return array{rows:Collection,totals:array<string,int>,net_receivable_cents:int} */
    public function forUser(User $user, ?int $propertyId = null): array
    {
        $propertyIds = $user->role === Role::Staff ? $user->properties()->pluck('properties.id')->all() : null;
        $accounts = BillingAccount::query()
            ->when($propertyIds !== null, fn ($query) => $query->whereIn('property_id', $propertyIds))
            ->when($propertyId !== null, fn ($query) => $query->where('property_id', $propertyId))
            ->with(['payer:id,full_name', 'property:id,name'])->orderBy('id')->get();
        $ids = $accounts->pluck('id')->all();
        $balances = LedgerEntry::whereIn('billing_account_id', $ids)
            ->selectRaw('billing_account_id, SUM(amount_cents) as balance_cents')
            ->groupBy('billing_account_id')->pluck('balance_cents', 'billing_account_id');
        $debits = $this->payments->openDebits($ids)->groupBy('billing_account_id');
        $buckets = ['current', '1-30', '31-60', '61-90', '90+'];
        $totals = array_fill_keys($buckets, 0);

        $rows = $accounts->map(function (BillingAccount $account) use ($balances, $debits, $buckets, &$totals): array {
            $amounts = array_fill_keys($buckets, 0);
            foreach ($debits->get($account->id, collect()) as $debit) {
                $daysPastDue = (int) Carbon::parse($debit->due_date)->diffInDays(today(), false);
                $bucket = match (true) {
                    $daysPastDue <= 0 => 'current',
                    $daysPastDue <= 30 => '1-30',
                    $daysPastDue <= 60 => '31-60',
                    $daysPastDue <= 90 => '61-90',
                    default => '90+',
                };
                $amounts[$bucket] += $debit->remaining_cents;
                $totals[$bucket] += $debit->remaining_cents;
            }

            return [
                'account_id' => $account->id,
                'account_no' => $account->account_no,
                'tenant' => $account->payer->full_name,
                'property' => $account->property->name,
                'property_id' => $account->property_id,
                'balance_cents' => (int) ($balances[$account->id] ?? 0),
                'open_cents' => array_sum($amounts),
                'oldest_due' => $debits->get($account->id, collect())->min('due_date'),
                'buckets' => $amounts,
            ];
        })->filter(fn (array $row) => $row['open_cents'] > 0 || $row['balance_cents'] !== 0)->values();

        return ['rows' => $rows, 'totals' => $totals, 'net_receivable_cents' => $rows->sum('balance_cents')];
    }
}
