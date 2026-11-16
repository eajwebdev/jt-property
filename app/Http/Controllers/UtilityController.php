<?php

namespace App\Http\Controllers;

use App\Enums\RentMode;
use App\Enums\Role;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\UtilityBill;
use App\Rules\Decimal;
use App\Rules\Pesos;
use App\Services\Billing\UtilityAllocationService;
use App\Support\Audit;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UtilityController extends Controller
{
    /** Water and electricity per room for one month. Everyone sees their properties; only the owner configures. */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? today()->format('Y-m');
        $first = Carbon::createFromFormat('!Y-m', $month);

        $units = Unit::query()
            ->when($user->role === Role::Staff, fn ($q) => $q->whereIn('property_id', $user->properties()->select('properties.id')))
            ->with(['property:id,name,code', 'agreements' => fn ($q) => $q->currentOrFuture()->whereDoesntHave('replacedBy')->with(['bed:id,label', 'billingAccount.payer:id,full_name'])])
            ->orderBy('property_id')->orderBy('code')
            ->get();

        $configs = UnitUtility::with('shares')->whereIn('unit_id', $units->modelKeys())->get()->groupBy('unit_id');
        $bills = UtilityBill::whereIn('unit_id', $units->modelKeys())->get();
        $thisMonth = $bills->filter(fn ($b) => $b->bill_month->equalTo($first))->groupBy('unit_id');
        $earlier = $bills->filter(fn ($b) => $b->bill_month->lt($first) && $b->current_reading_e3 !== null)->sortByDesc('bill_month')->groupBy('unit_id');

        return Inertia::render('utilities/index', [
            'month' => $month,
            'canConfigure' => $user->can('owner'),
            'units' => $units->map(function (Unit $unit) use ($configs, $thisMonth, $earlier, $user) {
                $utilities = [];
                foreach (array_keys(UtilityAllocationService::TYPES) as $type) {
                    $c = $configs->get($unit->id)?->firstWhere('type', $type);
                    $bill = $thisMonth->get($unit->id)?->firstWhere('type', $type);
                    $utilities[$type] = [
                        'mode' => $c?->mode ?? 'inclusive',
                        'fixed_cents' => $c?->fixed_cents,
                        'rate' => $c?->rate_e4 ? Quantity::formatRateE4($c->rate_e4) : null,
                        'rate_e4' => $c?->rate_e4,
                        'base_charge_cents' => $c?->base_charge_cents,
                        'split_method' => $c?->split_method,
                        'shares' => $c?->shares->map(fn ($s) => $s->only(['rental_agreement_id', 'percent_bp', 'manual_cents']))->values() ?? [],
                        'previous_e3' => $earlier->get($unit->id)?->firstWhere('type', $type)?->current_reading_e3,
                        'bill' => $bill ? [
                            'id' => $bill->id,
                            'status' => $bill->status,
                            'amount_cents' => $bill->amount_cents,
                            'amount' => Money::format($bill->amount_cents),
                            'previous_e3' => $bill->previous_reading_e3,
                            'current_e3' => $bill->current_reading_e3,
                            'reading_date' => $bill->reading_date?->toDateString(),
                            'rate_e4' => $bill->rate_e4,
                            'base_charge_cents' => $bill->base_charge_cents,
                            'has_evidence' => $bill->evidence_path !== null,
                        ] : null,
                    ];
                }

                return [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'property' => $unit->property->name,
                    'per_bed' => $unit->rent_mode === RentMode::PerBed,
                    'can_enter' => $user->can('enterUtilities', $unit),
                    'agreements' => $unit->agreements->map(fn ($a) => ['id' => $a->id, 'label' => $a->billingAccount->payer->full_name.($a->bed ? ' / '.$a->bed->label : '')])->values(),
                    'utilities' => $utilities,
                ];
            })->values(),
        ]);
    }

    public function configure(Request $request, Unit $unit, string $type): RedirectResponse
    {
        Gate::authorize('owner');

        $perBed = $unit->rent_mode === RentMode::PerBed;
        $data = $request->validate([
            'mode' => ['required', Rule::in(['inclusive', 'fixed', 'metered', 'shared'])],
            'fixed' => ['required_if:mode,fixed', 'nullable', 'string', new Pesos('fixed amount')],
            'rate' => ['required_if:mode,metered', 'nullable', 'string', new Decimal(4, 'rate per unit')],
            'base_charge' => ['nullable', 'string', new Pesos('base charge', allowZero: true)],
            'split_method' => [Rule::requiredIf($perBed && in_array($request->input('mode'), ['metered', 'shared'], true)), 'nullable', Rule::in(['equal', 'days', 'percent', 'manual'])],
            'shares' => ['nullable', 'array'],
            'shares.*.agreement_id' => ['required', 'integer', 'distinct', Rule::exists('rental_agreements', 'id')->where('unit_id', $unit->id)],
            'shares.*.percent_bp' => [Rule::requiredIf($request->input('split_method') === 'percent'), 'nullable', 'integer', 'min:0', 'max:10000'],
            'shares.*.manual_cents' => [Rule::requiredIf($request->input('split_method') === 'manual'), 'nullable', 'integer', 'min:0'],
        ]);

        $split = $perBed && in_array($data['mode'], ['metered', 'shared'], true) ? $data['split_method'] : null;
        $shares = $split === 'percent' || $split === 'manual' ? ($data['shares'] ?? []) : [];

        if ($split === 'percent' && array_sum(array_map(fn ($s) => $s['percent_bp'] ?? 0, $shares)) !== 10000) {
            throw ValidationException::withMessages(['shares' => 'Percent shares must add up to exactly 100%.']);
        }
        if ($split === 'manual' && (count($shares) === 0 || in_array(null, array_map(fn ($s) => $s['manual_cents'] ?? null, $shares), true))) {
            throw ValidationException::withMessages(['shares' => 'Enter an amount for every tenant.']);
        }

        DB::transaction(function () use ($unit, $type, $data, $split, $shares) {
            $config = UnitUtility::firstOrNew(['unit_id' => $unit->id, 'type' => $type]);
            $old = $config->exists ? $config->only(['mode', 'fixed_cents', 'rate_e4', 'base_charge_cents', 'split_method']) : null;
            $metered = $data['mode'] === 'metered';
            $config->fill([
                'mode' => $data['mode'],
                'fixed_cents' => $data['mode'] === 'fixed' ? Money::toCents($data['fixed']) : null,
                'rate_e4' => $metered ? Quantity::rateToE4($data['rate']) : null,
                'base_charge_cents' => $metered ? Money::toCents($data['base_charge'] ?? '0') : null,
                'split_method' => $split,
            ])->save();

            $config->shares()->delete();
            foreach ($shares as $s) {
                $config->shares()->create([
                    'rental_agreement_id' => $s['agreement_id'],
                    'percent_bp' => $split === 'percent' ? ($s['percent_bp'] ?? null) : null,
                    'manual_cents' => $split === 'manual' ? ($s['manual_cents'] ?? null) : null,
                ]);
            }

            Audit::log('unit_utility.configured', $config, [
                'old' => $old,
                'new' => $config->only(['mode', 'fixed_cents', 'rate_e4', 'base_charge_cents', 'split_method']) + ['shares' => $shares],
            ]);
        });

        return back()->with('success', ucfirst($type)." setup for Room {$unit->code} was saved.");
    }
}
