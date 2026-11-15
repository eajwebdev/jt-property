<?php

namespace Tests\Feature;

use App\Enums\RentMode;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\SecurityDeposit;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\TenancyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase2ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private TenancyService $service;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $this->service = app(TenancyService::class);
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
    }

    private function wholeUnit(string $code, int $capacity = 2): Unit
    {
        return Unit::factory()->for($this->property)->create(['code' => $code, 'capacity' => $capacity, 'rent_mode' => RentMode::Whole]);
    }

    private function tenant(string $name = 'Demo Tenant'): Tenant
    {
        return Tenant::factory()->for($this->property)->create(['full_name' => $name]);
    }

    private function d(string $date): Carbon
    {
        return Carbon::parse($date);
    }

    private function staffFor(Property ...$properties): User
    {
        $staff = User::factory()->create();
        foreach ($properties as $property) {
            $staff->properties()->attach($property);
        }

        return $staff;
    }

    private function assertRejected(string $key, callable $call): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), 'Errors were: '.json_encode($e->errors()));

            return;
        }

        $this->fail("Expected a validation error on [$key].");
    }

    /** Payer plus one resident sharing a whole room from 2026-01-01. */
    private function sharedRoom(): array
    {
        $unit = $this->wholeUnit('B201');
        $resident = $this->tenant('Demo Resident');
        $agreement = $this->service->startAgreement($this->tenant('Demo Payer'), $unit, null, 500000, 5, $this->d('2026-01-01'), ['resident_tenant_ids' => [$resident->id]]);

        return [$agreement, $resident, $unit];
    }

    private function tenantPayload(Tenant $tenant, array $overrides = []): array
    {
        return array_merge([
            'property_id' => $tenant->property_id,
            'full_name' => $tenant->full_name,
            'email' => $tenant->email,
            'mobile' => null,
            'preferred_delivery' => $tenant->preferred_delivery->value,
            'email_opt_in' => true,
        ], $overrides);
    }

    // ---- 1. Tenant property is locked once they have history ----

    public function test_tenant_with_a_rental_cannot_change_property_even_for_staff_assigned_to_both(): void
    {
        $other = Property::factory()->create();
        $agreement = $this->service->startAgreement($this->tenant(), $this->wholeUnit('A101', 1), null, 100000, 5, $this->d('2026-01-01'));
        $tenant = $agreement->billingAccount->payer;

        foreach ([$this->owner, $this->staffFor($this->property, $other)] as $user) {
            $this->actingAs($user)
                ->put(route('tenants.update', $tenant), $this->tenantPayload($tenant, ['property_id' => $other->id]))
                ->assertSessionHasErrors(['property_id' => 'This tenant has rentals at their current property. Start a transfer instead.']);
            $this->assertSame($this->property->id, $tenant->fresh()->property_id);
        }

        $this->put(route('tenants.update', $tenant), $this->tenantPayload($tenant, ['full_name' => 'Demo Renamed']))->assertSessionHasNoErrors();
        $this->assertSame('Demo Renamed', $tenant->fresh()->full_name);
    }

    public function test_tenant_with_only_an_occupancy_is_also_locked_and_a_fresh_tenant_can_still_move(): void
    {
        $other = Property::factory()->create();
        [, $resident] = $this->sharedRoom();

        $this->put(route('tenants.update', $resident), $this->tenantPayload($resident, ['property_id' => $other->id]))->assertSessionHasErrors('property_id');

        $fresh = $this->tenant('Demo Fresh');
        $this->put(route('tenants.update', $fresh), $this->tenantPayload($fresh, ['property_id' => $other->id]))->assertSessionHasNoErrors();
        $this->assertSame($other->id, $fresh->fresh()->property_id);
    }

    // ---- 2. Explicit transfer link ----

    public function test_transfer_stores_the_link_and_it_is_unique(): void
    {
        $a102 = $this->wholeUnit('A102', 1);
        $a103 = $this->wholeUnit('A103', 1);
        $old = $this->service->startAgreement($this->tenant(), $this->wholeUnit('A101', 1), null, 100000, 5, $this->d('2026-01-01'));

        $new = $this->service->transfer($old, $a102, null, $this->d('2026-05-01'), null, 'Move');

        $this->assertSame($old->id, $new->replaces_agreement_id);
        $this->assertTrue($old->fresh()->isReplaced());
        $this->assertSame($new->id, $old->replacedBy->id);
        $this->assertFalse($new->isReplaced());

        $this->expectException(QueryException::class);
        $other = $this->service->startAgreement($this->tenant('Demo Other'), $a103, null, 100000, 5, $this->d('2026-01-01'));
        $other->forceFill(['replaces_agreement_id' => $old->id])->save();
    }

    public function test_a_separate_rental_the_day_after_a_future_move_out_is_not_a_transfer(): void
    {
        $unitA = $this->wholeUnit('A101', 1);
        $unitB = $this->wholeUnit('A102', 1);
        $first = $this->service->startAgreement($this->tenant(), $unitA, null, 100000, 5, $this->d('2026-01-01'));
        $payer = $first->billingAccount->payer;
        $last = today()->addDays(10);
        $this->service->moveOut($first, $last, 'Leaving');

        $second = $this->service->startAgreement($payer, $unitB, null, 120000, 5, $last->copy()->addDay());

        $this->assertSame($first->billing_account_id, $second->billing_account_id);
        $this->assertNull($second->replaces_agreement_id);
        $this->assertFalse($first->fresh()->isReplaced());
        // The first rental can still be shortened.
        $this->service->moveOut($first, today()->addDays(5), 'Leaving sooner');
        $this->assertSame(today()->addDays(5)->toDateString(), $first->fresh()->end_date->toDateString());

        $this->get(route('tenants.show', $payer))
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.agreements.0.replaced', false)->where('accounts.0.agreements.1.replaced', false));
    }

    // ---- 3. One rent lookup ----

    public function test_index_show_and_rent_on_agree_with_a_future_dated_rate(): void
    {
        $agreement = $this->service->startAgreement($this->tenant('Demo Ana'), $this->wholeUnit('A101', 1), null, 250000, 5, $this->d('2026-01-01'));
        $this->service->changeRent($agreement, 300000, today()->addMonth(), 'Next month');
        $this->service->changeRent($agreement, 280000, today()->subDays(3)->max($this->d('2026-01-02')), 'Recent');
        $tenant = $agreement->billingAccount->payer;
        $expected = $agreement->rentOn(today());

        $this->assertSame(280000, $expected);
        $this->assertSame(['rent_cents' => 280000], ['rent_cents' => RentalAgreement::currentRents(collect([$agreement]), today())[$agreement->id]]);
        $this->get(route('tenants.show', $tenant))
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.agreements.0.current_rent_cents', $expected));
        $this->get(route('tenants.index'))
            ->assertInertia(fn (Assert $page) => $page->where('tenants.data.0.rent', '₱2,800.00'));
    }

    public function test_a_rental_that_has_not_started_has_no_current_rent_everywhere(): void
    {
        $agreement = $this->service->startAgreement($this->tenant(), $this->wholeUnit('A101', 1), null, 250000, 5, today()->addDays(10));
        $tenant = $agreement->billingAccount->payer;

        $this->assertNull($agreement->rentOn(today()));
        $this->assertSame([], RentalAgreement::currentRents(collect([$agreement]), today()));
        $this->get(route('tenants.show', $tenant))
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.agreements.0.current_rent_cents', null));
    }

    // ---- 4. Resident moves out on their own ----

    public function test_resident_moves_out_alone_and_capacity_frees_the_next_day(): void
    {
        [$agreement, $resident, $unit] = $this->sharedRoom();
        $occupancy = $agreement->occupancies()->where('tenant_id', $resident->id)->sole();

        $this->service->moveOutResident($occupancy, $this->d('2026-04-15'), 'Found own place');

        $this->assertSame('2026-04-15', $occupancy->fresh()->move_out_date->toDateString());
        $this->assertNull($agreement->fresh()->end_date);
        $log = ActivityLog::where('action', 'occupancy.moved_out')->sole();
        $this->assertSame('Found own place', $log->properties['reason']);
        $this->assertSame($occupancy->id, $log->subject_id);

        // Override skips the whole-room overlap rule, so only capacity (2 people) decides.
        $newcomer = $this->tenant('Demo Newcomer');
        $this->assertRejected('capacity', fn () => $this->service->startAgreement($newcomer, $unit, null, 100000, 5, $this->d('2026-04-15'), ['override_reason' => 'Owner approved sharing']));
        $this->service->startAgreement($newcomer, $unit, null, 100000, 5, $this->d('2026-04-16'), ['override_reason' => 'Owner approved sharing']);
    }

    public function test_a_resident_who_moved_out_can_be_archived(): void
    {
        [$agreement, $resident] = $this->sharedRoom();
        $occupancy = $agreement->occupancies()->where('tenant_id', $resident->id)->sole();

        $this->patch(route('tenants.archive', $resident))->assertSessionHasErrors('archive');

        $this->post(route('occupancies.move-out', $occupancy), ['move_out_date' => today()->subDay()->toDateString(), 'reason' => 'Left'])
            ->assertSessionHasNoErrors();
        $this->patch(route('tenants.archive', $resident))->assertSessionHasNoErrors();
        $this->assertNotNull($resident->fresh()->archived_at);
    }

    public function test_resident_move_out_rejects_payers_bad_dates_and_staff(): void
    {
        [$agreement, $resident] = $this->sharedRoom();
        $payerOccupancy = $agreement->occupancies()->where('is_payer', true)->sole();
        $occupancy = $agreement->occupancies()->where('tenant_id', $resident->id)->sole();
        $this->service->moveOut($agreement, today()->addDays(30), 'Family leaving');

        $this->assertRejected('occupancy', fn () => $this->service->moveOutResident($payerOccupancy, today()->addDays(5), 'No'));
        $this->assertRejected('move_out_date', fn () => $this->service->moveOutResident($occupancy, $this->d('2025-12-31'), 'Before move in'));
        $this->assertRejected('move_out_date', fn () => $this->service->moveOutResident($occupancy, today()->addDays(31), 'After the rental ends'));
        $this->assertSame(today()->addDays(30)->toDateString(), $occupancy->fresh()->move_out_date->toDateString());

        $this->actingAs($this->staffFor($this->property))
            ->post(route('occupancies.move-out', $occupancy), ['move_out_date' => today()->addDays(5)->toDateString(), 'reason' => 'Staff try'])
            ->assertForbidden();
        $this->assertSame(today()->addDays(30)->toDateString(), $occupancy->fresh()->move_out_date->toDateString());
    }

    public function test_show_page_flags_resident_occupancies_that_can_move_out(): void
    {
        [$agreement, $resident] = $this->sharedRoom();

        $this->get(route('tenants.show', $resident))
            ->assertInertia(fn (Assert $page) => $page->where('occupancies.0.is_payer', false)->where('occupancies.0.open', true)->where('canManageRentals', true));
    }

    // ---- 5. changeRent guards ----

    public function test_change_rent_is_refused_on_a_transferred_or_ended_agreement(): void
    {
        $a102 = $this->wholeUnit('A102', 1);
        $old = $this->service->startAgreement($this->tenant(), $this->wholeUnit('A101', 1), null, 100000, 5, $this->d('2026-01-01'));
        $this->service->transfer($old, $a102, null, today()->addDays(10), null, 'Move');

        $this->assertRejected('agreement', fn () => $this->service->changeRent($old, 150000, today()->addDays(2), 'On the old one'));

        $ended = $this->service->startAgreement($this->tenant('Demo Gone'), $this->wholeUnit('A103', 1), null, 100000, 5, $this->d('2026-01-01'));
        $this->service->moveOut($ended, $this->d('2026-03-31'), 'Gone');

        $this->assertRejected('agreement', fn () => $this->service->changeRent($ended, 150000, $this->d('2026-02-01'), 'After the fact'));
        $this->assertSame(1, $ended->rentRates()->count());
    }

    // ---- 6. Deposit at rental start ----

    public function test_deposit_at_start_goes_through_record_deposit(): void
    {
        $past = $this->service->startAgreement($this->tenant(), $this->wholeUnit('A101', 1), null, 100000, 5, $this->d('2026-01-01'), ['deposit_cents' => 200000]);
        $future = $this->service->startAgreement($this->tenant('Demo Later'), $this->wholeUnit('A102', 1), null, 100000, 5, today()->addDays(20), ['deposit_cents' => 300000]);

        $pastDeposit = SecurityDeposit::where('rental_agreement_id', $past->id)->sole();
        $futureDeposit = SecurityDeposit::where('rental_agreement_id', $future->id)->sole();
        $this->assertSame('2026-01-01', $pastDeposit->received_on->toDateString());
        $this->assertSame(today()->toDateString(), $futureDeposit->received_on->toDateString());
        $this->assertSame('held', $futureDeposit->status->value);
        $this->assertSame(2, ActivityLog::where('action', 'deposit.recorded')->count());
        $this->assertSame($futureDeposit->id, ActivityLog::where('action', 'deposit.recorded')->latest('id')->first()->subject_id);
    }

    // ---- 7. Archive under lock ----

    public function test_archive_locks_the_tenant_row_inside_the_transaction(): void
    {
        $tenant = $this->tenant();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $this->patch(route('tenants.archive', $tenant))->assertSessionHasNoErrors();

        $this->assertNotNull($tenant->fresh()->archived_at);
        $this->assertTrue(collect($queries)->contains(fn ($sql) => str_contains($sql, 'from `tenants`') && str_contains($sql, 'for update')));
    }

    // ---- 8. Index shows rent/due only for payers ----

    public function test_index_shows_residents_as_paid_by_the_payer_without_rent(): void
    {
        [, $resident] = $this->sharedRoom();

        $this->get(route('tenants.index'))->assertInertia(function (Assert $page) use ($resident) {
            $rows = collect($page->toArray()['props']['tenants']['data'])->keyBy('name');
            $this->assertSame('₱5,000.00', $rows['Demo Payer']['rent']);
            $this->assertSame(5, $rows['Demo Payer']['due_day']);
            $this->assertNull($rows['Demo Payer']['paid_by']);
            $this->assertNull($rows[$resident->full_name]['rent']);
            $this->assertNull($rows[$resident->full_name]['due_day']);
            $this->assertSame('Demo Payer', $rows[$resident->full_name]['paid_by']);
        });
    }
}
