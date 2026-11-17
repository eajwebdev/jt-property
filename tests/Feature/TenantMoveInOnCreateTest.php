<?php

namespace Tests\Feature;

use App\Enums\RentMode;
use App\Models\Bed;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** "Add a tenant" can also put the boarder in a building / room / bed so the rental starts right away. */
class TenantMoveInOnCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Property $property;

    private Unit $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 09:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        $this->property = Property::factory()->create();
        $this->room = Unit::factory()->for($this->property)->create(['code' => 'Room #1', 'building' => 'BIND House', 'floor' => '2nd floor', 'rent_mode' => RentMode::PerBed, 'capacity' => 2]);
        Bed::create(['unit_id' => $this->room->id, 'label' => 'Bed 1']);
        Bed::create(['unit_id' => $this->room->id, 'label' => 'Bed 2']);
    }

    /** @return array<string, mixed> */
    private function tenant(array $extra = []): array
    {
        return array_merge([
            'property_id' => $this->property->id, 'full_name' => 'DELA CRUZ, Juan', 'preferred_delivery' => 'print', 'email_opt_in' => false,
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function moveIn(array $extra = []): array
    {
        return array_merge([
            'unit_id' => $this->room->id, 'bed_id' => $this->room->beds()->orderBy('id')->value('id'),
            'rent' => '700', 'start_date' => '2026-11-17', 'due_day' => 5,
        ], $extra);
    }

    public function test_the_form_lists_each_room_with_its_building_floor_and_free_beds(): void
    {
        $this->get(route('tenants.index'))->assertInertia(fn (Assert $page) => $page
            ->where('form.rooms.0.unit_id', $this->room->id)
            ->where('form.rooms.0.property_id', $this->property->id)
            ->where('form.rooms.0.building', 'BIND House')
            ->where('form.rooms.0.floor', '2nd floor')
            ->where('form.rooms.0.code', 'Room #1')
            ->has('form.rooms.0.beds', 2));
    }

    public function test_adding_a_tenant_with_a_room_starts_the_rental_at_once(): void
    {
        $response = $this->post(route('tenants.store'), $this->tenant($this->moveIn()));

        $tenant = Tenant::sole();
        $response->assertRedirect(route('tenants.show', $tenant))->assertSessionHas('success');
        $agreement = RentalAgreement::with('rentRates', 'billingAccount')->sole();
        $this->assertSame($this->room->id, $agreement->unit_id);
        $this->assertSame('Bed 1', $agreement->bed->label);
        $this->assertSame('2026-11-17', $agreement->start_date->toDateString());
        $this->assertSame(5, $agreement->due_day);
        $this->assertSame(70000, $agreement->rentRates->sole()->rent_cents);
        $this->assertSame($tenant->id, $agreement->billingAccount->payer_tenant_id);
    }

    public function test_without_a_room_only_the_tenant_is_added(): void
    {
        $this->post(route('tenants.store'), $this->tenant(['unit_id' => '']))->assertRedirect();

        $this->assertSame(1, Tenant::count());
        $this->assertSame(0, RentalAgreement::count());
    }

    public function test_rent_start_date_and_due_day_are_required_once_a_room_is_chosen(): void
    {
        $this->post(route('tenants.store'), $this->tenant(['unit_id' => $this->room->id]))
            ->assertSessionHasErrors(['rent', 'start_date', 'due_day']);

        $this->assertSame(0, Tenant::count());
    }

    public function test_a_taken_bed_is_refused_and_no_half_saved_tenant_is_left(): void
    {
        $this->post(route('tenants.store'), $this->tenant($this->moveIn()))->assertRedirect();
        $this->post(route('tenants.store'), $this->tenant(['full_name' => 'SANTOS, Maria'] + $this->moveIn()))->assertSessionHasErrors();

        $this->assertSame(['DELA CRUZ, Juan'], Tenant::pluck('full_name')->all());
        $this->assertSame(1, RentalAgreement::count());
    }

    public function test_a_room_of_another_property_is_refused(): void
    {
        $other = Unit::factory()->for(Property::factory()->create())->create(['rent_mode' => RentMode::Whole]);

        $this->post(route('tenants.store'), $this->tenant($this->moveIn(['unit_id' => $other->id, 'bed_id' => null])))->assertSessionHasErrors();

        $this->assertSame(0, Tenant::count());
    }

    public function test_only_the_owner_can_start_a_rental_while_adding(): void
    {
        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $this->actingAs($staff);

        $this->post(route('tenants.store'), $this->tenant($this->moveIn()))->assertForbidden();
        $this->assertSame(0, Tenant::count());
    }
}
