<?php

namespace Tests\Feature\Billing;

use App\Enums\RentMode;
use App\Models\ActivityLog;
use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Models\UtilityBill;
use App\Models\UtilityExclusion;
use App\Models\UtilitySplitShare;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\UtilityAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UtilitiesHttpTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Unit $unit;

    private RentalAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $this->unit = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::Whole]);
        $this->agreement = $this->agreement($this->unit);
    }

    private function agreement(Unit $unit): RentalAgreement
    {
        return RentalAgreement::factory()->for(BillingAccount::factory()->for($this->property)->create(), 'billingAccount')->for($unit)->create(['start_date' => '2026-01-01']);
    }

    private function staff(bool $assigned = true): User
    {
        $user = User::factory()->create();
        if ($assigned) {
            $user->properties()->attach($this->property);
        }

        return $user;
    }

    private function metered(array $extra = []): UnitUtility
    {
        return UnitUtility::create(array_merge(['unit_id' => $this->unit->id, 'type' => 'electricity', 'mode' => 'metered', 'rate_e4' => 115432], $extra));
    }

    private function reading(array $overrides = []): array
    {
        return array_merge([
            'unit_id' => $this->unit->id, 'type' => 'electricity', 'bill_month' => '2026-11',
            'previous_reading' => '1000', 'current_reading' => '1042.5',
        ], $overrides);
    }

    public function test_owner_configures_a_metered_unit_and_it_is_audited(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->put(route('utilities.configure', [$this->unit, 'electricity']), ['mode' => 'metered', 'rate' => '11.5432', 'base_charge' => '150'])->assertRedirect();

        $u = UnitUtility::firstOrFail();
        $this->assertSame([115432, 15000, 'metered'], [$u->rate_e4, $u->base_charge_cents, $u->mode]);
        $this->assertTrue(ActivityLog::where('action', 'unit_utility.configured')->exists());
    }

    public function test_only_the_owner_configures_utilities(): void
    {
        foreach ([$this->staff(), User::factory()->accountant()->create()] as $user) {
            $this->actingAs($user)->put(route('utilities.configure', [$this->unit, 'water']), ['mode' => 'inclusive'])->assertForbidden();
        }
        $this->assertSame(0, UnitUtility::count());
    }

    public function test_metered_needs_a_rate_and_fixed_needs_an_amount(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->put(route('utilities.configure', [$this->unit, 'water']), ['mode' => 'metered'])->assertSessionHasErrors('rate');
        $this->put(route('utilities.configure', [$this->unit, 'water']), ['mode' => 'fixed'])->assertSessionHasErrors('fixed');
        $this->put(route('utilities.configure', [$this->unit, 'water']), ['mode' => 'metered', 'rate' => '1.23456'])->assertSessionHasErrors('rate');
    }

    public function test_percent_shares_must_total_exactly_100_percent(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $bedUnit = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::PerBed, 'capacity' => 3]);
        $a = $this->agreement($bedUnit);
        $b = $this->agreement($bedUnit);
        $url = route('utilities.configure', [$bedUnit, 'water']);
        $payload = fn (int $x, int $y) => ['mode' => 'shared', 'split_method' => 'percent', 'shares' => [['agreement_id' => $a->id, 'percent_bp' => $x], ['agreement_id' => $b->id, 'percent_bp' => $y]]];

        $this->put($url, $payload(6000, 3999))->assertSessionHasErrors('shares');
        $this->assertSame(0, UtilitySplitShare::count());

        $this->put($url, $payload(6000, 4000))->assertSessionDoesntHaveErrors();
        $this->assertSame([6000, 4000], UtilitySplitShare::orderBy('id')->pluck('percent_bp')->all());

        $this->put($url, ['mode' => 'shared', 'split_method' => 'equal'])->assertSessionDoesntHaveErrors();
        $this->assertSame(0, UtilitySplitShare::count());
    }

    public function test_staff_of_the_property_enters_a_reading_and_the_amount_is_computed(): void
    {
        $this->metered();
        $this->actingAs($staff = $this->staff());

        $this->post(route('utility-bills.store'), $this->reading())->assertRedirect()->assertSessionHasNoErrors();

        $bill = UtilityBill::firstOrFail();
        $this->assertSame([1000000, 1042500, 115432, 49059, 'draft', $staff->id], [$bill->previous_reading_e3, $bill->current_reading_e3, $bill->rate_e4, $bill->amount_cents, $bill->status, $bill->entered_by]);
        $this->assertSame('2026-11-01', $bill->bill_month->toDateString());
        $this->assertTrue(ActivityLog::where('action', 'utility_bill.recorded')->exists());
    }

    public function test_the_previous_reading_comes_from_last_months_bill_and_cannot_be_overridden(): void
    {
        $this->metered();
        $this->actingAs($this->staff());
        $this->post(route('utility-bills.store'), $this->reading(['bill_month' => '2026-10', 'previous_reading' => '900', 'current_reading' => '1000']))->assertSessionHasNoErrors();

        $this->post(route('utility-bills.store'), $this->reading(['previous_reading' => '5', 'current_reading' => '1042.5']))->assertSessionHasNoErrors();

        $this->assertSame(1000000, UtilityBill::where('bill_month', '2026-11-01')->value('previous_reading_e3'));
    }

    public function test_a_reading_lower_than_the_previous_is_a_validation_error(): void
    {
        $this->metered();
        $this->actingAs($this->staff());

        $this->post(route('utility-bills.store'), $this->reading(['current_reading' => '999.999']))->assertSessionHasErrors('current_reading');
        $this->post(route('utility-bills.store'), $this->reading(['current_reading' => '12.3456']))->assertSessionHasErrors('current_reading');
        $this->assertSame(0, UtilityBill::count());
    }

    public function test_a_draft_reading_can_be_corrected_but_a_billed_one_cannot(): void
    {
        $this->metered();
        $this->actingAs($this->staff());
        $this->post(route('utility-bills.store'), $this->reading());

        $this->post(route('utility-bills.store'), $this->reading(['current_reading' => '1050']))->assertSessionHasNoErrors();
        $this->assertSame(1, UtilityBill::count());
        $this->assertSame(1050000, UtilityBill::first()->current_reading_e3);

        UtilityBill::first()->update(['status' => 'billed']);
        $this->post(route('utility-bills.store'), $this->reading(['current_reading' => '1060']))->assertSessionHasErrors('bill_month');
        $this->assertSame(1050000, UtilityBill::first()->current_reading_e3);
    }

    public function test_a_shared_master_bill_is_entered_in_pesos(): void
    {
        UnitUtility::create(['unit_id' => $this->unit->id, 'type' => 'water', 'mode' => 'shared']);
        $this->actingAs($this->staff());

        $this->post(route('utility-bills.store'), ['unit_id' => $this->unit->id, 'type' => 'water', 'bill_month' => '2026-11', 'amount' => '1,000.50'])->assertSessionHasNoErrors();

        $this->assertSame(100050, UtilityBill::firstOrFail()->amount_cents);
    }

    public function test_a_unit_that_is_not_metered_or_shared_takes_no_readings(): void
    {
        UnitUtility::create(['unit_id' => $this->unit->id, 'type' => 'water', 'mode' => 'fixed', 'fixed_cents' => 100]);
        $this->actingAs($this->staff());

        $this->post(route('utility-bills.store'), ['unit_id' => $this->unit->id, 'type' => 'water', 'bill_month' => '2026-11', 'amount' => '10'])->assertSessionHasErrors('type');
        $this->post(route('utility-bills.store'), ['unit_id' => $this->unit->id, 'type' => 'electricity', 'bill_month' => '2026-11', 'amount' => '10'])->assertSessionHasErrors('type');
    }

    public function test_manual_shares_must_add_up_to_the_bill(): void
    {
        $bedUnit = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::PerBed, 'capacity' => 2]);
        $a = $this->agreement($bedUnit);
        $b = $this->agreement($bedUnit);
        $config = UnitUtility::create(['unit_id' => $bedUnit->id, 'type' => 'water', 'mode' => 'shared', 'split_method' => 'manual']);
        UtilitySplitShare::create(['unit_utility_id' => $config->id, 'rental_agreement_id' => $a->id, 'manual_cents' => 70000]);
        UtilitySplitShare::create(['unit_utility_id' => $config->id, 'rental_agreement_id' => $b->id, 'manual_cents' => 30000]);
        $this->actingAs($this->staff());
        $payload = fn (string $amount) => ['unit_id' => $bedUnit->id, 'type' => 'water', 'bill_month' => '2026-11', 'amount' => $amount];

        $this->post(route('utility-bills.store'), $payload('1000.01'))->assertSessionHasErrors('amount');
        $this->post(route('utility-bills.store'), $payload('1000'))->assertSessionHasNoErrors();
    }

    public function test_staff_outside_the_property_and_the_accountant_cannot_enter_readings(): void
    {
        $this->metered();

        foreach ([$this->staff(false), User::factory()->accountant()->create()] as $user) {
            $this->actingAs($user)->post(route('utility-bills.store'), $this->reading())->assertForbidden();
        }
        $this->assertSame(0, UtilityBill::count());
    }

    public function test_evidence_is_stored_privately_and_only_authorised_users_download_it(): void
    {
        Storage::fake('local');
        $this->metered();
        $this->actingAs($this->staff());

        $this->post(route('utility-bills.store'), $this->reading(['evidence' => UploadedFile::fake()->image('meter.jpg')]))->assertSessionHasNoErrors();

        $bill = UtilityBill::firstOrFail();
        $this->assertStringStartsWith('utility-evidence/', $bill->evidence_path);
        Storage::disk('local')->assertExists($bill->evidence_path);
        $this->get(route('utility-bills.evidence', $bill))->assertOk();

        $this->actingAs($this->staff(false))->get(route('utility-bills.evidence', $bill))->assertForbidden();
        $this->actingAs(User::factory()->accountant()->create())->get(route('utility-bills.evidence', $bill))->assertOk();
    }

    public function test_evidence_must_be_an_image_or_pdf_up_to_5mb(): void
    {
        Storage::fake('local');
        $this->metered();
        $this->actingAs($this->staff());

        $this->post(route('utility-bills.store'), $this->reading(['evidence' => UploadedFile::fake()->create('x.exe', 10)]))->assertSessionHasErrors('evidence');
        $this->post(route('utility-bills.store'), $this->reading(['evidence' => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')]))->assertSessionHasErrors('evidence');
        $this->post(route('utility-bills.store'), $this->reading(['evidence' => UploadedFile::fake()->create('ok.pdf', 100, 'application/pdf')]))->assertSessionHasNoErrors();
    }

    public function test_owner_excludes_a_utility_for_an_agreement_and_month_and_can_undo_it(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->post(route('agreements.utility-exclusions.store', $this->agreement), ['type' => 'electricity', 'bill_month' => '2026-11', 'reason' => 'Meter broken'])->assertRedirect();

        $exclusion = UtilityExclusion::firstOrFail();
        $this->assertSame('2026-11-01', $exclusion->bill_month->toDateString());
        $this->assertTrue(ActivityLog::where('action', 'utility_exclusion.created')->exists());

        $this->post(route('agreements.utility-exclusions.store', $this->agreement), ['type' => 'electricity', 'bill_month' => '2026-11', 'reason' => 'again'])->assertSessionHasErrors('type');

        $this->delete(route('utility-exclusions.destroy', $exclusion))->assertRedirect();
        $this->assertSame(0, UtilityExclusion::count());
    }

    public function test_exclusions_are_owner_only_and_need_a_reason(): void
    {
        $payload = ['type' => 'water', 'bill_month' => '2026-11', 'reason' => 'x'];
        foreach ([$this->staff(), User::factory()->accountant()->create()] as $user) {
            $this->actingAs($user)->post(route('agreements.utility-exclusions.store', $this->agreement), $payload)->assertForbidden();
        }

        $this->actingAs(User::factory()->owner()->create())
            ->post(route('agreements.utility-exclusions.store', $this->agreement), ['reason' => ''] + $payload)->assertSessionHasErrors('reason');
        $this->assertSame(0, UtilityExclusion::count());
    }

    public function test_the_page_lists_the_property_units_with_the_month_readings(): void
    {
        $this->metered();
        $this->unit->update(['building' => 'Creekside House', 'floor' => '2nd floor', 'capacity' => 4]);
        $this->actingAs($staff = $this->staff());
        $this->post(route('utility-bills.store'), $this->reading());
        $other = Unit::factory()->for(Property::factory()->create())->create();
        UnitUtility::create(['unit_id' => $other->id, 'type' => 'water', 'mode' => 'shared']);

        $this->get(route('utilities.index', ['month' => '2026-11']))->assertInertia(fn (Assert $page) => $page
            ->component('utilities/index')
            ->where('month', '2026-11')
            ->where('canConfigure', false)
            ->has('units', 1)
            ->where('units.0.id', $this->unit->id)
            ->where('units.0.building', 'Creekside House')
            ->where('units.0.floor', '2nd floor')
            ->where('units.0.capacity', 4)
            ->where('units.0.utilities.electricity.mode', 'metered')
            ->where('units.0.utilities.electricity.bill.amount', '₱490.59'));

        $this->actingAs(User::factory()->accountant()->create())->get(route('utilities.index'))->assertOk();
    }

    public function test_manual_shares_are_checked_against_the_agreements_the_bill_is_split_across(): void
    {
        $bedUnit = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::PerBed, 'capacity' => 3]);
        // Cycle runs the 15th to the 14th, so a tenant who left on Nov 10 has no day in the November cycle.
        $a = $this->agreement($bedUnit);
        $a->update(['cycle_start_day' => 15]);
        $b = $this->agreement($bedUnit);
        $b->update(['start_date' => '2026-11-16']);
        $gone = $this->agreement($bedUnit);
        $gone->update(['cycle_start_day' => 15, 'end_date' => '2026-11-10']);
        $config = UnitUtility::create(['unit_id' => $bedUnit->id, 'type' => 'water', 'mode' => 'shared', 'split_method' => 'manual']);
        foreach ([[$a, 70000], [$b, 30000], [$gone, 5000]] as [$agreement, $cents]) {
            UtilitySplitShare::create(['unit_utility_id' => $config->id, 'rental_agreement_id' => $agreement->id, 'manual_cents' => $cents]);
        }
        $this->actingAs($this->staff());

        $this->post(route('utility-bills.store'), ['unit_id' => $bedUnit->id, 'type' => 'water', 'bill_month' => '2026-11', 'amount' => '1000'])->assertSessionHasNoErrors();

        $this->assertSame(
            [$a->id => 70000, $b->id => 30000],
            app(UtilityAllocationService::class)->allocate(
                UtilityBill::firstOrFail(),
                app(UtilityAllocationService::class)->eligibleAgreements($bedUnit, 2026, 11),
                app(UtilityAllocationService::class)->eligibleAgreements($bedUnit, 2026, 11)
                    ->mapWithKeys(fn ($x) => [$x->id => app(BillingCycleResolver::class)->cycleFor($x, 2026, 11)])->all(),
            ),
        );
    }

    public function test_a_share_row_missing_its_amount_is_a_validation_error_not_a_500(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $bedUnit = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::PerBed, 'capacity' => 2]);
        $a = $this->agreement($bedUnit);
        $url = route('utilities.configure', [$bedUnit, 'water']);

        $this->put($url, ['mode' => 'shared', 'split_method' => 'percent', 'shares' => [['agreement_id' => $a->id]]])->assertSessionHasErrors('shares.0.percent_bp');
        $this->put($url, ['mode' => 'shared', 'split_method' => 'manual', 'shares' => [['agreement_id' => $a->id]]])->assertSessionHasErrors('shares.0.manual_cents');
        $this->assertSame(0, UnitUtility::count());
    }

    public function test_replacing_evidence_deletes_the_old_file(): void
    {
        Storage::fake('local');
        $this->metered();
        $this->actingAs($this->staff());
        $this->post(route('utility-bills.store'), $this->reading(['evidence' => UploadedFile::fake()->image('a.jpg')]));
        $first = UtilityBill::firstOrFail()->evidence_path;

        $this->post(route('utility-bills.store'), $this->reading(['evidence' => UploadedFile::fake()->image('b.jpg')]))->assertSessionHasNoErrors();

        $second = UtilityBill::firstOrFail()->evidence_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
    }

    public function test_a_concurrent_duplicate_is_a_validation_error_and_leaves_no_orphan_file(): void
    {
        Storage::fake('local');
        $this->metered();
        $this->actingAs($this->staff());
        // Simulate another request winning the race between our lookup and our insert.
        UtilityBill::creating(function (UtilityBill $b) {
            UtilityBill::withoutEvents(fn () => DB::table('utility_bills')->insert([
                'unit_id' => $b->unit_id, 'type' => $b->type, 'bill_month' => '2026-11-01', 'base_charge_cents' => 0, 'amount_cents' => 1,
                'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
            ]));
        });

        $this->post(route('utility-bills.store'), $this->reading(['evidence' => UploadedFile::fake()->image('a.jpg')]))->assertSessionHasErrors('bill_month');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
