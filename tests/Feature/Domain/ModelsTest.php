<?php

namespace Tests\Feature\Domain;

use App\Enums\UnitStatus;
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
use App\Support\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_no_is_generated_from_id(): void
    {
        $account = BillingAccount::factory()->create();

        $this->assertSame('JT-'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT), $account->account_no);
        $this->assertSame('JT-'.str_pad((string) $account->id, 6, '0', STR_PAD_LEFT), $account->fresh()->account_no);
    }

    public function test_rent_on_uses_latest_rate_effective_on_or_before_date(): void
    {
        $agreement = RentalAgreement::factory()->create();
        RentRate::factory()->for($agreement)->create(['effective_from' => '2026-01-01', 'rent_cents' => 250000]);
        RentRate::factory()->for($agreement)->create(['effective_from' => '2026-03-01', 'rent_cents' => 270000]);

        $this->assertSame(250000, $agreement->rentOn('2026-01-15'));
        $this->assertSame(270000, $agreement->rentOn('2026-03-01'));
        $this->assertSame(270000, $agreement->rentOn(now()->setDate(2026, 6, 1)));
        $this->assertNull($agreement->rentOn('2025-12-31'));
    }

    public function test_is_whole_unit_depends_on_bed(): void
    {
        $this->assertTrue(RentalAgreement::factory()->create()->isWholeUnit());

        $bed = Bed::factory()->create();
        $agreement = RentalAgreement::factory()->create(['unit_id' => $bed->unit_id, 'bed_id' => $bed->id]);
        $this->assertFalse($agreement->isWholeUnit());
    }

    public function test_activity_log_is_append_only(): void
    {
        $log = Audit::log('property.created', Property::factory()->create(), ['name' => 'X']);

        try {
            $log->update(['action' => 'changed']);
            $this->fail('update should throw');
        } catch (LogicException) {
            $this->assertSame('property.created', $log->fresh()->action);
        }

        try {
            $log->delete();
            $this->fail('delete should throw');
        } catch (LogicException) {
            $this->assertSame(1, ActivityLog::count());
        }
    }

    public function test_audit_log_records_user_subject_and_properties(): void
    {
        $user = User::factory()->owner()->create();
        $property = Property::factory()->create();
        $this->actingAs($user);

        $log = Audit::log('rent.changed', $property, ['old' => 1, 'new' => 2]);

        $this->assertSame($user->id, $log->user_id);
        $this->assertTrue($log->subject->is($property));
        $this->assertSame(['old' => 1, 'new' => 2], $log->fresh()->properties);
        $this->assertNotNull($log->fresh()->created_at);
    }

    public function test_can_access_property_by_role_and_assignment(): void
    {
        $property = Property::factory()->create();
        $other = Property::factory()->create();
        $owner = User::factory()->owner()->create();
        $accountant = User::factory()->accountant()->create();
        $staff = User::factory()->create();
        $staff->properties()->attach($property);
        $unassigned = User::factory()->create();

        $this->assertTrue($owner->canAccessProperty($property));
        $this->assertTrue($accountant->canAccessProperty($other->id));
        $this->assertTrue($staff->canAccessProperty($property));
        $this->assertTrue($staff->canAccessProperty($property->id));
        $this->assertFalse($staff->canAccessProperty($other));
        $this->assertFalse($unassigned->canAccessProperty($property));
        $this->assertTrue($property->users->contains($staff));
    }

    public function test_unit_display_status(): void
    {
        $unit = Unit::factory()->create(['capacity' => 2]);
        $this->assertSame('vacant', $unit->displayStatus('2026-05-01'));

        $this->occupy($unit, '2026-01-01');
        $this->assertSame('available', $unit->displayStatus('2026-05-01'));
        $this->assertSame('vacant', $unit->displayStatus('2025-12-31'));

        $this->occupy($unit, '2026-01-01');
        $this->assertSame('full', $unit->displayStatus('2026-05-01'));

        $unit->update(['status' => UnitStatus::Maintenance]);
        $this->assertSame('maintenance', $unit->displayStatus('2026-05-01'));
    }

    public function test_move_out_date_is_inclusive(): void
    {
        $unit = Unit::factory()->create(['capacity' => 2]);
        $this->occupy($unit, '2026-01-01', '2026-02-01');

        $this->assertSame(1, $unit->occupiedCountOn('2026-02-01'));
        $this->assertSame(0, $unit->occupiedCountOn('2026-02-02'));
    }

    public function test_relations_both_directions(): void
    {
        $agreement = RentalAgreement::factory()->create();
        $rate = RentRate::factory()->for($agreement)->create();
        $account = $agreement->billingAccount;

        $this->assertTrue($agreement->rentRates->contains($rate));
        $this->assertTrue($account->agreements->contains($agreement));
        $this->assertInstanceOf(Tenant::class, $account->payer);
        $this->assertTrue($agreement->unit->agreements->contains($agreement));
        $this->assertTrue($account->property->billingAccounts->contains($account));
        $this->assertTrue($account->payer->property->tenants->contains($account->payer));
        $this->assertTrue($agreement->unit->property->units->contains($agreement->unit));
    }

    public function test_rent_rate_is_append_only(): void
    {
        $rate = RentRate::factory()->create();

        try {
            $rate->update(['rent_cents' => 1]);
            $this->fail('update should throw');
        } catch (LogicException) {
            $this->assertNotSame(1, $rate->fresh()->rent_cents);
        }

        try {
            $rate->delete();
            $this->fail('delete should throw');
        } catch (LogicException) {
            $this->assertSame(1, RentRate::count());
        }
    }

    public function test_account_no_is_not_mass_assignable(): void
    {
        $this->assertNotContains('account_no', (new BillingAccount)->getFillable());
        $account = BillingAccount::factory()->create();

        $this->assertStringStartsWith('JT-', $account->account_no);
    }

    public function test_check_constraints_reject_bad_rows(): void
    {
        $this->assertCheckFails(fn () => Unit::factory()->create(['capacity' => 0]));
        $this->assertCheckFails(fn () => RentalAgreement::factory()->create(['due_day' => 32]));
        $this->assertCheckFails(fn () => RentalAgreement::factory()->create(['cycle_start_day' => 29]));
        $this->assertCheckFails(fn () => RentalAgreement::factory()->create(['issue_lead_days' => 32]));
        $this->assertCheckFails(fn () => RentalAgreement::factory()->create(['end_date' => '2025-12-31']));
        $this->assertCheckFails(fn () => RentRate::factory()->create(['rent_cents' => 0]));
        $this->assertCheckFails(fn () => SecurityDeposit::factory()->create(['amount_cents' => 0]));
        $this->assertCheckFails(fn () => Occupancy::factory()->create(['move_out_date' => '2025-12-31']));
    }

    private function assertCheckFails(callable $insert): void
    {
        try {
            $insert();
            $this->fail('CHECK constraint did not reject the row');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function occupy(Unit $unit, string $in, ?string $out = null): void
    {
        Occupancy::factory()->create([
            'unit_id' => $unit->id,
            'tenant_id' => Tenant::factory()->create(['property_id' => $unit->property_id])->id,
            'move_in_date' => $in,
            'move_out_date' => $out,
        ]);
    }
}
