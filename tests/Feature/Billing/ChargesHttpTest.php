<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingAccount;
use App\Models\ChargeCategory;
use App\Models\OneTimeCharge;
use App\Models\Property;
use App\Models\RecurringCharge;
use App\Models\RentalAgreement;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChargesHttpTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private RentalAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $account = BillingAccount::factory()->for($this->property)->create();
        $unit = Unit::factory()->for($this->property)->create();
        $this->agreement = RentalAgreement::factory()->for($account, 'billingAccount')->for($unit)->create(['start_date' => '2026-01-01']);
    }

    private function categoryId(string $name = 'Rice cooker'): int
    {
        return ChargeCategory::where('name', $name)->value('id');
    }

    private function recurring(array $overrides = []): RecurringCharge
    {
        return RecurringCharge::create(array_merge([
            'rental_agreement_id' => $this->agreement->id,
            'charge_category_id' => $this->categoryId(),
            'label' => 'Rice cooker',
            'amount_cents' => 20000,
            'start_date' => '2026-11-01',
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge(['charge_category_id' => $this->categoryId(), 'label' => 'Rice cooker', 'amount' => '200', 'start_date' => '2026-11-01'], $overrides);
    }

    public function test_owner_adds_a_recurring_charge_and_it_is_audited(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->post(route('agreements.recurring-charges.store', $this->agreement), $this->payload())->assertRedirect();

        $charge = RecurringCharge::firstOrFail();
        $this->assertSame(20000, $charge->amount_cents);
        $this->assertSame($this->agreement->id, $charge->rental_agreement_id);
        $this->assertNull($charge->end_date);
        $this->assertTrue(ActivityLog::where('action', 'recurring_charge.created')->where('subject_id', $charge->id)->exists());
    }

    public function test_staff_and_accountant_cannot_write_charges(): void
    {
        $charge = $this->recurring();

        foreach ([User::factory()->create(), User::factory()->accountant()->create()] as $user) {
            $this->actingAs($user);
            $this->post(route('agreements.recurring-charges.store', $this->agreement), $this->payload())->assertForbidden();
            $this->patch(route('recurring-charges.update', $charge), ['label' => 'x', 'amount' => '1', 'charge_category_id' => $this->categoryId()])->assertForbidden();
            $this->post(route('recurring-charges.end', $charge), ['end_date' => '2026-12-31'])->assertForbidden();
            $this->post(route('one-time-charges.store'), [])->assertForbidden();
            $this->post(route('charge-categories.store'), ['name' => 'Gym', 'kind' => 'service'])->assertForbidden();
        }

        $this->assertSame(1, RecurringCharge::count());
        $this->assertSame(0, OneTimeCharge::count());
    }

    public function test_changing_the_amount_is_allowed_and_audited(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $charge = $this->recurring();

        $this->patch(route('recurring-charges.update', $charge), ['label' => 'Rice cooker', 'amount' => '250', 'charge_category_id' => $this->categoryId()])->assertRedirect();

        $this->assertSame(25000, $charge->fresh()->amount_cents);
        $log = ActivityLog::where('action', 'recurring_charge.updated')->firstOrFail();
        $this->assertSame(20000, $log->properties['old']['amount_cents']);
        $this->assertSame(25000, $log->properties['new']['amount_cents']);
    }

    public function test_ending_a_charge_sets_the_end_date_and_keeps_the_row(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $charge = $this->recurring();

        $this->post(route('recurring-charges.end', $charge), ['end_date' => '2026-12-31'])->assertRedirect();

        $this->assertSame('2026-12-31', $charge->fresh()->end_date->toDateString());
        $this->assertTrue(ActivityLog::where('action', 'recurring_charge.ended')->exists());
        $this->post(route('recurring-charges.end', $charge), ['end_date' => '2026-10-31'])->assertSessionHasErrors('end_date');
        $this->assertSame('2026-12-31', $charge->fresh()->end_date->toDateString());
    }

    public function test_bad_amounts_are_rejected(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        foreach (['0', '-5', 'abc', '10.999'] as $bad) {
            $this->post(route('agreements.recurring-charges.store', $this->agreement), $this->payload(['amount' => $bad]))->assertSessionHasErrors('amount');
        }
        $this->assertSame(0, RecurringCharge::count());
    }

    public function test_owner_adds_a_one_time_credit_stored_on_the_first_of_the_month(): void
    {
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);

        $this->post(route('one-time-charges.store'), [
            'billing_account_id' => $this->agreement->billing_account_id,
            'charge_category_id' => $this->categoryId('Miscellaneous'),
            'label' => 'Goodwill',
            'kind' => 'credit',
            'amount' => '100',
            'bill_month' => '2026-11',
            'reason' => 'Aircon was down',
        ])->assertRedirect();

        $charge = OneTimeCharge::firstOrFail();
        $this->assertSame('2026-11-01', $charge->bill_month->toDateString());
        $this->assertSame(-10000, $charge->signed_cents);
        $this->assertSame($owner->id, $charge->created_by);
        $this->assertNull($charge->invoice_id);
        $this->assertTrue(ActivityLog::where('action', 'one_time_charge.created')->exists());
    }

    public function test_one_time_charge_agreement_must_belong_to_the_account(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $other = RentalAgreement::factory()->create(['start_date' => '2026-01-01']);

        $this->post(route('one-time-charges.store'), [
            'billing_account_id' => $this->agreement->billing_account_id,
            'rental_agreement_id' => $other->id,
            'charge_category_id' => $this->categoryId('Repair'),
            'label' => 'Door', 'kind' => 'charge', 'amount' => '50', 'bill_month' => '2026-11',
        ])->assertSessionHasErrors('rental_agreement_id');
    }

    public function test_owner_adds_a_custom_category_but_system_ones_cannot_be_renamed(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->post(route('charge-categories.store'), ['name' => 'Gym', 'kind' => 'service'])->assertRedirect();
        $gym = ChargeCategory::where('name', 'Gym')->firstOrFail();
        $this->assertFalse($gym->is_system);
        $this->post(route('charge-categories.store'), ['name' => 'Gym', 'kind' => 'service'])->assertSessionHasErrors('name');

        $this->patch(route('charge-categories.update', $gym), ['name' => 'Fitness'])->assertRedirect();
        $this->assertSame('Fitness', $gym->fresh()->name);

        $this->patch(route('charge-categories.update', $this->categoryId()), ['name' => 'Cooker'])->assertForbidden();
        $this->assertSame('Rice cooker', ChargeCategory::find($this->categoryId())->name);
    }

    public function test_index_shows_charges_and_staff_only_see_assigned_properties(): void
    {
        $this->recurring();
        $otherProperty = Property::factory()->create();
        $otherAccount = BillingAccount::factory()->for($otherProperty)->create();
        $otherAgreement = RentalAgreement::factory()->for($otherAccount, 'billingAccount')->for(Unit::factory()->for($otherProperty))->create(['start_date' => '2026-01-01']);
        $this->recurring(['rental_agreement_id' => $otherAgreement->id, 'label' => 'Hidden fan']);
        OneTimeCharge::create(['billing_account_id' => $otherAccount->id, 'charge_category_id' => $this->categoryId('Repair'), 'label' => 'Hidden repair', 'kind' => 'charge', 'amount_cents' => 100, 'bill_month' => '2026-11-01']);

        $owner = User::factory()->owner()->create();
        $this->actingAs($owner)->get(route('charges.index'))->assertInertia(fn (Assert $page) => $page
            ->component('charges/index')
            ->has('recurring', 2)->has('oneTime', 1)->has('categories', 12)->where('canManage', true));

        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $this->actingAs($staff)->get(route('charges.index'))->assertInertia(fn (Assert $page) => $page
            ->has('recurring', 1)->where('recurring.0.label', 'Rice cooker')->has('oneTime', 0)->where('canManage', false));
    }
}
