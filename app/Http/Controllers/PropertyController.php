<?php

namespace App\Http\Controllers;

use App\Enums\PropertyType;
use App\Enums\Role;
use App\Enums\UnitStatus;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Property::class);

        $user = $request->user();
        $query = $user->role === Role::Staff ? $user->properties() : Property::query();

        $properties = $query
            ->with(['units' => fn ($q) => $q->withCount(['occupancies as occupied_count' => fn ($o) => $o->activeOn(today())])])
            ->orderBy('name')
            ->get()
            ->map(fn (Property $property) => [
                'id' => $property->id,
                'name' => $property->name,
                'code' => $property->code,
                'type' => $property->type->value,
                'address' => $property->address,
                'rooms' => $property->units->count(),
                'capacity' => $property->units->sum('capacity'),
                'occupied' => $property->units->sum('occupied_count'),
                'vacant' => $property->units
                    ->filter(fn ($unit) => $unit->status === UnitStatus::Available)
                    ->sum(fn ($unit) => max($unit->capacity - $unit->occupied_count, 0)),
            ]);

        return Inertia::render('properties/index', [
            'properties' => $properties,
            'canManage' => $user->can('create', Property::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Property::class);

        return Inertia::render('properties/form', $this->formProps(new Property, []));
    }

    public function store(StorePropertyRequest $request): RedirectResponse
    {
        Gate::authorize('create', Property::class);

        $data = $request->validated();

        $property = DB::transaction(function () use ($data) {
            $property = Property::create(collect($data)->except('staff_ids')->all());
            Audit::log('property.created', $property, ['new' => $property->only(['name', 'code', 'type', 'address'])]);
            $this->syncStaff($property, $data['staff_ids'] ?? []);

            return $property;
        });

        return redirect()->route('properties.show', $property)->with('success', "{$property->name} was added. Now add its rooms.");
    }

    public function show(Property $property): Response
    {
        Gate::authorize('view', $property);

        $today = today();
        $units = $property->units()
            ->with(['occupancies' => fn ($q) => $q->activeOn($today)->with(['tenant:id,full_name', 'bed:id,label'])->orderBy('id')])
            ->orderBy('building')->orderBy('floor')->orderBy('code')
            ->get()
            ->map(function ($unit) {
                $occupied = $unit->occupancies->count();

                return [
                    'id' => $unit->id,
                    'code' => $unit->code,
                    'building' => $unit->building,
                    'floor' => $unit->floor,
                    'capacity' => $unit->capacity,
                    'rent_mode' => $unit->rent_mode->value,
                    'status' => $unit->status->value,
                    'description' => $unit->description,
                    'amenities' => $unit->amenities,
                    'occupied' => $occupied,
                    'display_status' => $unit->displayStatusFor($occupied),
                    'occupants' => $unit->occupancies->map(fn (Occupancy $o) => [
                        'id' => $o->id,
                        'name' => $o->tenant->full_name,
                        'bed' => $o->bed?->label,
                    ])->values(),
                ];
            });

        return Inertia::render('properties/show', [
            'property' => array_merge($property->only(['id', 'name', 'code', 'address', 'description', 'amenities']), ['type' => $property->type->value]),
            'units' => $units,
            'staff' => $property->users()->orderBy('name')->get(['users.id', 'users.name']),
            'canManage' => request()->user()->can('update', $property),
        ]);
    }

    public function edit(Property $property): Response
    {
        Gate::authorize('update', $property);

        return Inertia::render('properties/form', $this->formProps($property, $property->users()->pluck('users.id')->all()));
    }

    public function update(UpdatePropertyRequest $request, Property $property): RedirectResponse
    {
        Gate::authorize('update', $property);

        $data = $request->validated();

        DB::transaction(function () use ($data, $property) {
            $property->update(collect($data)->except('staff_ids')->all());

            if ($changes = $property->getChanges()) {
                Audit::log('property.updated', $property, [
                    'old' => array_intersect_key($property->getOriginal(), $changes),
                    'new' => $changes,
                ]);
            }

            $this->syncStaff($property, $data['staff_ids'] ?? []);
        });

        return redirect()->route('properties.show', $property)->with('success', "{$property->name} was saved.");
    }

    /** @param  array<int, int>  $staffIds */
    private function syncStaff(Property $property, array $staffIds): void
    {
        $changes = $property->users()->sync($staffIds);

        if ($changes['attached'] || $changes['detached']) {
            Audit::log('property.staff_changed', $property, [
                'added' => $changes['attached'],
                'removed' => $changes['detached'],
            ]);
        }
    }

    /**
     * @param  array<int, int>  $selected
     * @return array<string, mixed>
     */
    private function formProps(Property $property, array $selected): array
    {
        return [
            'property' => $property->exists
                ? array_merge($property->only(['id', 'name', 'code', 'address', 'description', 'amenities']), ['type' => $property->type->value, 'has_billing' => $property->billingAccounts()->exists()])
                : null,
            'staff_ids' => $selected,
            'types' => collect(PropertyType::cases())->map->value,
            'staff' => User::where('role', Role::Staff)->whereNull('deactivated_at')->orderBy('name')->get(['id', 'name']),
        ];
    }
}
