<?php

namespace Tests\Feature\Billing;

use App\Enums\RentMode;
use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\UtilityBill;
use App\Models\UtilityExclusion;
use App\Models\UtilitySplitShare;
use App\Services\Billing\BillingCycle;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\UtilityAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UtilityAllocationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private UtilityAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $this->service = app(UtilityAllocationService::class);
    }

    private function unit(RentMode $mode = RentMode::PerBed): Unit
    {
        return Unit::factory()->for($this->property)->create(['rent_mode' => $mode, 'capacity' => 4]);
    }

    private function agreement(Unit $unit, string $start = '2026-01-01', ?string $end = null): RentalAgreement
    {
        return RentalAgreement::factory()
            ->for(BillingAccount::factory()->for($this->property)->create(), 'billingAccount')
            ->for($unit)
            ->create(['start_date' => $start, 'end_date' => $end]);
    }

    private function config(Unit $unit, string $type, string $mode, array $extra = []): UnitUtility
    {
        return UnitUtility::create(array_merge(['unit_id' => $unit->id, 'type' => $type, 'mode' => $mode], $extra));
    }

    private function bill(Unit $unit, string $type, array $extra = []): UtilityBill
    {
        return UtilityBill::create(array_merge([
            'unit_id' => $unit->id, 'type' => $type, 'bill_month' => '2026-11-01',
            'base_charge_cents' => 0, 'amount_cents' => 0, 'status' => 'draft',
        ], $extra));
    }

    /** @return array<int, BillingCycle> */
    private function cycles(Collection $agreements): array
    {
        $resolver = app(BillingCycleResolver::class);

        return $agreements->mapWithKeys(fn ($a) => [$a->id => $resolver->cycleFor($a, 2026, 11)])->all();
    }

    public function test_metered_amount_rounds_half_up_to_the_centavo(): void
    {
        $bill = $this->bill($this->unit(), 'electricity', ['previous_reading_e3' => 1000000, 'current_reading_e3' => 1042500, 'rate_e4' => 115432]);

        $this->assertSame(49059, $this->service->amountFor($bill));
    }

    public function test_metered_amount_adds_the_base_charge_after_rounding(): void
    {
        $bill = $this->bill($this->unit(), 'water', ['previous_reading_e3' => 0, 'current_reading_e3' => 42500, 'rate_e4' => 115432, 'base_charge_cents' => 15000]);

        $this->assertSame(64059, $this->service->amountFor($bill));
    }

    public function test_shared_amount_is_the_entered_amount(): void
    {
        $this->assertSame(100000, $this->service->amountFor($this->bill($this->unit(), 'water', ['amount_cents' => 100000])));
    }

    public function test_equal_split_of_1000_pesos_three_ways_is_33334_33333_33333(): void
    {
        $unit = $this->unit();
        $agreements = collect([$this->agreement($unit), $this->agreement($unit), $this->agreement($unit)]);
        $this->config($unit, 'water', 'shared', ['split_method' => 'equal']);
        $bill = $this->bill($unit, 'water', ['amount_cents' => 100000]);

        $result = $this->service->allocate($bill, $agreements, $this->cycles($agreements));

        $this->assertSame([33334, 33333, 33333], array_values($result));
        $this->assertSame($agreements->pluck('id')->all(), array_keys($result));
    }

    public function test_days_split_where_one_tenant_covered_half_the_month_sums_exactly(): void
    {
        $unit = $this->unit();
        $agreements = collect([$this->agreement($unit), $this->agreement($unit), $this->agreement($unit, '2026-11-16')]);
        $this->config($unit, 'electricity', 'shared', ['split_method' => 'days']);
        $bill = $this->bill($unit, 'electricity', ['amount_cents' => 100001]);

        $result = $this->service->allocate($bill, $agreements, $this->cycles($agreements));

        $this->assertSame([40001, 40000, 20000], array_values($result));
        $this->assertSame(100001, array_sum($result));
    }

    public function test_percent_split_50_30_20_of_999_99_sums_exactly(): void
    {
        $unit = $this->unit();
        $agreements = collect([$this->agreement($unit), $this->agreement($unit), $this->agreement($unit)]);
        $unitUtility = $this->config($unit, 'electricity', 'shared', ['split_method' => 'percent']);
        foreach ($agreements as $i => $a) {
            UtilitySplitShare::create(['unit_utility_id' => $unitUtility->id, 'rental_agreement_id' => $a->id, 'percent_bp' => [5000, 3000, 2000][$i]]);
        }
        $bill = $this->bill($unit, 'electricity', ['amount_cents' => 99999]);

        $result = $this->service->allocate($bill, $agreements, $this->cycles($agreements));

        $this->assertSame([49999, 30000, 20000], array_values($result));
        $this->assertSame(99999, array_sum($result));
    }

    public function test_manual_shares_are_used_as_entered_when_they_add_up_to_the_bill(): void
    {
        $unit = $this->unit();
        $agreements = collect([$this->agreement($unit), $this->agreement($unit)]);
        $unitUtility = $this->config($unit, 'water', 'shared', ['split_method' => 'manual']);
        foreach ($agreements as $i => $a) {
            UtilitySplitShare::create(['unit_utility_id' => $unitUtility->id, 'rental_agreement_id' => $a->id, 'manual_cents' => [70000, 30000][$i]]);
        }

        $ok = $this->service->linesFor($agreements[0], $this->cycles($agreements)[$agreements[0]->id]);
        $this->assertSame(['water'], $ok['needs_input']);

        $this->bill($unit, 'water', ['amount_cents' => 100000]);
        $ok = $this->service->linesFor($agreements[0], $this->cycles($agreements)[$agreements[0]->id]);
        $this->assertSame([70000], $ok['lines']->pluck('amount_cents')->all());

        UtilityBill::first()->update(['amount_cents' => 100100]);
        $bad = $this->service->linesFor($agreements[0], $this->cycles($agreements)[$agreements[0]->id]);
        $this->assertSame(['water'], $bad['needs_input']);
        $this->assertCount(0, $bad['lines']);
    }

    public function test_agreements_with_no_days_in_the_period_get_nothing_and_an_empty_period_is_flagged_not_thrown(): void
    {
        $unit = $this->unit();
        $gone = $this->agreement($unit, '2026-01-01', '2026-10-31');
        $this->config($unit, 'water', 'shared', ['split_method' => 'equal']);
        $bill = $this->bill($unit, 'water', ['amount_cents' => 100000]);

        $this->assertSame([], $this->service->allocate($bill, collect([$gone]), $this->cycles(collect([$gone]))));
        $this->assertSame([], $this->service->allocate($bill, collect(), []));

        $result = $this->service->linesFor($gone, new BillingCycle(
            BillingCycle::day('2026-11-01'), BillingCycle::day('2026-11-30'), BillingCycle::day('2026-10-29'), BillingCycle::day('2026-11-05'),
        ));
        $this->assertSame(['water'], $result['no_occupants']);
        $this->assertCount(0, $result['lines']);
    }

    public function test_tied_remainders_go_to_the_lowest_agreement_id(): void
    {
        $unit = $this->unit();
        $agreements = collect([$this->agreement($unit), $this->agreement($unit), $this->agreement($unit)]);
        $this->config($unit, 'water', 'shared', ['split_method' => 'equal']);

        $result = $this->service->allocate($this->bill($unit, 'water', ['amount_cents' => 100001]), $agreements->reverse()->values(), $this->cycles($agreements));

        $this->assertSame([$agreements[0]->id => 33334, $agreements[1]->id => 33334, $agreements[2]->id => 33333], $result);
    }

    private function lines(RentalAgreement $a): array
    {
        return $this->service->linesFor($a, app(BillingCycleResolver::class)->cycleFor($a, 2026, 11));
    }

    public function test_inclusive_utilities_produce_no_line_and_no_flag(): void
    {
        $unit = $this->unit(RentMode::Whole);
        $a = $this->agreement($unit);
        $this->config($unit, 'water', 'inclusive');

        $result = $this->lines($a);

        $this->assertCount(0, $result['lines']);
        $this->assertSame([], $result['needs_input']);
    }

    public function test_an_unconfigured_utility_is_treated_as_inclusive(): void
    {
        $a = $this->agreement($this->unit(RentMode::Whole));

        $result = $this->lines($a);

        $this->assertCount(0, $result['lines']);
        $this->assertSame([], $result['needs_input']);
    }

    public function test_fixed_utility_bills_the_fixed_amount_per_agreement(): void
    {
        $unit = $this->unit();
        $a = $this->agreement($unit);
        $this->config($unit, 'water', 'fixed', ['fixed_cents' => 25000]);

        $result = $this->lines($a);

        $this->assertSame([25000], $result['lines']->pluck('amount_cents')->all());
        $this->assertSame('Water – fixed charge', $result['lines']->first()['label']);
    }

    public function test_metered_with_a_bill_gives_an_allocated_line_with_usage_text(): void
    {
        $unit = $this->unit(RentMode::Whole);
        $a = $this->agreement($unit);
        $this->config($unit, 'electricity', 'metered', ['rate_e4' => 115432]);
        $bill = $this->bill($unit, 'electricity', ['previous_reading_e3' => 1000000, 'current_reading_e3' => 1042500, 'rate_e4' => 115432, 'amount_cents' => 49059]);

        $result = $this->lines($a);

        $line = $result['lines']->first();
        $this->assertSame(49059, $line['amount_cents']);
        $this->assertSame('Electricity – 42.500 kWh × ₱11.5432', $line['label']);
        $this->assertTrue($line['source']->is($bill));
        $this->assertSame([], $result['needs_input']);
    }

    public function test_a_missing_bill_asks_for_input_and_is_never_billed_as_zero(): void
    {
        $unit = $this->unit(RentMode::Whole);
        $a = $this->agreement($unit);
        $this->config($unit, 'electricity', 'metered');
        $this->config($unit, 'water', 'shared', ['split_method' => 'equal']);
        $this->bill($unit, 'water', ['bill_month' => '2026-10-01', 'amount_cents' => 5000]);

        $result = $this->lines($a);

        $this->assertCount(0, $result['lines']);
        $this->assertEqualsCanonicalizing(['electricity', 'water'], $result['needs_input']);
    }

    public function test_an_exclusion_suppresses_both_the_line_and_the_flag(): void
    {
        $unit = $this->unit(RentMode::Whole);
        $a = $this->agreement($unit);
        $this->config($unit, 'electricity', 'metered');
        UtilityExclusion::create(['rental_agreement_id' => $a->id, 'type' => 'electricity', 'bill_month' => '2026-11-01', 'reason' => 'Meter broken']);

        $result = $this->lines($a);

        $this->assertCount(0, $result['lines']);
        $this->assertSame([], $result['needs_input']);
    }

    public function test_an_exclusion_for_one_month_does_not_cover_another(): void
    {
        $unit = $this->unit(RentMode::Whole);
        $a = $this->agreement($unit);
        $this->config($unit, 'electricity', 'metered');
        UtilityExclusion::create(['rental_agreement_id' => $a->id, 'type' => 'electricity', 'bill_month' => '2026-10-01', 'reason' => 'Meter broken']);

        $this->assertSame(['electricity'], $this->lines($a)['needs_input']);
    }

    public function test_a_shared_bill_is_split_across_every_agreement_of_the_unit_on_linesfor(): void
    {
        $unit = $this->unit();
        $a = $this->agreement($unit);
        $b = $this->agreement($unit);
        $c = $this->agreement($unit);
        $this->config($unit, 'water', 'shared', ['split_method' => 'equal']);
        $this->bill($unit, 'water', ['amount_cents' => 100000]);

        $amounts = collect([$a, $b, $c])->map(fn ($x) => $this->lines($x)['lines']->first()['amount_cents'])->all();

        $this->assertSame([33334, 33333, 33333], $amounts);
        $this->assertSame('Water – shared bill ₱1,000.00 (your share)', $this->lines($a)['lines']->first()['label']);
    }
}
