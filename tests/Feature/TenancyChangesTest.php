<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\AgreementStatus;
use App\Enums\RentMode;
use App\Models\ActivityLog;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\Occupancy;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\SecurityDeposit;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\TenancyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenancyChangesTest extends TestCase
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

    private function perBedUnit(string $code, int $capacity = 4): Unit
    {
        $unit = Unit::factory()->for($this->property)->create(['code' => $code, 'capacity' => $capacity, 'rent_mode' => RentMode::PerBed]);
        foreach (range(1, $capacity) as $n) {
            $unit->beds()->create(['label' => "Bed $n"]);
        }

        return $unit;
    }

    private function wholeUnit(string $code, int $capacity = 4): Unit
    {
        return Unit::factory()->for($this->property)->create(['code' => $code, 'capacity' => $capacity, 'rent_mode' => RentMode::Whole]);
    }

    private function tenant(string $name = 'Demo Tenant'): Tenant
    {
        return Tenant::factory()->for($this->property)->create(['full_name' => $name]);
    }

    private function bed(Unit $unit, int $n): Bed
    {
        return $unit->beds()->where('label', "Bed $n")->firstOrFail();
    }

    private function d(string $date): Carbon
    {
        return Carbon::parse($date);
    }

    /** Ana in A101 Bed 1 at 2,500 from 2026-01-01, due day 5. */
    private function anaInA101(?Unit $unit = null): RentalAgreement
    {
        $unit ??= $this->perBedUnit('A101');

        return $this->service->startAgreement($this->tenant('Demo Ana'), $unit, $this->bed($unit, 1), 250000, 5, $this->d('2026-01-01'), ['issue_lead_days' => 10]);
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

    private function counts(): array
    {
        return [
            BillingAccount::count(), RentalAgreement::count(), RentRate::count(),
            Occupancy::count(), SecurityDeposit::count(), ActivityLog::count(),
        ];
    }

    private function lastLog(string $action): ActivityLog
    {
        return ActivityLog::where('action', $action)->latest('id')->firstOrFail();
    }

    // ---- Rent changes ----

    public function test_rent_change_takes_effect_from_its_date_and_never_edits_the_old_rate(): void
    {
        $agreement = $this->anaInA101();
        $original = RentRate::sole()->getAttributes();

        $rate = $this->service->changeRent($agreement, 280000, $this->d('2026-03-01'), 'Yearly increase');

        $this->assertSame(250000, $agreement->rentOn('2026-02-28'));
        $this->assertSame(280000, $agreement->rentOn('2026-03-01'));
        $this->assertSame('Yearly increase', $rate->reason);
        $this->assertSame($this->owner->id, $rate->created_by);
        $this->assertSame($original, RentRate::find($original['id'])->getAttributes());

        $log = $this->lastLog('rent.changed');
        $this->assertSame('Yearly increase', $log->properties['reason']);
        $this->assertSame(250000, $log->properties['old']['rent_cents']);
        $this->assertSame(280000, $log->properties['new']['rent_cents']);
        $this->assertSame('2026-03-01', $log->properties['new']['effective_from']);
    }

    public function test_future_rent_change_does_not_change_todays_rent(): void
    {
        $agreement = $this->anaInA101();

        $this->service->changeRent($agreement, 300000, today()->addMonth(), 'Next month increase');

        $this->assertSame(250000, $agreement->rentOn(today()));
        $this->assertSame(300000, $agreement->rentOn(today()->addMonth()));
    }

    public function test_rent_change_before_start_or_on_an_existing_date_is_rejected(): void
    {
        $agreement = $this->anaInA101();
        $before = $this->counts();

        $this->assertRejected('effective_from', fn () => $this->service->changeRent($agreement, 280000, $this->d('2025-12-31'), 'Too early'));
        $this->assertRejected('effective_from', fn () => $this->service->changeRent($agreement, 280000, $this->d('2026-01-01'), 'Same day as start'));

        $this->assertSame($before, $this->counts());
    }

    // ---- Move-out ----

    public function test_move_out_closes_occupancies_and_frees_the_bed_from_the_next_day(): void
    {
        $unit = $this->perBedUnit('A101');
        $agreement = $this->anaInA101($unit);

        $this->service->moveOut($agreement, $this->d('2026-04-15'), 'Moved back home');

        $agreement->refresh();
        $this->assertSame('2026-04-15', $agreement->end_date->toDateString());
        $this->assertSame(AgreementStatus::Ended, $agreement->status);
        $this->assertSame('2026-04-15', Occupancy::sole()->move_out_date->toDateString());
        $this->assertSame(AccountStatus::Closed, $agreement->billingAccount->status);
        $this->assertSame('Moved back home', $this->lastLog('agreement.moved_out')->properties['reason']);
        $this->assertNull($this->lastLog('agreement.moved_out')->properties['old']['end_date']);

        $ben = $this->tenant('Demo Ben');
        $this->assertRejected('unit', fn () => $this->service->startAgreement($ben, $unit, $this->bed($unit, 1), 260000, 10, $this->d('2026-04-15')));
        $next = $this->service->startAgreement($ben, $unit, $this->bed($unit, 1), 260000, 10, $this->d('2026-04-16'));
        $this->assertSame(260000, $next->rentOn('2026-04-16'));
    }

    public function test_future_move_out_keeps_the_rental_active_until_then(): void
    {
        $agreement = $this->anaInA101();
        $last = today()->addDays(20);

        $this->service->moveOut($agreement, $last, 'Leaving at end of month');

        $agreement->refresh();
        $this->assertSame(AgreementStatus::Active, $agreement->status);
        $this->assertTrue(RentalAgreement::activeOn($last)->whereKey($agreement->id)->exists());
        $this->assertFalse(RentalAgreement::activeOn($last->copy()->addDay())->whereKey($agreement->id)->exists());
        $this->assertFalse(RentalAgreement::activeOn('2025-12-31')->whereKey($agreement->id)->exists());
        $this->assertSame(AccountStatus::Active, $agreement->billingAccount->status);
    }

    public function test_once_a_future_move_out_has_passed_the_tenant_can_be_archived_and_the_room_mode_changed(): void
    {
        $unit = $this->perBedUnit('A101');
        $agreement = $this->anaInA101($unit);
        $tenant = $agreement->billingAccount->payer;
        $this->service->moveOut($agreement, today()->addDays(5), 'Leaving soon');
        $unitPayload = ['code' => 'A101', 'capacity' => 4, 'rent_mode' => 'whole', 'status' => 'available'];

        $this->patch(route('tenants.archive', $tenant))->assertSessionHasErrors('archive');
        $this->put(route('units.update', $unit), $unitPayload)->assertSessionHasErrors('rent_mode');

        $this->travelTo(today()->addDays(6));

        // The status column still says active: only dates decide.
        $this->assertSame(AgreementStatus::Active, $agreement->fresh()->status);
        $this->patch(route('tenants.archive', $tenant))->assertSessionHasNoErrors();
        $this->assertNotNull($tenant->fresh()->archived_at);
        $this->put(route('units.update', $unit), $unitPayload)->assertSessionHasNoErrors();
        $this->assertSame(RentMode::Whole, $unit->fresh()->rent_mode);
    }

    public function test_move_out_before_start_is_rejected(): void
    {
        $agreement = $this->anaInA101();
        $before = $this->counts();

        $this->assertRejected('move_out_date', fn () => $this->service->moveOut($agreement, $this->d('2025-12-31'), 'Wrong date'));

        $this->assertSame($before, $this->counts());
        $this->assertNull($agreement->fresh()->end_date);
    }

    public function test_billing_account_stays_open_while_another_rental_continues(): void
    {
        $agreement = $this->anaInA101();
        $other = $this->wholeUnit('B201');
        $second = $agreement->billingAccount->agreements()->create([
            'unit_id' => $other->id, 'due_day' => 5, 'start_date' => '2026-01-01', 'status' => AgreementStatus::Active,
        ]);
        $second->rentRates()->create(['rent_cents' => 100000, 'effective_from' => '2026-01-01']);

        $this->service->moveOut($agreement, $this->d('2026-04-15'), 'Gave up the bed');

        $this->assertSame(AccountStatus::Active, $agreement->billingAccount->fresh()->status);
    }

    // ---- Transfers ----

    public function test_transfer_ends_old_agreement_the_day_before_and_carries_rent_and_settings(): void
    {
        $a101 = $this->perBedUnit('A101');
        $a102 = $this->perBedUnit('A102');
        $agreement = $this->anaInA101($a101);
        $this->service->changeRent($agreement, 280000, $this->d('2026-03-01'), 'Yearly increase');

        $new = $this->service->transfer($agreement, $a102, $this->bed($a102, 2), $this->d('2026-05-01'), null, 'Wanted a quieter room');

        $agreement->refresh();
        $this->assertSame('2026-04-30', $agreement->end_date->toDateString());
        $this->assertSame(AgreementStatus::Ended, $agreement->status);
        $this->assertSame($agreement->billing_account_id, $new->billing_account_id);
        $this->assertSame([$a102->id, $this->bed($a102, 2)->id], [$new->unit_id, $new->bed_id]);
        $this->assertSame('2026-05-01', $new->start_date->toDateString());
        $this->assertSame([5, 1, 10, false], [$new->due_day, $new->cycle_start_day, $new->issue_lead_days, $new->prorate]);
        $this->assertSame(280000, $new->rentOn('2026-05-01'));
        $this->assertSame('2026-05-01', $new->rentRates()->sole()->effective_from->toDateString());
        $this->assertSame(AccountStatus::Active, $new->billingAccount->status);

        $this->assertSame('2026-04-30', $agreement->occupancies()->sole()->move_out_date->toDateString());
        $moved = $new->occupancies()->sole();
        $this->assertTrue($moved->is_payer);
        $this->assertSame('2026-05-01', $moved->move_in_date->toDateString());

        $log = $this->lastLog('agreement.transferred');
        $this->assertSame('Wanted a quieter room', $log->properties['reason']);
        $this->assertSame($new->id, $log->properties['new']['rental_agreement_id']);
        $this->assertSame(280000, $log->properties['new']['rent_cents']);
    }

    public function test_transfer_with_new_rent(): void
    {
        $a102 = $this->perBedUnit('A102');
        $agreement = $this->anaInA101();

        $new = $this->service->transfer($agreement, $a102, $this->bed($a102, 2), $this->d('2026-05-01'), 300000, 'Bigger bed');

        $this->assertSame(250000, $agreement->rentOn('2026-04-30'));
        $this->assertSame(300000, $new->rentOn('2026-05-01'));
    }

    public function test_transfer_into_a_full_room_is_rejected_and_rolls_back_completely(): void
    {
        $agreement = $this->anaInA101();
        $full = $this->wholeUnit('C301', 1);
        $this->service->startAgreement($this->tenant('Demo Cara'), $full, null, 200000, 5, $this->d('2026-01-01'));
        $before = $this->counts();

        $this->assertRejected('capacity', fn () => $this->service->transfer($agreement, $full, null, $this->d('2026-05-01'), null, 'Try the full room'));

        $this->assertSame($before, $this->counts());
        $agreement->refresh();
        $this->assertNull($agreement->end_date);
        $this->assertSame(AgreementStatus::Active, $agreement->status);
        $this->assertNull($agreement->occupancies()->sole()->move_out_date);
    }

    public function test_transfer_on_or_before_the_start_date_is_rejected(): void
    {
        $a102 = $this->perBedUnit('A102');
        $agreement = $this->anaInA101();

        $this->assertRejected('transfer_date', fn () => $this->service->transfer($agreement, $a102, $this->bed($a102, 1), $this->d('2026-01-01'), null, 'Too early'));
        $this->assertNull($agreement->fresh()->end_date);
    }

    public function test_transfer_before_a_planned_move_out_keeps_the_account_open_and_the_end_date(): void
    {
        $a102 = $this->perBedUnit('A102');
        $agreement = $this->anaInA101();
        $last = today()->addDays(60);
        $this->service->moveOut($agreement, $last, 'Leaving in two months');
        $this->assertSame(AccountStatus::Active, $agreement->billingAccount->fresh()->status);

        $new = $this->service->transfer($agreement, $a102, $this->bed($a102, 1), today()->addDays(10), null, 'Change before leaving');

        $this->assertSame(AccountStatus::Active, $new->billingAccount->fresh()->status);
        $this->assertSame(1, BillingAccount::count());
        $this->assertSame($last->toDateString(), $new->end_date->toDateString());
        $this->assertSame($last->toDateString(), $new->occupancies()->sole()->move_out_date->toDateString());
    }

    public function test_transfer_carries_the_rent_of_the_move_day_and_later_scheduled_rates(): void
    {
        $a102 = $this->perBedUnit('A102');
        $agreement = $this->anaInA101();
        $this->service->changeRent($agreement, 280000, $this->d('2026-05-01'), 'Yearly increase');
        $this->service->changeRent($agreement, 300000, $this->d('2026-07-01'), 'Aircon added');
        $oldRates = RentRate::orderBy('id')->get()->map->getAttributes()->all();

        $new = $this->service->transfer($agreement, $a102, $this->bed($a102, 2), $this->d('2026-05-01'), null, 'Quieter room');

        $this->assertSame(280000, $new->rentOn('2026-05-01'));
        $this->assertSame(280000, $new->rentOn('2026-06-30'));
        $this->assertSame(300000, $new->rentOn('2026-07-01'));
        $carried = $new->rentRates()->whereDate('effective_from', '2026-07-01')->sole();
        $this->assertSame('Carried from transfer: Aircon added', $carried->reason);
        $this->assertSame(2, $new->rentRates()->count());
        // Old rows untouched.
        foreach ($oldRates as $attributes) {
            $this->assertSame($attributes, RentRate::find($attributes['id'])->getAttributes());
        }

        $log = $this->lastLog('agreement.transferred')->properties;
        $this->assertSame(250000, $log['old']['rent_cents']);
        $this->assertSame(280000, $log['new']['rent_cents']);
        $this->assertSame([['effective_from' => '2026-07-01', 'rent_cents' => 300000]], $log['new']['carried_rates']);
    }

    public function test_a_transferred_agreement_cannot_be_moved_out_or_transferred_again(): void
    {
        $a102 = $this->perBedUnit('A102');
        $a103 = $this->perBedUnit('A103');
        $agreement = $this->anaInA101();
        $this->service->transfer($agreement, $a102, $this->bed($a102, 1), today()->addDays(10), null, 'First move');
        $before = $this->counts();

        $this->assertRejected('agreement', fn () => $this->service->moveOut($agreement, today()->addDays(5), 'Leaving'));
        $this->assertRejected('agreement', fn () => $this->service->transfer($agreement, $a103, $this->bed($a103, 1), today()->addDays(5), null, 'Again'));

        $this->assertSame($before, $this->counts());
        $this->assertSame(today()->addDays(9)->toDateString(), $agreement->fresh()->end_date->toDateString());

        $this->get(route('tenants.show', $agreement->billingAccount->payer))
            ->assertInertia(fn (Assert $page) => $page->where('accounts.0.agreements.0.replaced', true)
                ->where('accounts.0.agreements.1.replaced', false));
    }

    public function test_whole_room_transfer_moves_residents_along(): void
    {
        $b201 = $this->wholeUnit('B201');
        $b202 = $this->wholeUnit('B202');
        $resident = $this->tenant('Demo Resident');
        $agreement = $this->service->startAgreement($this->tenant('Demo Payer'), $b201, null, 500000, 5, $this->d('2026-01-01'), ['resident_tenant_ids' => [$resident->id]]);

        $new = $this->service->transfer($agreement, $b202, null, $this->d('2026-06-01'), null, 'Family moved rooms');

        $this->assertSame(2, $new->occupancies()->count());
        $this->assertTrue($new->occupancies()->where('tenant_id', $resident->id)->where('is_payer', false)->exists());
        $this->assertSame(0, $agreement->occupancies()->whereNull('move_out_date')->count());
    }

    // ---- Deposits ----

    public function test_record_deposit_is_audited_and_does_not_touch_rent(): void
    {
        $agreement = $this->anaInA101();

        $deposit = $this->service->recordDeposit($agreement->billingAccount, 500000, $this->d('2026-01-03'), $agreement, 'Cash, two months');

        $this->assertSame(500000, $deposit->amount_cents);
        $this->assertSame('2026-01-03', $deposit->received_on->toDateString());
        $this->assertSame('held', $deposit->status->value);
        $this->assertSame(1, RentRate::count());
        $this->assertSame(250000, $agreement->rentOn('2026-01-03'));
        $this->assertSame('Cash, two months', $this->lastLog('deposit.recorded')->properties['reason']);
    }

    // ---- HTTP ----

    public function test_staff_and_accountant_cannot_post_any_change(): void
    {
        $agreement = $this->anaInA101();
        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $before = $this->counts();

        foreach ([$staff, User::factory()->accountant()->create()] as $user) {
            $this->actingAs($user)->post(route('agreements.rent.store', $agreement), ['rent' => '2,800', 'effective_from' => '2026-03-01', 'reason' => 'Increase'])->assertForbidden();
            $this->actingAs($user)->post(route('agreements.move-out', $agreement), ['move_out_date' => '2026-04-15', 'reason' => 'Leaving'])->assertForbidden();
            $this->actingAs($user)->post(route('agreements.transfer', $agreement), ['unit_id' => $agreement->unit_id, 'transfer_date' => '2026-05-01', 'reason' => 'Move'])->assertForbidden();
            $this->actingAs($user)->post(route('accounts.deposits.store', $agreement->billingAccount), ['amount' => '5,000', 'received_on' => '2026-01-03'])->assertForbidden();
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_owner_changes_rent_over_http_with_peso_input(): void
    {
        $agreement = $this->anaInA101();

        $this->post(route('agreements.rent.store', $agreement), ['rent' => '2,800.00', 'effective_from' => '2026-03-01', 'reason' => 'Yearly increase'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(280000, $agreement->rentOn('2026-03-01'));
        $this->post(route('agreements.rent.store', $agreement), ['rent' => '2,800.00', 'effective_from' => '2026-03-01', 'reason' => 'Again'])
            ->assertSessionHasErrors('effective_from');
        $this->post(route('agreements.rent.store', $agreement), ['rent' => '28,00', 'effective_from' => '2026-04-01', 'reason' => 'Typo'])
            ->assertSessionHasErrors('rent');
        $this->post(route('agreements.rent.store', $agreement), ['rent' => '2,900', 'effective_from' => '2026-04-01', 'reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    public function test_owner_moves_out_transfers_and_records_deposit_over_http(): void
    {
        $a102 = $this->perBedUnit('A102');
        $agreement = $this->anaInA101();

        $this->post(route('accounts.deposits.store', $agreement->billingAccount), ['amount' => '5,000', 'received_on' => '2026-01-03', 'rental_agreement_id' => $agreement->id, 'notes' => 'Cash'])
            ->assertSessionHasNoErrors();
        $this->assertSame(500000, SecurityDeposit::sole()->amount_cents);

        $this->post(route('agreements.transfer', $agreement), ['unit_id' => $a102->id, 'bed_id' => $this->bed($a102, 2)->id, 'transfer_date' => '2026-05-01', 'rent' => '', 'reason' => 'Quieter room'])
            ->assertSessionHasNoErrors();
        $new = RentalAgreement::latest('id')->first();
        $this->assertSame(250000, $new->rentOn('2026-05-01'));

        $this->post(route('agreements.move-out', $new), ['move_out_date' => '2026-08-31', 'reason' => 'Finished studies'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-08-31', $new->fresh()->end_date->toDateString());
    }

    public function test_show_page_offers_change_actions_to_owner_only(): void
    {
        $agreement = $this->anaInA101();
        $tenant = $agreement->billingAccount->payer;

        $this->get(route('tenants.show', $tenant))
            ->assertInertia(fn (Assert $page) => $page->where('canManageRentals', true)
                ->where('accounts.0.agreements.0.current_rent_cents', 250000)
                ->where('accounts.0.agreements.0.open', true));

        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $this->actingAs($staff)->get(route('tenants.show', $tenant))
            ->assertInertia(fn (Assert $page) => $page->where('canManageRentals', false));
    }
}
