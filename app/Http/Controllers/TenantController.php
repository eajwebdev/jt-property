<?php

namespace App\Http\Controllers;

use App\Enums\AgreementStatus;
use App\Enums\DeliveryMethod;
use App\Enums\RentMode;
use App\Enums\Role;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    private const FIELDS = [
        'property_id', 'full_name', 'email', 'mobile', 'emergency_contact_name', 'emergency_contact_phone',
        'preferred_delivery', 'email_opt_in', 'remarks',
    ];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Tenant::class);

        $user = $request->user();
        $today = today();
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $propertyId = $request->integer('property') ?: null;

        $tenants = Tenant::query()
            ->with('property:id,name,code')
            ->when($user->role === Role::Staff, fn ($query) => $query->whereIn('property_id', $user->properties()->select('properties.id')))
            ->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn ($w) => $w->where('full_name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('mobile', 'like', $like));
            })
            ->when($status === 'archived', fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($status === 'no_email', fn ($query) => $query->whereNull('email'))
            ->when($status === 'active', fn ($query) => $query->whereHas('occupancies', fn ($o) => $o->activeOn($today)))
            ->when($status === 'upcoming', fn ($query) => $query->whereHas('occupancies', fn ($o) => $o->where('move_in_date', '>', $today->toDateString()))->whereDoesntHave('occupancies', fn ($o) => $o->activeOn($today)))
            ->when($status === 'vacated', fn ($query) => $query->whereHas('occupancies', fn ($o) => $o->where('move_out_date', '<', $today->toDateString()))->whereDoesntHave('occupancies', fn ($o) => $o->currentOrFuture()))
            ->orderBy('full_name')
            ->paginate(25)
            ->withQueryString();

        // Current room, rent and due day for this page's tenants in three queries (no per-row lookups).
        $occupancies = Occupancy::query()
            ->activeOn($today)
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->with(['unit:id,code', 'bed:id,label', 'rentalAgreement:id,due_day'])
            ->orderBy('id')
            ->get()
            ->groupBy('tenant_id');

        $upcoming = Occupancy::query()
            ->where('move_in_date', '>', $today->toDateString())
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->orderBy('move_in_date')
            ->get()
            ->groupBy('tenant_id');

        $rents = RentRate::query()
            ->whereIn('rental_agreement_id', $occupancies->flatten()->pluck('rental_agreement_id')->filter()->unique())
            ->where('effective_from', '<=', $today->toDateString())
            ->orderBy('effective_from')
            ->get()
            ->groupBy('rental_agreement_id')
            ->map(fn ($rates) => $rates->last()->rent_cents);

        $tenants->through(function (Tenant $tenant) use ($occupancies, $rents, $upcoming) {
            $current = $occupancies->get($tenant->id)?->first();
            $next = $upcoming->get($tenant->id)?->first();
            $rent = $current?->rental_agreement_id ? $rents->get($current->rental_agreement_id) : null;

            return [
                'id' => $tenant->id,
                'name' => $tenant->full_name,
                'email' => $tenant->email,
                'mobile' => $tenant->mobile,
                'property' => $tenant->property->name,
                'room' => $current ? $current->unit->code.($current->bed ? ' / '.$current->bed->label : '') : null,
                'rent' => $rent === null ? null : Money::format($rent),
                'due_day' => $current?->rentalAgreement?->due_day,
                'status' => $tenant->archived_at ? 'archived' : ($current ? 'active' : ($next ? 'upcoming' : 'no_room')),
                'moving_in' => $current || $tenant->archived_at ? null : $next?->move_in_date->toDateString(),
            ];
        });

        return Inertia::render('tenants/index', [
            'tenants' => $tenants,
            'filters' => ['q' => $q, 'status' => $status, 'property' => $propertyId],
            'properties' => $this->accessibleProperties($request)->get(['properties.id', 'properties.name']),
            'canCreate' => $user->can('create', Tenant::class),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Tenant::class);

        return Inertia::render('tenants/form', $this->formProps($request, null));
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        Gate::authorize('create', [Tenant::class, $request->integer('property_id')]);

        $tenant = DB::transaction(function () use ($request) {
            $tenant = Tenant::create($request->safe()->only(self::FIELDS));
            Audit::log('tenant.created', $tenant, ['new' => $tenant->only(['full_name', 'property_id'])]);

            return $tenant;
        });

        return redirect()->route('tenants.show', $tenant)->with('success', "{$tenant->full_name} was added.");
    }

    public function show(Tenant $tenant): Response
    {
        Gate::authorize('view', $tenant);

        $tenant->load('property:id,name,archived_at');
        $today = today()->toDateString();

        $accounts = $tenant->billingAccounts()
            ->with(['agreements.unit:id,code', 'agreements.bed:id,label', 'agreements.rentRates', 'deposits'])
            ->orderBy('id')
            ->get()
            ->map(fn ($account) => [
                'id' => $account->id,
                'account_no' => $account->account_no,
                'status' => $account->status->value,
                'agreements' => $account->agreements->map(fn ($a) => [
                    'id' => $a->id,
                    'room' => $a->unit->code.($a->bed ? ' / '.$a->bed->label : ''),
                    'due_day' => $a->due_day,
                    'start_date' => $a->start_date->toDateString(),
                    'end_date' => $a->end_date?->toDateString(),
                    // Dates decide: an end date in the past means ended even if the status column was not updated yet.
                    'status' => $a->end_date !== null && $a->end_date->toDateString() < $today ? 'ended' : $a->status->value,
                    'open' => $a->end_date === null || $a->end_date->toDateString() >= $today,
                    'whole' => $a->bed_id === null,
                    'current_rent_cents' => ($a->rentRates->sortBy('effective_from')->filter(fn ($r) => $r->effective_from->toDateString() <= $today)->last() ?? $a->rentRates->sortBy('effective_from')->first())?->rent_cents,
                    'rates' => $a->rentRates->sortByDesc('effective_from')->values()->map(fn ($r) => [
                        'id' => $r->id,
                        'rent' => Money::format($r->rent_cents),
                        'effective_from' => $r->effective_from->toDateString(),
                        'reason' => $r->reason,
                    ]),
                ]),
                'deposits' => $account->deposits->map(fn ($d) => [
                    'id' => $d->id,
                    'amount' => Money::format($d->amount_cents),
                    'received_on' => $d->received_on->toDateString(),
                    'status' => $d->status->value,
                    'notes' => $d->notes,
                ]),
            ]);

        $occupancies = $tenant->occupancies()
            ->with(['unit:id,code', 'bed:id,label'])
            ->orderByDesc('move_in_date')
            ->get()
            ->map(fn (Occupancy $o) => [
                'id' => $o->id,
                'room' => $o->unit->code.($o->bed ? ' / '.$o->bed->label : ''),
                'move_in_date' => $o->move_in_date->toDateString(),
                'move_out_date' => $o->move_out_date?->toDateString(),
                'is_payer' => $o->is_payer,
            ]);

        $user = request()->user();
        $canStartRental = $user->can('create', RentalAgreement::class) && $tenant->archived_at === null && $tenant->property->archived_at === null;

        return Inertia::render('tenants/show', [
            'tenant' => array_merge(
                $tenant->only(['id', 'full_name', 'email', 'mobile', 'emergency_contact_name', 'emergency_contact_phone', 'email_opt_in', 'remarks']),
                ['property' => $tenant->property->name, 'preferred_delivery' => $tenant->preferred_delivery->value, 'archived_at' => $tenant->archived_at?->toDateString()],
            ),
            'accounts' => $accounts,
            'occupancies' => $occupancies,
            'canEdit' => $user->can('update', $tenant),
            'canArchive' => $user->can('archive', $tenant) && $tenant->archived_at === null,
            'canStartRental' => $canStartRental,
            'rentalOptions' => $canStartRental ? $this->rentalOptions($tenant) : null,
            'canManageRentals' => $user->can('recordDeposit', RentalAgreement::class),
            'today' => $today,
        ]);
    }

    /**
     * Rooms, free beds and possible co-residents for the "Start rental" dialog.
     * Only a convenience list: TenancyService re-checks every rule when saving.
     *
     * @return array<string, mixed>
     */
    private function rentalOptions(Tenant $tenant): array
    {
        $today = today()->toDateString();

        $takenBeds = RentalAgreement::query()
            ->where('status', AgreementStatus::Active)
            ->whereNotNull('bed_id')
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->whereHas('unit', fn ($q) => $q->where('property_id', $tenant->property_id))
            ->pluck('bed_id')
            ->flip();

        $units = Unit::query()
            ->where('property_id', $tenant->property_id)
            ->with(['beds' => fn ($q) => $q->orderBy('id')])
            ->withCount(['occupancies as occupied' => fn ($q) => $q->activeOn($today)])
            ->orderBy('code')
            ->get()
            ->map(fn (Unit $unit) => [
                'id' => $unit->id,
                'code' => $unit->code,
                'rent_mode' => $unit->rent_mode->value,
                'capacity' => $unit->capacity,
                'occupied' => $unit->occupied,
                'status' => $unit->displayStatusFor($unit->occupied),
                'beds' => $unit->rent_mode === RentMode::PerBed
                    ? $unit->beds->reject(fn ($bed) => $takenBeds->has($bed->id))->map(fn ($bed) => ['id' => $bed->id, 'label' => $bed->label])->values()
                    : [],
            ]);

        $residents = Tenant::query()
            ->where('property_id', $tenant->property_id)
            ->whereNull('archived_at')
            ->whereKeyNot($tenant->id)
            ->whereDoesntHave('occupancies', fn ($q) => $q->currentOrFuture())
            ->orderBy('full_name')
            ->get(['id', 'full_name'])
            ->map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->full_name]);

        return ['units' => $units, 'residents' => $residents, 'today' => $today];
    }

    public function edit(Request $request, Tenant $tenant): Response
    {
        Gate::authorize('update', $tenant);

        return Inertia::render('tenants/form', $this->formProps($request, $tenant));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): RedirectResponse
    {
        Gate::authorize('update', $tenant);

        DB::transaction(function () use ($request, $tenant) {
            $tenant->update($request->safe()->only(self::FIELDS));

            if ($changes = $tenant->getChanges()) {
                Audit::log('tenant.updated', $tenant, [
                    'old' => array_intersect_key($tenant->getOriginal(), $changes),
                    'new' => $changes,
                ]);
            }
        });

        return redirect()->route('tenants.show', $tenant)->with('success', "{$tenant->full_name} was saved.");
    }

    /** Tenants with history are never deleted; archiving only hides them from the default list. */
    public function archive(Tenant $tenant): RedirectResponse
    {
        Gate::authorize('archive', $tenant);

        $hasAgreement = $tenant->billingAccounts()
            ->whereHas('agreements', fn ($a) => $a->where('status', AgreementStatus::Active))
            ->exists();

        if ($hasAgreement || $tenant->occupancies()->currentOrFuture()->exists()) {
            throw ValidationException::withMessages([
                'archive' => 'This tenant still has a rental or a room. End the rental and move them out first.',
            ]);
        }

        DB::transaction(function () use ($tenant) {
            $tenant->update(['archived_at' => now()]);
            Audit::log('tenant.archived', $tenant, ['full_name' => $tenant->full_name]);
        });

        return redirect()->route('tenants.index')->with('success', "{$tenant->full_name} was archived.");
    }

    private function accessibleProperties(Request $request)
    {
        $user = $request->user();

        return ($user->role === Role::Staff ? $user->properties() : Property::query())->orderBy('properties.name');
    }

    /** @return array<string, mixed> */
    private function formProps(Request $request, ?Tenant $tenant): array
    {
        return [
            'tenant' => $tenant ? array_merge($tenant->only(['id', ...self::FIELDS]), ['preferred_delivery' => $tenant->preferred_delivery->value]) : null,
            'properties' => $this->accessibleProperties($request)->get(['properties.id', 'properties.name']),
            'deliveryMethods' => collect(DeliveryMethod::cases())->map->value,
        ];
    }
}
