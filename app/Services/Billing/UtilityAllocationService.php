<?php

namespace App\Services\Billing;

use App\Enums\RentMode;
use App\Models\ChargeCategory;
use App\Models\RentalAgreement;
use App\Models\UnitUtility;
use App\Models\UtilityBill;
use App\Models\UtilityExclusion;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Turns a unit's utility setup plus a month's reading or master bill into a bill line per agreement.
 * Read-only; allocations always add up to the bill to the centavo (largest remainder).
 */
class UtilityAllocationService
{
    /** type => [label, unit of measure] */
    public const TYPES = ['electricity' => ['Electricity', 'kWh'], 'water' => ['Water', 'm³']];

    public function __construct(private BillingCycleResolver $cycles) {}

    /** Metered: round(consumption x rate) + base charge. Shared: the amount the owner entered. */
    public function amountFor(UtilityBill $bill): int
    {
        if ($bill->current_reading_e3 === null) {
            return $bill->amount_cents;
        }

        $used = $bill->current_reading_e3 - ($bill->previous_reading_e3 ?? 0);
        if ($used < 0 || $bill->rate_e4 === null) {
            throw new LogicException('Utility bill has an invalid reading or no rate.');
        }

        return Money::roundHalfUpDiv($used * $bill->rate_e4, 100000) + $bill->base_charge_cents;
    }

    /**
     * Each eligible agreement's share of the bill, keyed by agreement id (ascending). Eligible means
     * running at least one day of its own cycle. Empty when nobody is eligible or a percent/manual
     * setup does not cover the eligible agreements.
     *
     * @param  Collection<int, RentalAgreement>  $agreements
     * @param  array<int, BillingCycle|null>  $cycles  keyed by agreement id
     * @return array<int, int>
     */
    public function allocate(UtilityBill $bill, Collection $agreements, array $cycles): array
    {
        return $this->split($bill, $this->eligible($agreements, $cycles)) ?? [];
    }

    /**
     * Utility lines for one agreement and cycle.
     * A metered/shared utility with no bill yet is listed in needs_input (never billed as zero) unless
     * the owner excluded it for this agreement and month. no_occupants lists types with a bill but nobody to bill.
     *
     * @return array{lines: Collection<int, array{type: string, label: string, amount_cents: int, category_id: ?int, source: UtilityBill|UnitUtility}>, needs_input: list<string>, no_occupants: list<string>}
     */
    public function linesFor(RentalAgreement $agreement, BillingCycle $cycle): array
    {
        $month = $cycle->periodStart->startOfMonth();
        $lines = collect();
        $needsInput = [];
        $noOccupants = [];

        $configs = UnitUtility::where('unit_id', $agreement->unit_id)->get()->keyBy('type');
        $excluded = UtilityExclusion::where('rental_agreement_id', $agreement->id)->whereDate('bill_month', $month->toDateString())->pluck('type');

        foreach (self::TYPES as $type => [$name, $measure]) {
            $config = $configs->get($type);
            if ($config === null || $config->mode === 'inclusive' || $excluded->contains($type)) {
                continue;
            }

            if ($config->mode === 'fixed') {
                if (($config->fixed_cents ?? 0) > 0) {
                    $lines->push($this->line($type, "{$name} – fixed charge", $config->fixed_cents, $config));
                }

                continue;
            }

            $bill = UtilityBill::where('unit_id', $agreement->unit_id)->where('type', $type)->whereDate('bill_month', $month->toDateString())->first();
            if ($bill === null) {
                $needsInput[] = $type;

                continue;
            }

            $eligible = $this->eligible($this->unitAgreements($agreement, $cycle), [$agreement->id => $cycle] + $this->cyclesFor($agreement, $cycle));
            if ($eligible->isEmpty()) {
                $noOccupants[] = $type;

                continue;
            }

            $split = $this->split($bill, $eligible);
            if ($split === null) {
                $needsInput[] = $type;

                continue;
            }

            $cents = $split[$agreement->id] ?? 0;
            if ($cents > 0) {
                $share = count($split) > 1 ? ' (your share)' : '';
                $label = $bill->current_reading_e3 !== null
                    ? "{$name} – ".Quantity::formatE3($bill->current_reading_e3 - ($bill->previous_reading_e3 ?? 0))." {$measure} × ₱".Quantity::formatRateE4($bill->rate_e4).$share
                    : "{$name} – shared bill ".Money::format($bill->amount_cents).$share;
                $lines->push($this->line($type, $label, $cents, $bill));
            }
        }

        return ['lines' => $lines, 'needs_input' => $needsInput, 'no_occupants' => $noOccupants];
    }

    private function line(string $type, string $label, int $cents, UtilityBill|UnitUtility $source): array
    {
        static $categories = [];
        $categories[$type] ??= ChargeCategory::where('name', self::TYPES[$type][0])->value('id');

        return ['type' => $type, 'label' => $label, 'amount_cents' => $cents, 'category_id' => $categories[$type], 'source' => $source];
    }

    /** @return Collection<int, RentalAgreement> every agreement of the unit that overlaps the cycle's period */
    private function unitAgreements(RentalAgreement $agreement, BillingCycle $cycle): Collection
    {
        return RentalAgreement::where('unit_id', $agreement->unit_id)
            ->whereDate('start_date', '<=', $cycle->periodEnd->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $cycle->periodStart->toDateString()))
            ->get();
    }

    /** @return array<int, BillingCycle|null> */
    private function cyclesFor(RentalAgreement $agreement, BillingCycle $cycle): array
    {
        return $this->unitAgreements($agreement, $cycle)
            ->mapWithKeys(fn ($a) => [$a->id => $this->cycles->cycleFor($a, $cycle->periodStart->year, $cycle->periodStart->month)])
            ->all();
    }

    /** @return Collection<int, array{agreement: RentalAgreement, days: int}> keyed by agreement id, ascending */
    private function eligible(Collection $agreements, array $cycles): Collection
    {
        return $agreements
            ->mapWithKeys(fn (RentalAgreement $a) => [$a->id => ['agreement' => $a, 'days' => ($cycles[$a->id] ?? null)?->coveredDays($a) ?? 0]])
            ->filter(fn ($e) => $e['days'] > 0)
            ->sortKeys();
    }

    /** @return array<int, int>|null null when the unit's percent/manual setup cannot cover these agreements */
    private function split(UtilityBill $bill, Collection $eligible): ?array
    {
        if ($eligible->isEmpty()) {
            return [];
        }

        $amount = $this->amountFor($bill);
        $unit = $bill->unit;
        $method = $unit->rent_mode === RentMode::Whole ? 'days' : ($this->config($bill)?->split_method ?? 'equal');

        if ($method === 'equal') {
            return $this->largestRemainder($amount, $eligible->map(fn () => 1)->all());
        }
        if ($method === 'days') {
            return $this->largestRemainder($amount, $eligible->map(fn ($e) => $e['days'])->all());
        }

        $shares = $this->config($bill)?->shares()->get()->keyBy('rental_agreement_id') ?? collect();
        $column = $method === 'percent' ? 'percent_bp' : 'manual_cents';
        $weights = $eligible->map(fn ($e, $id) => $shares->get($id)?->{$column})->all();
        if (in_array(null, $weights, true)) {
            return null;
        }

        if ($method === 'manual') {
            return array_sum($weights) === $amount ? $weights : null;
        }

        return array_sum($weights) > 0 ? $this->largestRemainder($amount, $weights) : null;
    }

    private function config(UtilityBill $bill): ?UnitUtility
    {
        return UnitUtility::where('unit_id', $bill->unit_id)->where('type', $bill->type)->first();
    }

    /**
     * Split $amount by integer weights so the parts add up exactly: floor each share, then hand the
     * leftover centavos to the biggest remainders, ties to the lowest agreement id.
     *
     * @param  array<int, int>  $weights  agreement id => weight
     * @return array<int, int>
     */
    private function largestRemainder(int $amount, array $weights): array
    {
        ksort($weights);
        $total = array_sum($weights);
        $parts = [];
        $remainders = [];
        foreach ($weights as $id => $w) {
            $parts[$id] = intdiv($amount * $w, $total);
            $remainders[$id] = ($amount * $w) % $total;
        }

        $ids = array_keys($weights);
        usort($ids, fn ($a, $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);
        foreach (array_slice($ids, 0, $amount - array_sum($parts)) as $id) {
            $parts[$id]++;
        }
        ksort($parts);

        return $parts;
    }
}
