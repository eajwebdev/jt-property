<?php

namespace Tests\Feature;

use App\Enums\RentMode;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\SecurityDeposit;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RentalAgreementTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Unit $unit;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $this->unit = Unit::factory()->for($this->property)->create(['code' => 'A101', 'capacity' => 2, 'rent_mode' => RentMode::PerBed]);
        $this->unit->beds()->create(['label' => 'Bed 1']);
        $this->unit->beds()->create(['label' => 'Bed 2']);
        $this->tenant = Tenant::factory()->for($this->property)->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'unit_id' => $this->unit->id,
            'bed_id' => $this->unit->beds()->first()->id,
            'rent' => '2,500.00',
            'due_day' => 5,
            'cycle_start_day' => 1,
            'issue_lead_days' => 7,
            'prorate' => false,
            'start_date' => '2026-12-01',
            'end_date' => null,
            'resident_tenant_ids' => [],
            'deposit' => '',
            'override_reason' => '',
        ], $overrides);
    }

    public function test_owner_starts_rental_and_rent_input_is_parsed_to_centavos(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('tenants.agreements.store', $this->tenant), $this->payload(['deposit' => '5,000']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('tenants.show', $this->tenant));

        $this->assertSame(250000, RentRate::sole()->rent_cents);
        $this->assertSame(500000, SecurityDeposit::sole()->amount_cents);
        $this->assertSame(5, RentalAgreement::sole()->due_day);
        $this->assertSame(1, Occupancy::where('tenant_id', $this->tenant->id)->where('is_payer', true)->count());
    }

    public function test_staff_and_accountant_cannot_start_rental(): void
    {
        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);

        $this->actingAs($staff)->post(route('tenants.agreements.store', $this->tenant), $this->payload())->assertForbidden();
        $this->actingAs(User::factory()->accountant()->create())->post(route('tenants.agreements.store', $this->tenant), $this->payload())->assertForbidden();

        $this->assertSame(0, RentalAgreement::count());
    }

    public function test_bad_money_and_numbers_are_rejected(): void
    {
        $owner = User::factory()->owner()->create();
        $post = fn (array $o) => $this->actingAs($owner)->post(route('tenants.agreements.store', $this->tenant), $this->payload($o));

        $post(['rent' => '25,00'])->assertSessionHasErrors('rent');
        $post(['rent' => '0'])->assertSessionHasErrors('rent');
        $post(['rent' => '-100'])->assertSessionHasErrors('rent');
        $post(['deposit' => 'abc'])->assertSessionHasErrors('deposit');
        $post(['due_day' => 32])->assertSessionHasErrors('due_day');
        $post(['cycle_start_day' => 29])->assertSessionHasErrors('cycle_start_day');
        $post(['issue_lead_days' => 32])->assertSessionHasErrors('issue_lead_days');
        $post(['end_date' => '2026-11-30'])->assertSessionHasErrors('end_date');
        $post(['override_reason' => 'short'])->assertSessionHasErrors('override_reason');
        $this->assertSame(0, RentalAgreement::count());
    }

    public function test_service_rule_errors_come_back_to_the_form(): void
    {
        $this->actingAs(User::factory()->owner()->create())
            ->post(route('tenants.agreements.store', $this->tenant), $this->payload(['bed_id' => null]))
            ->assertSessionHasErrors('bed');
    }

    public function test_show_page_offers_rental_options_to_owner_only(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get(route('tenants.show', $this->tenant))
            ->assertInertia(fn (Assert $page) => $page->where('canStartRental', true)
                ->has('rentalOptions.units', 1)
                ->where('rentalOptions.units.0.code', 'A101')
                ->has('rentalOptions.units.0.beds', 2));

        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $this->actingAs($staff)->get(route('tenants.show', $this->tenant))
            ->assertInertia(fn (Assert $page) => $page->where('canStartRental', false)->where('rentalOptions', null));
    }
}
