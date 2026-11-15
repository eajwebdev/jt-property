<?php

namespace Tests\Feature\Billing;

use App\Enums\RentMode;
use App\Models\ActivityLog;
use App\Models\ChargeCategory;
use App\Models\Property;
use App\Models\RecurringCharge;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\RecurringChargeService;
use App\Services\TenancyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferCarriesRecurringChargesTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $this->actingAs(User::factory()->owner()->create());
    }

    private function unit(string $code): Unit
    {
        return Unit::factory()->for($this->property)->create(['code' => $code, 'capacity' => 2, 'rent_mode' => RentMode::Whole]);
    }

    private function charge($agreement, array $overrides = []): RecurringCharge
    {
        return RecurringCharge::create(array_merge([
            'rental_agreement_id' => $agreement->id,
            'charge_category_id' => ChargeCategory::where('name', 'Rice cooker')->value('id'),
            'label' => 'Rice cooker',
            'amount_cents' => 20000,
            'start_date' => '2026-09-01',
        ], $overrides));
    }

    private function start(Tenant $tenant, Unit $unit)
    {
        return app(TenancyService::class)->startAgreement($tenant, $unit, null, 500000, 5, Carbon::parse('2026-09-01'));
    }

    public function test_active_charges_move_to_the_new_agreement_from_the_transfer_date(): void
    {
        $old = $this->start(Tenant::factory()->for($this->property)->create(), $this->unit('A1'));
        $open = $this->charge($old);
        $dated = $this->charge($old, ['label' => 'Fan', 'amount_cents' => 5000, 'end_date' => '2027-03-31']);
        $endedBefore = $this->charge($old, ['label' => 'Old', 'end_date' => '2026-10-31']);
        $inactive = $this->charge($old, ['label' => 'Paused', 'active' => false]);

        $new = app(TenancyService::class)->transfer($old, $this->unit('A2'), null, Carbon::parse('2026-11-16'));

        $this->assertSame('2026-11-15', $open->fresh()->end_date->toDateString());
        $this->assertSame('2026-11-15', $dated->fresh()->end_date->toDateString());
        $this->assertSame('2026-10-31', $endedBefore->fresh()->end_date->toDateString());
        $this->assertNull($inactive->fresh()->end_date);

        $copies = RecurringCharge::where('rental_agreement_id', $new->id)->orderBy('id')->get();
        $this->assertSame(['Rice cooker', 'Fan'], $copies->pluck('label')->all());
        $this->assertSame([20000, 5000], $copies->pluck('amount_cents')->all());
        $this->assertSame(['2026-11-16', '2026-11-16'], $copies->map(fn ($c) => $c->start_date->toDateString())->all());
        $this->assertSame([null, '2027-03-31'], $copies->map(fn ($c) => $c->end_date?->toDateString())->all());
        $this->assertSame($open->charge_category_id, $copies[0]->charge_category_id);
    }

    public function test_a_charge_that_has_not_started_yet_moves_to_the_new_agreement(): void
    {
        $old = $this->start(Tenant::factory()->for($this->property)->create(), $this->unit('A1'));
        $future = $this->charge($old, ['start_date' => '2026-12-01']);

        $new = app(TenancyService::class)->transfer($old, $this->unit('A2'), null, Carbon::parse('2026-11-16'));

        $this->assertSame($new->id, $future->fresh()->rental_agreement_id);
        $this->assertSame(1, RecurringCharge::count());
    }

    public function test_a_failed_transfer_leaves_recurring_charges_untouched(): void
    {
        $old = $this->start(Tenant::factory()->for($this->property)->create(), $this->unit('A1'));
        $charge = $this->charge($old);
        $taken = $this->unit('A2');
        $this->start(Tenant::factory()->for($this->property)->create(), $taken);

        try {
            app(TenancyService::class)->transfer($old, $taken, null, Carbon::parse('2026-11-16'));
            $this->fail('Transfer into an occupied room should fail.');
        } catch (ValidationException) {
        }

        $this->assertNull($charge->fresh()->end_date);
        $this->assertSame($old->id, $charge->fresh()->rental_agreement_id);
        $this->assertSame(1, RecurringCharge::count());
    }

    public function test_in_the_transfer_cycle_the_add_on_is_billed_only_on_the_new_agreement(): void
    {
        $old = $this->start(Tenant::factory()->for($this->property)->create(), $this->unit('A1'));
        $this->charge($old);

        $new = app(TenancyService::class)->transfer($old, $this->unit('A2'), null, Carbon::parse('2026-11-16'));

        $resolver = app(BillingCycleResolver::class);
        $service = app(RecurringChargeService::class);
        $oldLines = $service->linesFor($old->fresh(), $resolver->cycleFor($old->fresh(), 2026, 11));
        $newLines = $service->linesFor($new, $resolver->cycleFor($new, 2026, 11));

        $this->assertCount(0, $oldLines);
        $this->assertSame([20000], $newLines->pluck('amount_cents')->all());
        // Earlier cycles of the old agreement still carry it once.
        $this->assertCount(1, $service->linesFor($old->fresh(), $resolver->cycleFor($old->fresh(), 2026, 10)));
    }

    public function test_audit_separates_moved_and_copied_charges(): void
    {
        $old = $this->start(Tenant::factory()->for($this->property)->create(), $this->unit('A1'));
        $copied = $this->charge($old);
        $moved = $this->charge($old, ['label' => 'Later', 'start_date' => '2026-12-01']);

        $new = app(TenancyService::class)->transfer($old, $this->unit('A2'), null, Carbon::parse('2026-11-16'));

        $new_ = ActivityLog::where('action', 'agreement.transferred')->firstOrFail()->properties['new'];
        $this->assertSame([$moved->id], $new_['moved_recurring_charge_ids']);
        $this->assertCount(1, $new_['copied_recurring_charge_ids']);
        $this->assertSame($new->id, $new_['recurring_charges_agreement_id']);
        $this->assertNotContains($copied->id, $new_['copied_recurring_charge_ids']);
    }
}
