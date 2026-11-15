<?php

namespace Tests\Feature;

use App\Enums\RentMode;
use App\Models\ActivityLog;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PropertyTest extends TestCase
{
    use RefreshDatabase;

    private function propertyPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Riverside Demo House',
            'code' => 'RIV',
            'type' => 'boarding_house',
            'address' => 'Demo Street 1',
            'staff_ids' => [],
        ], $overrides);
    }

    private function unitPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'A101',
            'capacity' => 4,
            'rent_mode' => 'per_bed',
            'status' => 'available',
        ], $overrides);
    }

    public function test_owner_creates_property_and_assigns_staff(): void
    {
        $staff = User::factory()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('properties.store'), $this->propertyPayload(['code' => 'riv', 'staff_ids' => [$staff->id]]))
            ->assertRedirect();

        $property = Property::where('code', 'RIV')->firstOrFail();
        $this->assertTrue($property->users->contains($staff));
        $this->assertDatabaseHas('activity_logs', ['action' => 'property.created', 'subject_id' => $property->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'property.staff_changed', 'subject_id' => $property->id]);
    }

    public function test_property_code_must_be_unique_and_well_formed(): void
    {
        Property::factory()->create(['code' => 'RIV']);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('properties.store'), $this->propertyPayload())->assertSessionHasErrors('code');
        $this->actingAs($owner)->post(route('properties.store'), $this->propertyPayload(['code' => 'A']))->assertSessionHasErrors('code');
    }

    public function test_code_cannot_change_once_billing_accounts_exist(): void
    {
        $property = Property::factory()->create(['code' => 'RIV']);
        BillingAccount::factory()->create(['property_id' => $property->id]);

        $this->actingAs(User::factory()->owner()->create())
            ->put(route('properties.update', $property), $this->propertyPayload(['code' => 'NEW']))
            ->assertSessionHasErrors('code');

        $this->actingAs(User::factory()->owner()->create())
            ->put(route('properties.update', $property), $this->propertyPayload(['name' => 'Renamed']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $property->fresh()->name);
    }

    public function test_staff_and_accountant_cannot_create(): void
    {
        $this->actingAs(User::factory()->create())->post(route('properties.store'), $this->propertyPayload())->assertForbidden();
        $this->actingAs(User::factory()->accountant()->create())->post(route('properties.store'), $this->propertyPayload())->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('properties.create'))->assertForbidden();
        $this->assertDatabaseCount('properties', 0);
    }

    public function test_staff_index_shows_only_assigned_properties(): void
    {
        $mine = Property::factory()->create();
        Property::factory()->create();
        $staff = User::factory()->create();
        $staff->properties()->attach($mine);

        $this->actingAs($staff)->get(route('properties.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('properties/index')->has('properties', 1)->where('properties.0.id', $mine->id));
    }

    public function test_staff_cannot_view_or_edit_unassigned_property_or_unit(): void
    {
        $other = Property::factory()->create();
        $unit = Unit::factory()->create(['property_id' => $other->id]);
        $staff = User::factory()->create();
        $staff->properties()->attach(Property::factory()->create());

        $this->actingAs($staff)->get(route('properties.show', $other))->assertForbidden();
        $this->actingAs($staff)->put(route('units.update', $unit), $this->unitPayload())->assertForbidden();
        $this->actingAs($staff)->post(route('properties.units.store', $other), $this->unitPayload())->assertForbidden();
    }

    public function test_accountant_can_view_everything_but_not_change(): void
    {
        $property = Property::factory()->create();
        $accountant = User::factory()->accountant()->create();

        $this->actingAs($accountant)->get(route('properties.index'))->assertOk();
        $this->actingAs($accountant)->get(route('properties.show', $property))
            ->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManage', false));
        $this->actingAs($accountant)->post(route('properties.units.store', $property), $this->unitPayload())->assertForbidden();
    }

    public function test_show_lists_units_with_occupants_and_status(): void
    {
        $property = Property::factory()->create();
        $unit = Unit::factory()->create(['property_id' => $property->id, 'capacity' => 1, 'code' => 'B1']);
        Occupancy::factory()->create(['unit_id' => $unit->id, 'move_in_date' => '2026-01-01']);

        $this->actingAs(User::factory()->owner()->create())->get(route('properties.show', $property))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('properties/show')
                ->where('units.0.occupied', 1)->where('units.0.display_status', 'full')->has('units.0.occupants', 1));
    }

    public function test_index_has_constant_query_count(): void
    {
        Property::factory()->count(3)->create()->each(fn ($p) => Unit::factory()->count(3)->create(['property_id' => $p->id]));
        $owner = User::factory()->owner()->create();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->actingAs($owner)->get(route('properties.index'))->assertOk();
        $first = $queries;

        Property::factory()->count(3)->create()->each(fn ($p) => Unit::factory()->count(3)->create(['property_id' => $p->id]));
        $queries = 0;
        $this->actingAs($owner)->get(route('properties.index'))->assertOk();

        $this->assertSame($first, $queries);
    }

    public function test_per_bed_unit_creates_beds(): void
    {
        $property = Property::factory()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('properties.units.store', $property), $this->unitPayload())->assertRedirect();

        $unit = Unit::firstWhere('code', 'A101');
        $this->assertSame(['Bed 1', 'Bed 2', 'Bed 3', 'Bed 4'], $unit->beds()->orderBy('id')->pluck('label')->all());
        $this->assertDatabaseHas('activity_logs', ['action' => 'unit.created', 'subject_id' => $unit->id]);
    }

    public function test_whole_unit_has_no_beds_and_zero_capacity_is_rejected(): void
    {
        $property = Property::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('properties.units.store', $property), $this->unitPayload(['rent_mode' => 'whole']))->assertRedirect();
        $this->assertSame(0, Bed::count());
        $this->actingAs($owner)->post(route('properties.units.store', $property), $this->unitPayload(['code' => 'X', 'capacity' => 0]))->assertSessionHasErrors('capacity');
    }

    public function test_capacity_up_adds_beds_and_down_removes_trailing_free_beds(): void
    {
        $property = Property::factory()->create();
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->post(route('properties.units.store', $property), $this->unitPayload(['capacity' => 2]));
        $unit = Unit::firstWhere('code', 'A101');

        $this->actingAs($owner)->put(route('units.update', $unit), $this->unitPayload(['capacity' => 4]))->assertSessionHasNoErrors();
        $this->assertSame(4, $unit->beds()->count());

        $this->actingAs($owner)->put(route('units.update', $unit), $this->unitPayload(['capacity' => 3]))->assertSessionHasNoErrors();
        $this->assertSame(['Bed 1', 'Bed 2', 'Bed 3'], $unit->beds()->orderBy('id')->pluck('label')->all());
    }

    public function test_capacity_down_blocked_when_trailing_bed_occupied(): void
    {
        $unit = Unit::factory()->create(['capacity' => 4, 'rent_mode' => RentMode::PerBed]);
        foreach (range(1, 4) as $n) {
            Bed::create(['unit_id' => $unit->id, 'label' => "Bed $n"]);
        }
        $bed3 = Bed::where('label', 'Bed 3')->first();
        Occupancy::factory()->create(['unit_id' => $unit->id, 'bed_id' => $bed3->id]);

        $this->actingAs(User::factory()->owner()->create())
            ->put(route('units.update', $unit), $this->unitPayload(['capacity' => 2]))
            ->assertSessionHasErrors('capacity');

        $this->assertSame(4, $unit->fresh()->capacity);
        $this->assertSame(4, $unit->beds()->count());
    }

    public function test_whole_unit_capacity_cannot_drop_below_occupants(): void
    {
        $unit = Unit::factory()->create(['capacity' => 3]);
        Occupancy::factory()->count(2)->create(['unit_id' => $unit->id]);

        $this->actingAs(User::factory()->owner()->create())
            ->put(route('units.update', $unit), $this->unitPayload(['capacity' => 1, 'rent_mode' => 'whole']))
            ->assertSessionHasErrors('capacity');
    }

    public function test_rent_mode_change_blocked_with_active_agreement(): void
    {
        $unit = Unit::factory()->create(['capacity' => 2, 'rent_mode' => RentMode::Whole]);
        RentalAgreement::factory()->create(['unit_id' => $unit->id, 'status' => 'active']);

        $this->actingAs(User::factory()->owner()->create())
            ->put(route('units.update', $unit), $this->unitPayload(['capacity' => 2, 'rent_mode' => 'per_bed']))
            ->assertSessionHasErrors('rent_mode');
        $this->assertSame(RentMode::Whole, $unit->fresh()->rent_mode);
    }

    public function test_unit_code_unique_per_property_only(): void
    {
        $a = Property::factory()->create();
        $b = Property::factory()->create();
        $owner = User::factory()->owner()->create();
        $payload = $this->unitPayload(['rent_mode' => 'whole', 'capacity' => 1]);

        $this->actingAs($owner)->post(route('properties.units.store', $a), $payload)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('properties.units.store', $a), $payload)->assertSessionHasErrors('code');
        $this->actingAs($owner)->post(route('properties.units.store', $b), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, Unit::where('code', 'A101')->count());
    }

    public function test_staff_assignment_changes_are_audited(): void
    {
        $property = Property::factory()->create();
        $staff = User::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('properties.update', $property), $this->propertyPayload(['code' => $property->code, 'staff_ids' => [$staff->id]]));
        $this->assertTrue($property->users()->whereKey($staff->id)->exists());
        $this->assertSame(1, ActivityLog::where('action', 'property.staff_changed')->count());

        $this->actingAs($owner)->put(route('properties.update', $property), $this->propertyPayload(['code' => $property->code, 'staff_ids' => [$staff->id]]));
        $this->assertSame(1, ActivityLog::where('action', 'property.staff_changed')->count());
    }

    public function test_update_without_staff_ids_keeps_assigned_staff(): void
    {
        $property = Property::factory()->create();
        $staff = User::factory()->create();
        $property->users()->attach($staff);
        $payload = $this->propertyPayload(['code' => $property->code]);
        unset($payload['staff_ids']);

        $this->actingAs(User::factory()->owner()->create())->put(route('properties.update', $property), $payload)->assertSessionHasNoErrors();

        $this->assertTrue($property->users()->whereKey($staff->id)->exists());
    }

    public function test_staff_viewer_does_not_get_staff_names(): void
    {
        $property = Property::factory()->create();
        $staff = User::factory()->create();
        $property->users()->attach($staff);

        $this->actingAs($staff)->get(route('properties.show', $property))
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('staff', []));
    }

    public function test_unit_update_flash_uses_new_code(): void
    {
        $unit = Unit::factory()->create(['code' => 'OLD1', 'capacity' => 1]);

        $this->actingAs(User::factory()->owner()->create())
            ->put(route('units.update', $unit), $this->unitPayload(['code' => 'NEW1', 'capacity' => 1, 'rent_mode' => 'whole']))
            ->assertSessionHas('success', 'Room NEW1 was saved.');
    }

    public function test_only_staff_role_users_can_be_assigned(): void
    {
        $accountant = User::factory()->accountant()->create();

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('properties.store'), $this->propertyPayload(['staff_ids' => [$accountant->id]]))
            ->assertSessionHasErrors('staff_ids.0');
    }
}
