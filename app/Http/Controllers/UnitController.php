<?php

namespace App\Http\Controllers;

use App\Enums\AgreementStatus;
use App\Enums\RentMode;
use App\Http\Requests\StoreUnitRequest;
use App\Http\Requests\UpdateUnitRequest;
use App\Models\Property;
use App\Models\Unit;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class UnitController extends Controller
{
    public function store(StoreUnitRequest $request, Property $property): RedirectResponse
    {
        Gate::authorize('create', [Unit::class, $property]);

        $unit = DB::transaction(function () use ($request, $property) {
            $unit = $property->units()->create($request->validated());
            $this->syncBeds($unit);
            Audit::log('unit.created', $unit, ['new' => $request->validated()]);

            return $unit;
        });

        return back()->with('success', "Room {$unit->code} was added.");
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        Gate::authorize('update', $unit);

        $unit = DB::transaction(function () use ($request, $unit) {
            $unit = Unit::lockForUpdate()->findOrFail($unit->id);
            $data = $request->validated();

            $this->guardRentMode($unit, $data['rent_mode']);
            $this->guardCapacity($unit, $data);

            $unit->update($data);
            $this->syncBeds($unit);

            if ($changes = $unit->getChanges()) {
                Audit::log('unit.updated', $unit, [
                    'old' => array_intersect_key($unit->getOriginal(), $changes),
                    'new' => $changes,
                ]);
            }

            return $unit;
        });

        return back()->with('success', "Room {$unit->code} was saved.");
    }

    private function guardRentMode(Unit $unit, string $newMode): void
    {
        if ($unit->rent_mode->value !== $newMode && $unit->agreements()->where('status', AgreementStatus::Active)->exists()) {
            throw ValidationException::withMessages([
                'rent_mode' => 'This room has an active rental agreement. End the agreement before changing how rent is charged.',
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function guardCapacity(Unit $unit, array $data): void
    {
        $capacity = (int) $data['capacity'];

        if ($capacity >= $unit->capacity) {
            return;
        }

        $inUse = $unit->occupancies()->currentOrFuture()->count() > $capacity;

        if ($data['rent_mode'] === RentMode::PerBed->value) {
            $inUse = $inUse || $unit->beds()->orderBy('id')->get()->slice($capacity)
                ->contains(fn ($bed) => $bed->occupancies()->exists() || $bed->agreements()->exists());
        }

        if ($inUse) {
            throw ValidationException::withMessages([
                'capacity' => 'Tenants are using beds or places that would be removed. Move them out first, or choose a larger capacity.',
            ]);
        }
    }

    /** Per-bed units always have exactly `capacity` beds; extra beds are removed from the end. */
    private function syncBeds(Unit $unit): void
    {
        if ($unit->rent_mode !== RentMode::PerBed) {
            return;
        }

        $beds = $unit->beds()->orderBy('id')->get();

        $beds->slice($unit->capacity)->each->delete();

        for ($n = $beds->count() + 1; $n <= $unit->capacity; $n++) {
            $unit->beds()->create(['label' => "Bed $n"]);
        }
    }
}
