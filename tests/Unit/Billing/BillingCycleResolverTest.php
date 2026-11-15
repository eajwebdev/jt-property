<?php

namespace Tests\Unit\Billing;

use App\Models\Bed;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Unit;
use App\Services\Billing\BillingCycleResolver;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCycleResolverTest extends TestCase
{
    use RefreshDatabase;

    private BillingCycleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new BillingCycleResolver;
    }

    /** @param array<string, mixed> $attrs */
    private function agreement(array $attrs = [], int $rentCents = 1_500_000, ?string $rateFrom = null, ?Unit $unit = null): RentalAgreement
    {
        $attrs += ['start_date' => '2026-01-01'];
        if ($unit) {
            $attrs['unit_id'] = $unit->id;
        }
        $a = RentalAgreement::factory()->create($attrs);
        RentRate::factory()->create([
            'rental_agreement_id' => $a->id,
            'rent_cents' => $rentCents,
            'effective_from' => $rateFrom ?? $attrs['start_date'],
        ]);

        return $a;
    }

    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Manila')->startOfDay();
    }

    public function test_standard_cycle_nov_2026(): void
    {
        $a = $this->agreement(['cycle_start_day' => 1, 'due_day' => 5, 'issue_lead_days' => 7]);

        $c = $this->resolver->cycleFor($a, 2026, 11);

        $this->assertSame('2026-11-01', $c->periodStart->toDateString());
        $this->assertSame('2026-11-30', $c->periodEnd->toDateString());
        $this->assertSame('2026-11-05', $c->dueDate->toDateString());
        $this->assertSame('2026-10-29', $c->issueDate->toDateString());
        $this->assertSame(30, $c->periodDays());
    }

    public function test_cycle_starting_on_the_15th_runs_to_the_14th_and_is_due_next_month(): void
    {
        $a = $this->agreement(['cycle_start_day' => 15, 'due_day' => 5, 'issue_lead_days' => 7]);

        $c = $this->resolver->cycleFor($a, 2026, 11);

        $this->assertSame('2026-11-15', $c->periodStart->toDateString());
        $this->assertSame('2026-12-14', $c->periodEnd->toDateString());
        $this->assertSame('2026-12-05', $c->dueDate->toDateString());
        $this->assertSame('2026-11-28', $c->issueDate->toDateString());
        $this->assertSame(30, $c->periodDays());
    }

    public function test_due_day_is_clamped_to_month_length(): void
    {
        $this->assertSame('2026-02-28', $this->resolver->dueDate(31, $this->d('2026-02-01'))->toDateString());
        $this->assertSame('2028-02-29', $this->resolver->dueDate(31, $this->d('2028-02-01'))->toDateString());
        $this->assertSame('2026-04-30', $this->resolver->dueDate(30, $this->d('2026-04-01'))->toDateString());
        $this->assertSame('2026-02-28', $this->resolver->dueDate(30, $this->d('2026-02-01'))->toDateString());
        $this->assertSame('2026-01-31', $this->resolver->dueDate(31, $this->d('2026-01-01'))->toDateString());
    }

    public function test_due_date_rolls_to_next_month_when_due_day_is_before_cycle_start(): void
    {
        $this->assertSame('2026-12-05', $this->resolver->dueDate(5, $this->d('2026-11-15'))->toDateString());
        $this->assertSame('2026-11-15', $this->resolver->dueDate(15, $this->d('2026-11-15'))->toDateString());
        // Cycle starts Jan 31 and due day 30 has already passed in January, so it rolls to February and is clamped to Feb 28.
        $this->assertSame('2026-02-28', $this->resolver->dueDate(30, $this->d('2026-01-31'))->toDateString());
    }

    public function test_feb_cycle_of_28_days_and_leap_feb_of_29(): void
    {
        $a = $this->agreement(['due_day' => 31]);

        $feb26 = $this->resolver->cycleFor($a, 2026, 2);
        $feb28 = $this->resolver->cycleFor($a, 2028, 2);

        $this->assertSame(28, $feb26->periodDays());
        $this->assertSame('2026-02-28', $feb26->dueDate->toDateString());
        $this->assertSame(29, $feb28->periodDays());
        $this->assertSame('2028-02-29', $feb28->dueDate->toDateString());
    }

    public function test_full_cycle_charges_full_rent_with_plain_description(): void
    {
        $unit = Unit::factory()->create(['code' => 'A101']);
        $a = $this->agreement([], 1_500_000, null, $unit);

        $line = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));

        $this->assertSame(1_500_000, $line['amount_cents']);
        $this->assertSame('Monthly Rental – Room A101', $line['description']);
    }

    public function test_first_partial_cycle_without_proration_charges_full_rent_using_start_date_rate(): void
    {
        $a = $this->agreement(['start_date' => '2026-11-20', 'prorate' => false], 1_500_000);

        $c = $this->resolver->cycleFor($a, 2026, 11);
        $line = $this->resolver->rentLine($a, $c);

        $this->assertSame(11, $c->coveredDays($a));
        $this->assertSame(1_500_000, $line['amount_cents']);
        $this->assertStringNotContainsString('days', $line['description']);
    }

    public function test_first_partial_cycle_with_proration_uses_covered_days_and_shows_formula(): void
    {
        $unit = Unit::factory()->create(['code' => 'A101']);
        $a = $this->agreement(['start_date' => '2026-11-20', 'prorate' => true], 1_500_000, null, $unit);

        $line = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));

        // 1,500,000 * 11 / 30 = 550,000 exactly.
        $this->assertSame(550_000, $line['amount_cents']);
        $this->assertSame('Monthly Rental – Room A101 (11/30 days)', $line['description']);
    }

    public function test_proration_rounds_half_up_to_the_centavo(): void
    {
        // 100,001 * 15 / 30 = 50,000.5 -> 50,001
        $a = $this->agreement(['start_date' => '2026-11-16', 'prorate' => true], 100_001);

        $line = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));

        $this->assertSame(50_001, $line['amount_cents']);
    }

    public function test_agreement_ended_mid_period_inclusive_prorated_when_flag_on(): void
    {
        $unit = Unit::factory()->create(['code' => 'A101']);
        $a = $this->agreement(['end_date' => '2026-11-10', 'prorate' => true], 1_500_000, null, $unit);

        $c = $this->resolver->cycleFor($a, 2026, 11);
        $line = $this->resolver->rentLine($a, $c);

        $this->assertSame(10, $c->coveredDays($a));
        $this->assertSame(500_000, $line['amount_cents']);
        $this->assertSame('Monthly Rental – Room A101 (10/30 days)', $line['description']);
    }

    public function test_agreement_ended_mid_period_charges_full_rent_when_flag_off(): void
    {
        $a = $this->agreement(['end_date' => '2026-11-10', 'prorate' => false]);

        $line = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));

        $this->assertSame(1_500_000, $line['amount_cents']);
    }

    public function test_ineligible_agreements_have_no_cycle(): void
    {
        $future = $this->agreement(['start_date' => '2026-12-01']);
        $ended = $this->agreement(['end_date' => '2026-10-31']);

        $this->assertNull($this->resolver->cycleFor($future, 2026, 11));
        $this->assertNull($this->resolver->cycleFor($ended, 2026, 11));
    }

    public function test_period_overlap_end_date_is_inclusive_on_both_edges(): void
    {
        $startsOnLastDay = $this->agreement(['start_date' => '2026-11-30']);
        $endsOnFirstDay = $this->agreement(['start_date' => '2026-01-01', 'end_date' => '2026-11-01']);

        $this->assertNotNull($this->resolver->cycleFor($startsOnLastDay, 2026, 11));
        $this->assertSame(1, $this->resolver->cycleFor($startsOnLastDay, 2026, 11)->coveredDays($startsOnLastDay));
        $this->assertSame(1, $this->resolver->cycleFor($endsOnFirstDay, 2026, 11)->coveredDays($endsOnFirstDay));
    }

    public function test_fifteenth_cycle_for_agreement_ending_before_cycle_start_is_null(): void
    {
        $a = $this->agreement(['cycle_start_day' => 15, 'end_date' => '2026-11-14']);

        $this->assertNull($this->resolver->cycleFor($a, 2026, 11));
        $this->assertNotNull($this->resolver->cycleFor($a, 2026, 10));
    }

    public function test_mid_period_rate_change_applies_from_the_next_cycle(): void
    {
        $a = $this->agreement(['start_date' => '2026-01-01'], 1_000_000);
        RentRate::factory()->create(['rental_agreement_id' => $a->id, 'rent_cents' => 1_200_000, 'effective_from' => '2026-11-15']);

        $nov = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));
        $dec = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 12));

        $this->assertSame(1_000_000, $nov['amount_cents']);
        $this->assertSame(1_200_000, $dec['amount_cents']);
    }

    public function test_transfer_month_prorates_both_halves_regardless_of_flag(): void
    {
        $unit = Unit::factory()->create(['code' => 'A101']);
        $old = $this->agreement(['end_date' => '2026-11-15', 'prorate' => false], 1_500_000, null, $unit);
        $new = $this->agreement([
            'start_date' => '2026-11-16', 'prorate' => false, 'replaces_agreement_id' => $old->id,
        ], 1_500_000, null, Unit::factory()->create(['code' => 'A102']));

        $oldLine = $this->resolver->rentLine($old, $this->resolver->cycleFor($old, 2026, 11));
        $newLine = $this->resolver->rentLine($new, $this->resolver->cycleFor($new, 2026, 11));

        $this->assertSame(750_000, $oldLine['amount_cents']);
        $this->assertSame('Monthly Rental – Room A101 (15/30 days)', $oldLine['description']);
        $this->assertSame(750_000, $newLine['amount_cents']);
        $this->assertSame('Monthly Rental – Room A102 (15/30 days)', $newLine['description']);
    }

    public function test_agreement_not_in_a_transfer_still_follows_prorate_flag(): void
    {
        // Ends mid-cycle but nothing replaced it: not a transfer cycle.
        $a = $this->agreement(['end_date' => '2026-11-15', 'prorate' => false]);

        $line = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));

        $this->assertSame(1_500_000, $line['amount_cents']);
    }

    public function test_transfer_link_outside_the_cycle_does_not_force_proration(): void
    {
        // Old agreement ended in Nov (transfer cycle = Nov); the new one's Dec cycle is a normal full cycle.
        $old = $this->agreement(['end_date' => '2026-11-15']);
        $new = $this->agreement(['start_date' => '2026-11-16', 'replaces_agreement_id' => $old->id]);

        $dec = $this->resolver->rentLine($new, $this->resolver->cycleFor($new, 2026, 12));

        $this->assertSame(1_500_000, $dec['amount_cents']);
        $this->assertStringNotContainsString('days', $dec['description']);
    }

    public function test_bed_agreement_description_appends_bed_label(): void
    {
        $unit = Unit::factory()->create(['code' => 'A101']);
        $bed = Bed::factory()->create(['unit_id' => $unit->id, 'label' => 'Bed 2']);
        $a = $this->agreement(['bed_id' => $bed->id], 500_000, null, $unit);

        $line = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));

        $this->assertSame('Monthly Rental – Room A101 Bed 2', $line['description']);
    }

    public function test_missing_rate_is_an_error_not_a_zero_bill(): void
    {
        $a = RentalAgreement::factory()->create(['start_date' => '2026-01-01']);

        $this->expectException(DomainException::class);

        $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));
    }

    public function test_prorated_bed_agreement_description_puts_bed_before_days(): void
    {
        $unit = Unit::factory()->create(['code' => 'A101']);
        $bed = Bed::factory()->create(['unit_id' => $unit->id, 'label' => 'Bed 2']);
        $a = $this->agreement(['bed_id' => $bed->id, 'start_date' => '2026-11-19', 'prorate' => true], 600_000, null, $unit);

        $line = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 11));

        $this->assertSame('Monthly Rental – Room A101 Bed 2 (12/30 days)', $line['description']);
        $this->assertSame(240_000, $line['amount_cents']);
    }

    public function test_cycle_from_jan_15_to_feb_14_has_31_days(): void
    {
        $a = $this->agreement(['cycle_start_day' => 15]);

        $c = $this->resolver->cycleFor($a, 2026, 1);

        $this->assertSame('2026-02-14', $c->periodEnd->toDateString());
        $this->assertSame(31, $c->periodDays());
    }

    public function test_due_day_29_on_a_15th_cycle_is_clamped_in_february(): void
    {
        $a = $this->agreement(['cycle_start_day' => 15, 'due_day' => 29]);

        $this->assertSame('2026-02-28', $this->resolver->cycleFor($a, 2026, 2)->dueDate->toDateString());
        $this->assertSame('2028-02-29', $this->resolver->cycleFor($a, 2028, 2)->dueDate->toDateString());
    }

    public function test_prorated_rent_not_divisible_by_days_rounds_half_up_to_exact_centavos(): void
    {
        // Dec has 31 days: 250,000 * 7 / 31 = 56,451.61... -> 56,452
        $a = $this->agreement(['start_date' => '2026-12-25', 'prorate' => true], 250_000);

        $line = $this->resolver->rentLine($a, $this->resolver->cycleFor($a, 2026, 12));

        $this->assertSame(56_452, $line['amount_cents']);
        $this->assertSame(7, $this->resolver->cycleFor($a, 2026, 12)->coveredDays($a));
    }
}
