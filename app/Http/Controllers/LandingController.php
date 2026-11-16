<?php

namespace App\Http\Controllers;

use App\Enums\UnitStatus;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\Unit;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public website: what the boarding houses look like and where there is room.
 * Only properties the owner marked "show on website" appear, and only listing details:
 * never tenant names, balances or anything from billing.
 */
class LandingController extends Controller
{
    public function index(): Response
    {
        $properties = $this->listed()->with(['photos', 'units'])->orderBy('name')->get();
        $occupied = $this->occupiedByUnit($properties->flatMap->units);

        return Inertia::render('welcome', [
            'company' => $this->company(),
            'listings' => $properties->map(fn (Property $p) => $this->card($p, $occupied))->values(),
        ]);
    }

    public function show(string $code): Response
    {
        $property = $this->listed()->where('code', $code)->with(['photos', 'units' => fn ($q) => $q->orderBy('building')->orderBy('floor')->orderBy('code')])->firstOrFail();
        $occupied = $this->occupiedByUnit($property->units);

        return Inertia::render('listing', [
            'company' => $this->company(),
            'listing' => array_merge($this->card($property, $occupied), [
                'description' => $property->description,
                'photos' => $property->photos->map(fn (PropertyPhoto $photo) => $photo->toCard())->values(),
                'rooms' => $property->units->filter(fn (Unit $u) => $u->status !== UnitStatus::Unavailable)->map(fn (Unit $u) => [
                    'id' => $u->id,
                    'name' => trim(($u->building ? $u->building.' · ' : '').$u->code),
                    'floor' => $u->floor,
                    'rent_mode' => $u->rent_mode->value,
                    'capacity' => $u->capacity,
                    'available' => $u->status === UnitStatus::Available ? max(0, $u->capacity - (int) ($occupied[$u->id] ?? 0)) : 0,
                    'amenities' => $this->list($u->amenities),
                ])->values(),
            ]),
        ]);
    }

    private function listed()
    {
        return Property::query()->where('show_on_website', true)->whereNull('archived_at');
    }

    /** @param  Collection<int, int>  $occupied */
    private function card(Property $p, Collection $occupied): array
    {
        $units = $p->units->filter(fn (Unit $u) => $u->status !== UnitStatus::Unavailable);

        return [
            'code' => $p->code,
            'name' => $p->name,
            'type' => $p->type->value,
            'address' => $p->address,
            'amenities' => $this->list($p->amenities),
            'cover' => $p->photos->first()?->toCard(),
            'photo_count' => $p->photos->count(),
            'rooms' => $units->count(),
            'capacity' => (int) $units->sum('capacity'),
            'available' => (int) $units->filter(fn (Unit $u) => $u->status === UnitStatus::Available)
                ->sum(fn (Unit $u) => max(0, $u->capacity - (int) ($occupied[$u->id] ?? 0))),
        ];
    }

    /** @param  Collection<int, Unit>  $units */
    private function occupiedByUnit(Collection $units): Collection
    {
        return Occupancy::activeOn(today())->whereIn('unit_id', $units->pluck('id'))
            ->selectRaw('unit_id, COUNT(*) as n')->groupBy('unit_id')->pluck('n', 'unit_id');
    }

    /** "water, wifi; parking" or one per line → ['water', 'wifi', 'parking'] */
    private function list(?string $text): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $text))->map(fn ($s) => trim($s))->filter()->unique()->values()->all();
    }

    private function company(): array
    {
        $s = Settings::all();

        return [
            'name' => $s['company_name'] ?? null,
            'address' => $s['company_address'] ?? null,
            'phone' => $s['company_phone'] ?? null,
            'email' => $s['company_email'] ?? null,
        ];
    }
}
