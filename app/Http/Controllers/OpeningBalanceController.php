<?php

namespace App\Http\Controllers;

use App\Enums\LedgerEntryType;
use App\Enums\Role;
use App\Models\BillingAccount;
use App\Models\OpeningBalance;
use App\Models\Property;
use App\Rules\Pesos;
use App\Services\Billing\OpeningBalanceService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OpeningBalanceController extends Controller
{
    public function __construct(private OpeningBalanceService $service) {}

    /** Every account with its opening-balance status. Staff see only their properties. */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', OpeningBalance::class);

        $user = $request->user();
        $propertyId = $request->integer('property_id') ?: null;

        $accounts = BillingAccount::query()
            ->when($user->role === Role::Staff, fn (Builder $q) => $q->whereIn('property_id', $user->properties()->select('properties.id')))
            ->when($propertyId, fn (Builder $q) => $q->where('property_id', $propertyId))
            ->with(['payer:id,full_name', 'property:id,name,code'])
            ->addSelect(['first_charge_date' => DB::table('ledger_entries')
                ->selectRaw('MIN(effective_date)')
                ->whereColumn('billing_account_id', 'billing_accounts.id')
                ->where('type', LedgerEntryType::Charge->value),
            ])
            ->orderBy('id')
            ->get();

        // The live balance if there is one, otherwise the most recent reversed one.
        $balances = OpeningBalance::whereIn('billing_account_id', $accounts->pluck('id'))
            ->orderByRaw("status = 'reversed'")->orderByDesc('id')
            ->get()->unique('billing_account_id')->keyBy('billing_account_id');

        $rows = $accounts->map(function (BillingAccount $a) use ($balances) {
            $ob = $balances->get($a->id);

            return [
                'account_id' => $a->id,
                'account_no' => $a->account_no,
                'tenant' => $a->payer->full_name,
                'property' => $a->property->name,
                'property_id' => $a->property_id,
                'account_status' => $a->status->value,
                'first_charge_date' => $a->first_charge_date ? Carbon::parse($a->first_charge_date)->toDateString() : null,
                'status' => $ob?->status ?? 'none',
                'opening_balance_id' => $ob?->id,
                'amount_cents' => $ob?->amount_cents,
                'amount' => $ob ? Money::format($ob->amount_cents) : null,
                'as_of' => $ob?->as_of->toDateString(),
                'description' => $ob?->description,
                'breakdown' => $ob?->breakdown,
                'reverse_reason' => $ob?->reverse_reason,
            ];
        })->values();

        return Inertia::render('opening-balances/index', [
            'accounts' => $rows,
            'properties' => Property::query()
                ->when($user->role === Role::Staff, fn (Builder $q) => $q->whereIn('id', $user->properties()->select('properties.id')))
                ->orderBy('name')->get(['id', 'name']),
            'filters' => ['property_id' => $propertyId],
            'canManage' => $user->can('create', OpeningBalance::class),
            'today' => today()->toDateString(),
            'importResult' => session('import_result'),
        ]);
    }

    /** Saves the draft; with approve=true it also posts it (the wizard's last step). */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', OpeningBalance::class);

        $data = $request->validate([
            'billing_account_id' => ['required', 'integer', 'exists:billing_accounts,id'],
            'amount' => ['required', 'string', new Pesos('balance')],
            'as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'breakdown' => ['nullable', 'array', 'max:60'],
            'breakdown.*.month' => ['required', 'date_format:Y-m'],
            'breakdown.*.amount' => ['required', 'string', new Pesos('monthly amount')],
            'breakdown.*.note' => ['nullable', 'string', 'max:255'],
            'approve' => ['nullable', 'boolean'],
        ]);

        $breakdown = collect($data['breakdown'] ?? [])->map(fn ($b) => [
            'month' => $b['month'],
            'amount_cents' => Money::toCents($b['amount']),
            'note' => ($b['note'] ?? null) ?: null,
        ])->values()->all();

        $ob = DB::transaction(function () use ($data, $breakdown, $request) {
            $ob = $this->service->draft(BillingAccount::findOrFail($data['billing_account_id']), Money::toCents($data['amount']), Carbon::parse($data['as_of']), $breakdown, $data['notes'] ?? null);
            if ($request->boolean('approve')) {
                $this->service->approve($ob);
            }

            return $ob;
        });

        return redirect()->route('opening-balances.index')->with('success', $ob->status === 'approved'
            ? Money::format($ob->amount_cents).' posted as Balance Brought Forward.'
            : 'Draft saved. Nothing is posted until you approve it.');
    }

    public function approve(OpeningBalance $openingBalance): RedirectResponse
    {
        Gate::authorize('approve', $openingBalance);

        $this->service->approve($openingBalance);

        return back()->with('success', Money::format($openingBalance->amount_cents).' posted as Balance Brought Forward.');
    }

    public function reverse(Request $request, OpeningBalance $openingBalance): RedirectResponse
    {
        Gate::authorize('reverse', $openingBalance);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $this->service->reverse($openingBalance, trim($data['reason']));

        return back()->with('success', 'Opening balance reversed. You can enter a new one.');
    }

    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('create', OpeningBalance::class);

        $request->validate(['file' => ['required', 'file', 'max:1024', 'extensions:csv,txt']]);

        $result = $this->service->importCsv($request->file('file'));

        return redirect()->route('opening-balances.index')
            ->with('import_result', $result)
            ->with('success', $result['created'].' opening '.($result['created'] === 1 ? 'balance' : 'balances').' saved as drafts. Review and approve each one.');
    }
}
