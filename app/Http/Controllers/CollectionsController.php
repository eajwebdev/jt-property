<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Property;
use App\Services\Billing\PaymentService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CollectionsController extends Controller
{
    public const STATUSES = ['unpaid', 'partial', 'overdue', 'credit'];

    public function __construct(private PaymentService $payments) {}

    /** Accounts that owe (or are owed) money, with the oldest due date and an aging bucket. */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Payment::class);

        $user = $request->user();
        $propertyId = $request->integer('property_id') ?: null;
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;

        $accounts = BillingAccount::query()
            ->when($user->role === Role::Staff, fn (Builder $q) => $q->whereIn('property_id', $user->properties()->select('properties.id')))
            ->when($propertyId, fn (Builder $q) => $q->where('property_id', $propertyId))
            ->with(['payer:id,full_name', 'property:id,name', 'agreements' => fn ($q) => $q->orderByRaw("status = 'active' desc")->orderByDesc('id'), 'agreements.unit:id,code,building'])
            ->orderBy('id')->get();

        $ids = $accounts->pluck('id')->all();
        $balances = LedgerEntry::whereIn('billing_account_id', $ids)->groupBy('billing_account_id')
            ->selectRaw('billing_account_id, SUM(amount_cents) as balance')->pluck('balance', 'billing_account_id');
        $debits = $this->payments->openDebits($ids)->groupBy('billing_account_id');
        $today = today();

        $allRows = $accounts->map(function (BillingAccount $a) use ($balances, $debits, $today) {
            $open = $debits->get($a->id, collect());
            $balance = (int) ($balances[$a->id] ?? 0);
            $oldest = $open->min('due_date');
            $days = $oldest ? (int) Carbon::parse($oldest)->diffInDays($today, false) : null;

            return [
                'account_id' => $a->id,
                'account_no' => $a->account_no,
                'tenant' => $a->payer->full_name,
                'property' => $a->property->name,
                'property_id' => $a->property_id,
                'room' => $a->agreements->first()?->unit?->label,
                'balance_cents' => $balance,
                'balance' => Money::format(abs($balance)),
                'is_credit' => $balance < 0,
                'oldest_due' => $oldest,
                'aging' => $oldest === null ? null : $this->bucket($days),
                'flags' => [
                    'unpaid' => $open->isNotEmpty(),
                    'partial' => $open->contains(fn ($d) => $d->remaining_cents < $d->amount_cents),
                    'overdue' => $open->contains(fn ($d) => $d->due_date < $today->toDateString()),
                    'credit' => $balance < 0,
                ],
            ];
        })->filter(fn ($r) => $r['balance_cents'] !== 0 || $r['flags']['unpaid']);

        $rows = $allRows
            ->when($status, fn ($c) => $c->filter(fn ($r) => $r['flags'][$status]))
            ->sortByDesc('balance_cents')
            ->values();

        return Inertia::render('collections/index', [
            'rows' => $rows,
            'paymentAccounts' => $accounts->map(fn (BillingAccount $account) => [
                'account_id' => $account->id,
                'account_no' => $account->account_no,
                'tenant' => $account->payer->full_name,
            ])->values(),
            'summary' => [
                'total_due_cents' => $allRows->sum(fn ($row) => max(0, $row['balance_cents'])),
                'overdue_accounts' => $allRows->where('flags.overdue', true)->count(),
                'credit_cents' => $allRows->sum(fn ($row) => max(0, -$row['balance_cents'])),
                'accounts_with_balance' => $allRows->count(),
            ],
            'statusCounts' => collect(self::STATUSES)->mapWithKeys(fn (string $key) => [$key => $allRows->where("flags.{$key}", true)->count()])->all()
                + ['all' => $allRows->count()],
            'properties' => Property::query()
                ->when($user->role === Role::Staff, fn (Builder $q) => $q->whereIn('id', $user->properties()->select('properties.id')))
                ->orderBy('name')->get(['id', 'name']),
            'filters' => ['status' => $status, 'property_id' => $propertyId],
            'canRecord' => $user->can('create', Payment::class),
            'canManage' => $user->can('refund', Payment::class),
            'today' => $today->toDateString(),
            'methods' => Payment::METHODS,
        ]);
    }

    private function bucket(int $daysPastDue): string
    {
        return match (true) {
            $daysPastDue <= 0 => 'current',
            $daysPastDue <= 30 => '1-30',
            $daysPastDue <= 60 => '31-60',
            $daysPastDue <= 90 => '61-90',
            default => '90+',
        };
    }
}
