<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BillingAccount;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\SecurityDeposit;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantTest extends TestCase
{
    use RefreshDatabase;

    private function staffFor(Property $property): User
    {
        $staff = User::factory()->create();
        $staff->properties()->attach($property);

        return $staff;
    }

    private function payload(Property $property, array $overrides = []): array
    {
        return array_merge([
            'property_id' => $property->id,
            'full_name' => 'Demo Juan Dela Cruz',
            'email' => 'juan@example.com',
            'mobile' => '09171234567',
            'preferred_delivery' => 'email',
            'email_opt_in' => true,
        ], $overrides);
    }

    public function test_staff_creates_tenant_in_assigned_property(): void
    {
        $property = Property::factory()->create();

        $this->actingAs($this->staffFor($property))
            ->post(route('tenants.store'), $this->payload($property))
            ->assertRedirect();

        $tenant = Tenant::firstWhere('full_name', 'Demo Juan Dela Cruz');
        $this->assertSame($property->id, $tenant->property_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'tenant.created', 'subject_id' => $tenant->id]);
    }

    public function test_staff_cannot_create_in_unassigned_property(): void
    {
        $mine = Property::factory()->create();
        $other = Property::factory()->create();

        $this->actingAs($this->staffFor($mine))
            ->post(route('tenants.store'), $this->payload($other))
            ->assertForbidden();

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_accountant_cannot_create_or_edit(): void
    {
        $property = Property::factory()->create();
        $tenant = Tenant::factory()->for($property)->create();
        $accountant = User::factory()->accountant()->create();

        $this->actingAs($accountant)->post(route('tenants.store'), $this->payload($property))->assertForbidden();
        $this->actingAs($accountant)->put(route('tenants.update', $tenant), $this->payload($property))->assertForbidden();
        $this->actingAs($accountant)->get(route('tenants.show', $tenant))->assertOk();
    }

    public function test_staff_cannot_view_or_edit_tenant_of_other_property(): void
    {
        $mine = Property::factory()->create();
        $tenant = Tenant::factory()->create();
        $staff = $this->staffFor($mine);

        $this->actingAs($staff)->get(route('tenants.show', $tenant))->assertForbidden();
        $this->actingAs($staff)->get(route('tenants.edit', $tenant))->assertForbidden();
        $this->actingAs($staff)->put(route('tenants.update', $tenant), $this->payload($mine))->assertForbidden();
        $this->actingAs($staff)->patch(route('tenants.archive', $tenant))->assertForbidden();
    }

    public function test_staff_cannot_move_tenant_to_unassigned_property(): void
    {
        $mine = Property::factory()->create();
        $other = Property::factory()->create();
        $tenant = Tenant::factory()->for($mine)->create();

        $this->actingAs($this->staffFor($mine))
            ->put(route('tenants.update', $tenant), $this->payload($other))
            ->assertForbidden();
    }

    public function test_update_saves_and_audits(): void
    {
        $property = Property::factory()->create();
        $tenant = Tenant::factory()->for($property)->create(['full_name' => 'Demo Old']);

        $this->actingAs(User::factory()->owner()->create())
            ->put(route('tenants.update', $tenant), $this->payload($property, ['full_name' => 'Demo New']))
            ->assertRedirect(route('tenants.show', $tenant));

        $this->assertSame('Demo New', $tenant->fresh()->full_name);
        $this->assertDatabaseHas('activity_logs', ['action' => 'tenant.updated', 'subject_id' => $tenant->id]);
    }

    public function test_mobile_format_and_email_validation(): void
    {
        $property = Property::factory()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->post(route('tenants.store'), $this->payload($property, ['mobile' => '12345']))->assertSessionHasErrors('mobile');
        $this->actingAs($owner)->post(route('tenants.store'), $this->payload($property, ['email' => 'nope']))->assertSessionHasErrors('email');
        $this->actingAs($owner)->post(route('tenants.store'), $this->payload($property, ['mobile' => ' +639171234567 ', 'email' => null]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenants', ['mobile' => '+639171234567', 'email' => null]);
    }

    public function test_search_finds_partial_name_email_and_mobile(): void
    {
        $property = Property::factory()->create();
        Tenant::factory()->for($property)->create(['full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'mobile' => '09181111111']);
        Tenant::factory()->for($property)->create(['full_name' => 'Pedro Reyes', 'email' => 'pedro@example.com', 'mobile' => '09192222222']);
        $owner = User::factory()->owner()->create();

        foreach (['sant' => 'Maria Santos', 'pedro@' => 'Pedro Reyes', '0919222' => 'Pedro Reyes'] as $q => $name) {
            $this->actingAs($owner)->get(route('tenants.index', ['q' => $q]))
                ->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1)->where('tenants.data.0.name', $name));
        }
    }

    public function test_filter_no_email_returns_only_tenants_without_email(): void
    {
        $property = Property::factory()->create();
        Tenant::factory()->for($property)->create(['email' => null]);
        Tenant::factory()->for($property)->create();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('tenants.index', ['status' => 'no_email']))
            ->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1)->where('tenants.data.0.email', null));
    }

    public function test_status_filters_and_archived_hidden_by_default(): void
    {
        $property = Property::factory()->create();
        $active = Tenant::factory()->for($property)->create();
        Occupancy::factory()->for($active)->create();
        $vacated = Tenant::factory()->for($property)->create();
        Occupancy::factory()->for($vacated)->create(['move_out_date' => '2026-02-01']);
        $archived = Tenant::factory()->for($property)->create(['archived_at' => now()]);
        $owner = User::factory()->owner()->create();

        $ids = fn (array $q) => collect($this->actingAs($owner)->get(route('tenants.index', $q))->viewData('page')['props']['tenants']['data'])->pluck('id')->sort()->values()->all();

        $this->assertSame([$active->id, $vacated->id], $ids([]));
        $this->assertSame([$active->id], $ids(['status' => 'active']));
        $this->assertSame([$vacated->id], $ids(['status' => 'vacated']));
        $this->assertSame([$archived->id], $ids(['status' => 'archived']));
    }

    public function test_index_is_scoped_for_staff_and_filters_by_property(): void
    {
        $mine = Property::factory()->create();
        $other = Property::factory()->create();
        $mineTenant = Tenant::factory()->for($mine)->create();
        Tenant::factory()->for($other)->create();

        $this->actingAs($this->staffFor($mine))->get(route('tenants.index'))
            ->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1)->where('tenants.data.0.id', $mineTenant->id)->has('properties', 1));

        $this->actingAs(User::factory()->owner()->create())->get(route('tenants.index', ['property' => $other->id]))
            ->assertInertia(fn (Assert $page) => $page->has('tenants.data', 1));
    }

    public function test_index_shows_current_rent_and_due_day_without_n_plus_one(): void
    {
        $property = Property::factory()->create();
        $owner = User::factory()->owner()->create();
        $queries = [];

        foreach (range(1, 5) as $i) {
            $tenant = Tenant::factory()->for($property)->create();
            $account = BillingAccount::factory()->create(['property_id' => $property->id, 'payer_tenant_id' => $tenant->id]);
            $agreement = RentalAgreement::factory()->create(['billing_account_id' => $account->id, 'due_day' => 15]);
            RentRate::factory()->for($agreement)->create(['rent_cents' => 300000, 'effective_from' => '2026-01-01']);
            RentRate::factory()->for($agreement)->create(['rent_cents' => 999900, 'effective_from' => now()->addMonth()->toDateString()]);
            Occupancy::factory()->for($tenant)->create(['unit_id' => $agreement->unit_id, 'rental_agreement_id' => $agreement->id, 'is_payer' => true]);
        }

        \DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->actingAs($owner)->get(route('tenants.index'))
            ->assertInertia(fn (Assert $page) => $page->has('tenants.data', 5)
                ->where('tenants.data.0.rent', '₱3,000.00')->where('tenants.data.0.due_day', 15));

        $this->assertLessThan(20, count($queries));
    }

    public function test_archive_refused_with_active_agreement(): void
    {
        $property = Property::factory()->create();
        $tenant = Tenant::factory()->for($property)->create();
        $account = BillingAccount::factory()->create(['property_id' => $property->id, 'payer_tenant_id' => $tenant->id]);
        RentalAgreement::factory()->create(['billing_account_id' => $account->id]);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('tenants.archive', $tenant))
            ->assertSessionHasErrors('archive');

        $this->assertNull($tenant->fresh()->archived_at);
    }

    public function test_archive_refused_with_active_occupancy(): void
    {
        $tenant = Tenant::factory()->create();
        Occupancy::factory()->for($tenant)->create();

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('tenants.archive', $tenant))
            ->assertSessionHasErrors('archive');
    }

    public function test_archive_without_active_records_sets_archived_at_and_audits(): void
    {
        $tenant = Tenant::factory()->create();
        Occupancy::factory()->for($tenant)->create(['move_out_date' => '2026-02-01']);

        $this->actingAs(User::factory()->owner()->create())
            ->patch(route('tenants.archive', $tenant))
            ->assertRedirect();

        $this->assertNotNull($tenant->fresh()->archived_at);
        $this->assertSame(1, ActivityLog::where('action', 'tenant.archived')->where('subject_id', $tenant->id)->count());
    }

    public function test_show_lists_history(): void
    {
        $property = Property::factory()->create();
        $tenant = Tenant::factory()->for($property)->create();
        $account = BillingAccount::factory()->create(['property_id' => $property->id, 'payer_tenant_id' => $tenant->id]);
        $agreement = RentalAgreement::factory()->create(['billing_account_id' => $account->id]);
        RentRate::factory()->for($agreement)->create();
        Occupancy::factory()->for($tenant)->create(['unit_id' => $agreement->unit_id, 'rental_agreement_id' => $agreement->id]);
        SecurityDeposit::factory()->create(['billing_account_id' => $account->id]);

        $this->actingAs(User::factory()->owner()->create())->get(route('tenants.show', $tenant))
            ->assertInertia(fn (Assert $page) => $page->component('tenants/show')
                ->has('accounts', 1)->has('accounts.0.agreements', 1)->has('accounts.0.agreements.0.rates', 1)
                ->has('accounts.0.deposits', 1)->has('occupancies', 1));
    }

    public function test_archived_tenant_is_read_only(): void
    {
        $property = Property::factory()->create();
        $tenant = Tenant::factory()->for($property)->create(['archived_at' => now()]);
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('tenants.edit', $tenant))->assertForbidden();
        $this->actingAs($owner)->put(route('tenants.update', $tenant), $this->payload($property))->assertForbidden();
        $this->actingAs($owner)->patch(route('tenants.archive', $tenant))->assertForbidden();
        $this->assertSame(0, ActivityLog::where('action', 'tenant.archived')->count());
        $this->actingAs($owner)->get(route('tenants.show', $tenant))
            ->assertInertia(fn (Assert $page) => $page->where('canEdit', false)->where('canArchive', false));
    }

    public function test_future_only_tenant_is_upcoming_not_vacated(): void
    {
        $property = Property::factory()->create();
        $future = Tenant::factory()->for($property)->create();
        Occupancy::factory()->for($future)->create(['move_in_date' => today()->addDays(10)->toDateString()]);
        $ended = Tenant::factory()->for($property)->create();
        Occupancy::factory()->for($ended)->create(['move_out_date' => '2026-02-01']);
        $owner = User::factory()->owner()->create();

        $ids = fn (array $q) => collect($this->actingAs($owner)->get(route('tenants.index', $q))->viewData('page')['props']['tenants']['data'])->pluck('id')->all();

        $this->assertSame([$future->id], $ids(['status' => 'upcoming']));
        $this->assertSame([$ended->id], $ids(['status' => 'vacated']));
        $this->actingAs($owner)->get(route('tenants.index', ['status' => 'upcoming']))
            ->assertInertia(fn (Assert $page) => $page->where('tenants.data.0.status', 'upcoming')
                ->where('tenants.data.0.moving_in', today()->addDays(10)->toDateString()));
    }

    public function test_no_destroy_route_exists(): void
    {
        $this->assertFalse(Route::has('tenants.destroy'));
    }
}
