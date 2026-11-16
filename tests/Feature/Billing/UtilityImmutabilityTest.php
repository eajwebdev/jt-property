<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Unit;
use App\Models\UtilityAllocation;
use App\Models\UtilityBill;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class UtilityImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private UtilityBill $bill;

    private RentalAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();

        $property = Property::factory()->create();
        $unit = Unit::factory()->for($property)->create();
        $this->agreement = RentalAgreement::factory()->for(BillingAccount::factory()->for($property)->create(), 'billingAccount')->for($unit)->create();
        $this->bill = UtilityBill::create([
            'unit_id' => $unit->id, 'type' => 'water', 'bill_month' => '2026-11-01',
            'base_charge_cents' => 0, 'amount_cents' => 1000, 'status' => 'draft',
        ]);
    }

    public function test_a_draft_bill_can_be_edited_and_then_billed(): void
    {
        $this->bill->update(['amount_cents' => 2000]);
        $this->bill->update(['status' => 'billed']);

        $this->assertSame('billed', $this->bill->fresh()->status);
    }

    public function test_a_billed_bill_cannot_be_updated_or_deleted(): void
    {
        $this->bill->update(['status' => 'billed']);

        try {
            $this->bill->update(['amount_cents' => 5]);
            $this->fail('Update should throw.');
        } catch (LogicException) {
        }
        $this->assertSame(1000, $this->bill->fresh()->amount_cents);

        $this->expectException(LogicException::class);
        $this->bill->delete();
    }

    public function test_allocations_of_a_billed_bill_cannot_change(): void
    {
        $allocation = UtilityAllocation::create(['utility_bill_id' => $this->bill->id, 'rental_agreement_id' => $this->agreement->id, 'amount_cents' => 1000, 'basis' => 'equal']);
        $this->bill->update(['status' => 'billed']);

        foreach ([fn () => $allocation->update(['amount_cents' => 1]), fn () => $allocation->delete(), fn () => UtilityAllocation::create(['utility_bill_id' => $this->bill->id, 'rental_agreement_id' => RentalAgreement::factory()->create()->id, 'amount_cents' => 1, 'basis' => 'x'])] as $change) {
            try {
                $change();
                $this->fail('Change should throw.');
            } catch (LogicException) {
            }
        }
        $this->assertSame(1000, $allocation->fresh()->amount_cents);
        $this->assertSame(1, UtilityAllocation::count());
    }

    public function test_one_allocation_per_bill_and_agreement(): void
    {
        UtilityAllocation::create(['utility_bill_id' => $this->bill->id, 'rental_agreement_id' => $this->agreement->id, 'amount_cents' => 1, 'basis' => 'equal']);

        $this->expectException(QueryException::class);
        UtilityAllocation::create(['utility_bill_id' => $this->bill->id, 'rental_agreement_id' => $this->agreement->id, 'amount_cents' => 2, 'basis' => 'equal']);
    }
}
