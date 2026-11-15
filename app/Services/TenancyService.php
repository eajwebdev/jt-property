<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\AgreementStatus;
use App\Enums\DepositStatus;
use App\Enums\RentMode;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\Occupancy;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\SecurityDeposit;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenancyService
{
    /**
     * Start a rental: billing account (reused if active), agreement, first rent rate, occupancies, optional deposit.
     * All rules are checked under a lock on the unit row; any failure throws ValidationException and writes nothing.
     * End dates are inclusive (the last day the room is occupied).
     *
     * @param  array{cycle_start_day?: int, issue_lead_days?: int, prorate?: bool, end_date?: CarbonInterface|string|null,
     *               resident_tenant_ids?: int[], deposit_cents?: int|null, override_reason?: string|null}  $options
     */
    public function startAgreement(Tenant $payer, Unit $unit, ?Bed $bed, int $rentCents, int $dueDay, CarbonInterface $startDate, array $options = []): RentalAgreement
    {
        return DB::transaction(fn () => $this->open($payer, $unit, $bed, $rentCents, $dueDay, $startDate, $options));
    }

    /**
     * The body of startAgreement, shared with transfer(); must run inside a transaction.
     * $account forces the billing account (transfer); null finds or creates the payer's active one.
     */
    private function open(Tenant $payer, Unit $unit, ?Bed $bed, int $rentCents, int $dueDay, CarbonInterface $startDate, array $options, ?BillingAccount $account = null, ?RentalAgreement $replaces = null): RentalAgreement
    {
        $unit = Unit::with('property')->lockForUpdate()->findOrFail($unit->id);
        $start = $startDate->toDateString();
        $end = isset($options['end_date']) ? Carbon::parse($options['end_date'])->toDateString() : null;
        $residentIds = array_values(array_unique(array_map('intval', $options['resident_tenant_ids'] ?? [])));
        $override = trim((string) ($options['override_reason'] ?? '')) ?: null;

        // Lock the people too (fixed id order): stops the same tenant being placed in two rooms at once
        // and two active billing accounts being created for one payer.
        $payer = Tenant::whereIn('id', [$payer->id, ...$residentIds])->orderBy('id')->lockForUpdate()->get()->find($payer->id) ?? $payer;

        $errors = $this->ruleErrors($payer, $unit, $bed, $start, $end, $residentIds, $override);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $account ??= $payer->billingAccounts()
            ->where('property_id', $unit->property_id)
            ->where('status', AccountStatus::Active)
            ->orderBy('id')
            ->first()
            ?? BillingAccount::create(['property_id' => $unit->property_id, 'payer_tenant_id' => $payer->id, 'status' => AccountStatus::Active]);

        $agreement = $account->agreements()->create([
            'unit_id' => $unit->id,
            'bed_id' => $bed?->id,
            'due_day' => $dueDay,
            'cycle_start_day' => $options['cycle_start_day'] ?? 1,
            'issue_lead_days' => $options['issue_lead_days'] ?? 7,
            'prorate' => $options['prorate'] ?? false,
            'start_date' => $start,
            'end_date' => $end,
            'status' => AgreementStatus::Active,
            'override_reason' => $override,
            'replaces_agreement_id' => $replaces?->id,
        ]);

        $agreement->rentRates()->create(['rent_cents' => $rentCents, 'effective_from' => $start, 'created_by' => auth()->id()]);

        foreach ([$payer->id, ...$residentIds] as $tenantId) {
            $agreement->occupancies()->create([
                'tenant_id' => $tenantId,
                'unit_id' => $unit->id,
                'bed_id' => $bed?->id,
                'is_payer' => $tenantId === $payer->id,
                'move_in_date' => $start,
                'move_out_date' => $end,
            ]);
        }

        $depositCents = $options['deposit_cents'] ?? null;
        if ($depositCents) {
            // Never dated in the future: a deposit is money already received.
            $this->recordDeposit($account, $depositCents, Carbon::parse(min($start, today()->toDateString())), $agreement);
        }

        Audit::log('agreement.created', $agreement, [
            'new' => [
                'payer_tenant_id' => $payer->id,
                'billing_account_id' => $account->id,
                'unit_id' => $unit->id,
                'bed_id' => $bed?->id,
                'rent_cents' => $rentCents,
                'due_day' => $dueDay,
                'start_date' => $start,
                'end_date' => $end,
                'resident_tenant_ids' => $residentIds,
                'deposit_cents' => $depositCents,
            ],
            'override_reason' => $override,
        ]);

        return $agreement;
    }

    /**
     * Append a new rent rate from $effectiveFrom on. Existing rate rows are never edited,
     * so bills for earlier dates keep their old amount.
     */
    public function changeRent(RentalAgreement $agreement, int $rentCents, CarbonInterface $effectiveFrom, string $reason): RentRate
    {
        return DB::transaction(function () use ($agreement, $rentCents, $effectiveFrom, $reason) {
            $agreement = RentalAgreement::lockForUpdate()->findOrFail($agreement->id);
            $this->guardNotTransferred($agreement);
            $this->guardNotEnded($agreement);
            $from = $effectiveFrom->toDateString();

            $error = match (true) {
                $from < $agreement->start_date->toDateString() => 'The new rent cannot start before the rental starts ('.$agreement->start_date->toDateString().').',
                $agreement->end_date !== null && $from > $agreement->end_date->toDateString() => 'The new rent cannot start after the rental ends ('.$agreement->end_date->toDateString().').',
                $agreement->rentRates()->whereDate('effective_from', $from)->exists() => 'There is already a rent starting on this date. Pick another date.',
                default => null,
            };
            if ($error) {
                throw ValidationException::withMessages(['effective_from' => $error]);
            }

            $old = $agreement->rentOn($from);
            $rate = $agreement->rentRates()->create(['rent_cents' => $rentCents, 'effective_from' => $from, 'reason' => $reason, 'created_by' => auth()->id()]);

            Audit::log('rent.changed', $agreement, [
                'old' => ['rent_cents' => $old],
                'new' => ['rent_cents' => $rentCents, 'effective_from' => $from],
                'reason' => $reason,
            ]);

            return $rate;
        });
    }

    /**
     * End the rental with $date as the last day lived in (inclusive); the room is free from the next day.
     * The agreement stays active until that day passes. Closes the billing account if nothing else continues.
     */
    public function moveOut(RentalAgreement $agreement, CarbonInterface $date, ?string $reason = null): void
    {
        DB::transaction(function () use ($agreement, $date, $reason) {
            Unit::lockForUpdate()->findOrFail($agreement->unit_id);
            $agreement = RentalAgreement::lockForUpdate()->findOrFail($agreement->id);
            $this->guardNotTransferred($agreement);
            $last = $date->toDateString();

            $error = match (true) {
                $last < $agreement->start_date->toDateString() => 'The move-out date cannot be before the rental starts ('.$agreement->start_date->toDateString().').',
                $last > today()->addDays(365)->toDateString() => 'The move-out date must be within the next 12 months.',
                default => $this->endError($agreement, $last),
            };
            if ($error) {
                throw ValidationException::withMessages(['move_out_date' => $error]);
            }

            $old = ['end_date' => $agreement->end_date?->toDateString(), 'status' => $agreement->status->value];
            $this->end($agreement, $last);

            // Close the account only once the last day has passed and nothing else on it continues;
            // a future move-out leaves it active so later bills and transfers still find it.
            $close = $last < today()->toDateString() && ! RentalAgreement::where('billing_account_id', $agreement->billing_account_id)
                ->whereKeyNot($agreement->id)
                ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>', $last))
                ->exists();
            if ($close) {
                BillingAccount::whereKey($agreement->billing_account_id)->update(['status' => AccountStatus::Closed]);
            }

            Audit::log('agreement.moved_out', $agreement, [
                'old' => $old,
                'new' => ['end_date' => $last, 'status' => $agreement->status->value, 'account_closed' => $close],
                'reason' => $reason,
            ]);
        });
    }

    /**
     * A resident (not the payer) leaves on their own: $date is their last day, the place is free from the next day.
     * The rental and the payer carry on. Owner only.
     */
    public function moveOutResident(Occupancy $occupancy, CarbonInterface $date, ?string $reason = null): void
    {
        abort_unless(auth()->user()?->isOwner(), 403);

        DB::transaction(function () use ($occupancy, $date, $reason) {
            // Same order as the other flows: the unit row first, then the people.
            Unit::lockForUpdate()->findOrFail($occupancy->unit_id);
            Tenant::lockForUpdate()->findOrFail($occupancy->tenant_id);
            $occupancy = Occupancy::with('rentalAgreement')->lockForUpdate()->findOrFail($occupancy->id);
            $agreement = $occupancy->rentalAgreement;
            $this->guardNotTransferred($agreement);
            $last = $date->toDateString();
            $current = $occupancy->move_out_date?->toDateString();
            $agreementEnd = $agreement->end_date?->toDateString();

            $error = match (true) {
                $occupancy->is_payer => ['occupancy', 'The person paying moves out with the rental. Use Move out on the rental instead.'],
                $last < $occupancy->move_in_date->toDateString() => ['move_out_date', 'The move-out date cannot be before they moved in ('.$occupancy->move_in_date->toDateString().').'],
                $last > today()->addDays(365)->toDateString() => ['move_out_date', 'The move-out date must be within the next 12 months.'],
                $agreementEnd !== null && $last > $agreementEnd => ['move_out_date', "The rental ends on {$agreementEnd}. Pick that date or earlier."],
                $current !== null && $current < today()->toDateString() => ['move_out_date', 'This person has already moved out.'],
                $current !== null && $last > $current => ['move_out_date', "This person already moves out on {$current}. Pick that date or earlier."],
                default => null,
            };
            if ($error) {
                throw ValidationException::withMessages([$error[0] => $error[1]]);
            }

            $occupancy->update(['move_out_date' => $last]);

            Audit::log('occupancy.moved_out', $occupancy, [
                'old' => ['move_out_date' => $current],
                'new' => ['move_out_date' => $last, 'tenant_id' => $occupancy->tenant_id, 'rental_agreement_id' => $agreement->id],
                'reason' => $reason,
            ]);
        });
    }

    /**
     * Move a rental to another room/bed from $date: the old agreement ends the day before, a new one for the
     * same billing account starts on $date with the same settings and the given or current rent, and the
     * residents move along. All of startAgreement's rules apply; any failure rolls everything back.
     */
    public function transfer(RentalAgreement $agreement, Unit $toUnit, ?Bed $toBed, CarbonInterface $date, ?int $newRentCents = null, ?string $reason = null): RentalAgreement
    {
        return DB::transaction(function () use ($agreement, $toUnit, $toBed, $date, $newRentCents, $reason) {
            // Unit rows first (id order), then startAgreement's own locks re-take them in this transaction.
            Unit::whereIn('id', [$agreement->unit_id, $toUnit->id])->orderBy('id')->lockForUpdate()->get();
            $agreement = RentalAgreement::with('billingAccount.payer')->lockForUpdate()->findOrFail($agreement->id);
            $this->guardNotTransferred($agreement);
            $start = $date->toDateString();
            $last = $date->copy()->subDay()->toDateString();

            $error = match (true) {
                $last < $agreement->start_date->toDateString() => 'The move date must be after the first day of the current rental ('.$agreement->start_date->toDateString().').',
                (int) $toUnit->id === (int) $agreement->unit_id && $toBed?->id == $agreement->bed_id => 'Choose a different room or bed.',
                default => $this->endError($agreement, $last),
            };
            if ($error) {
                throw ValidationException::withMessages(['transfer_date' => $error]);
            }

            // Rent in force on the move day; without a new rent, later scheduled rates move along too.
            $oldRentCents = $agreement->rentOn($last);
            $rentCents = $newRentCents ?? $agreement->rentOn($start);
            $laterRates = $newRentCents === null ? $agreement->rentRates()->where('effective_from', '>', $start)->orderBy('effective_from')->get() : collect();
            $residentIds = $agreement->occupancies()->where('is_payer', false)
                ->where(fn ($q) => $q->whereNull('move_out_date')->orWhere('move_out_date', '>', $last))
                ->pluck('tenant_id')->all();
            $plannedEnd = $agreement->end_date?->toDateString();

            // End the old stay first so the people are free from $date for the shared start rules.
            $this->end($agreement, $last);

            $new = $this->open($agreement->billingAccount->payer, $toUnit, $toBed, $rentCents, $agreement->due_day, $date, [
                'cycle_start_day' => $agreement->cycle_start_day,
                'issue_lead_days' => $agreement->issue_lead_days,
                'prorate' => $agreement->prorate,
                'end_date' => $plannedEnd !== null && $plannedEnd >= $start ? $plannedEnd : null,
                'resident_tenant_ids' => $residentIds,
            ], $agreement->billingAccount, $agreement);

            foreach ($laterRates as $rate) {
                $new->rentRates()->create([
                    'rent_cents' => $rate->rent_cents,
                    'effective_from' => $rate->effective_from->toDateString(),
                    'reason' => trim('Carried from transfer: '.$rate->reason),
                    'created_by' => auth()->id(),
                ]);
            }

            $carriedCharges = $this->carryRecurringCharges($agreement, $new, $start, $last);

            Audit::log('agreement.transferred', $agreement, [
                'old' => ['rental_agreement_id' => $agreement->id, 'unit_id' => $agreement->unit_id, 'bed_id' => $agreement->bed_id, 'end_date' => $last, 'rent_cents' => $oldRentCents],
                'new' => [
                    'carried_recurring_charge_ids' => $carriedCharges,
                    'rental_agreement_id' => $new->id, 'unit_id' => $new->unit_id, 'bed_id' => $new->bed_id, 'start_date' => $start, 'rent_cents' => $rentCents,
                    'carried_rates' => $laterRates->map(fn ($r) => ['effective_from' => $r->effective_from->toDateString(), 'rent_cents' => $r->rent_cents])->values()->all(),
                ],
                'reason' => $reason,
            ]);

            return $new;
        });
    }

    /**
     * Move the old agreement's active add-ons to the new one: the old charge ends the day before $start and a
     * copy starts on $start (same category, label, amount and end). A charge that has not started yet simply
     * moves. Charges that already ended before $start or are switched off stay put. Returns the new charge ids.
     *
     * @return int[]
     */
    private function carryRecurringCharges(RentalAgreement $old, RentalAgreement $new, string $start, string $last): array
    {
        $carried = [];
        $charges = $old->recurringCharges()->where('active', true)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $start))
            ->orderBy('id')->lockForUpdate()->get();

        foreach ($charges as $charge) {
            if ($charge->start_date->toDateString() >= $start) {
                $charge->update(['rental_agreement_id' => $new->id]);
                $carried[] = $charge->id;

                continue;
            }

            $copy = $new->recurringCharges()->create([
                'charge_category_id' => $charge->charge_category_id,
                'label' => $charge->label,
                'amount_cents' => $charge->amount_cents,
                'start_date' => $start,
                'end_date' => $charge->end_date?->toDateString(),
            ]);
            $charge->update(['end_date' => $last]);
            $carried[] = $copy->id;
        }

        return $carried;
    }

    /** A security deposit is a liability record only: it never changes rent or any balance here. */
    public function recordDeposit(BillingAccount $account, int $cents, CarbonInterface $receivedOn, ?RentalAgreement $agreement = null, ?string $notes = null): SecurityDeposit
    {
        $errors = array_filter([
            'amount' => $cents <= 0 ? 'Enter a deposit above zero.' : null,
            'received_on' => $receivedOn->toDateString() > today()->toDateString() ? 'The date received cannot be in the future.' : null,
            'rental_agreement_id' => $agreement && (int) $agreement->billing_account_id !== (int) $account->id ? 'That rental belongs to a different account.' : null,
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($account, $cents, $receivedOn, $agreement, $notes) {
            $deposit = $account->deposits()->create([
                'rental_agreement_id' => $agreement?->id,
                'amount_cents' => $cents,
                'received_on' => $receivedOn->toDateString(),
                'status' => DepositStatus::Held,
                'notes' => $notes,
            ]);

            Audit::log('deposit.recorded', $deposit, [
                'new' => ['billing_account_id' => $account->id, 'rental_agreement_id' => $agreement?->id, 'amount_cents' => $cents, 'received_on' => $receivedOn->toDateString()],
                'reason' => $notes,
            ]);

            return $deposit;
        });
    }

    /** A transfer replaced this agreement; changes belong on the new one. */
    private function guardNotTransferred(RentalAgreement $agreement): void
    {
        if ($agreement->isReplaced()) {
            throw ValidationException::withMessages(['agreement' => 'This rental was transferred; manage the new one.']);
        }
    }

    /** An agreement whose last day has passed is history: nothing on it changes any more. */
    private function guardNotEnded(RentalAgreement $agreement): void
    {
        if ($agreement->end_date !== null && $agreement->end_date->toDateString() < today()->toDateString()) {
            throw ValidationException::withMessages(['agreement' => 'This rental has already ended.']);
        }
    }

    /** Why the agreement cannot be given $last as its final day, if anything. Ending is only ever brought forward. */
    private function endError(RentalAgreement $agreement, string $last): ?string
    {
        $end = $agreement->end_date?->toDateString();

        return match (true) {
            $end !== null && $end < today()->toDateString() => 'This rental has already ended.',
            $end !== null && $last > $end => "This rental already ends on {$end}. Pick that date or earlier.",
            default => null,
        };
    }

    /** Set the inclusive last day on the agreement and on its stays that run past it. */
    private function end(RentalAgreement $agreement, string $last): void
    {
        $agreement->update([
            'end_date' => $last,
            'status' => $last < today()->toDateString() ? AgreementStatus::Ended : AgreementStatus::Active,
        ]);

        $agreement->occupancies()
            ->where('move_in_date', '<=', $last)
            ->where(fn ($q) => $q->whereNull('move_out_date')->orWhere('move_out_date', '>', $last))
            ->update(['move_out_date' => $last]);
    }

    /**
     * Every broken rule at once, keyed by field, so the form can show them together.
     *
     * @param  int[]  $residentIds
     * @return array<string, string>
     */
    private function ruleErrors(Tenant $payer, Unit $unit, ?Bed $bed, string $start, ?string $end, array $residentIds, ?string $override): array
    {
        $errors = [];

        if ($payer->archived_at !== null) {
            $errors['tenant'] = 'This tenant is archived. Archived tenants cannot start a new rental.';
        } elseif ((int) $payer->property_id !== (int) $unit->property_id) {
            $errors['tenant'] = 'This tenant belongs to a different property than the room.';
        } elseif ($this->overlapping(Occupancy::query(), $start, $end)->where('tenant_id', $payer->id)->exists()) {
            // Never bypassed by override_reason: a person cannot live in two places at once.
            $errors['tenant'] = 'This tenant already has a room for some of these dates.';
        }

        if ($end !== null && $end < $start) {
            $errors['end_date'] = 'The end date cannot be before the start date.';
        }

        if ($unit->status !== UnitStatus::Available) {
            $errors['unit_status'] = $unit->status === UnitStatus::Maintenance
                ? 'This room is under repair. Mark it as open for tenants before starting a rental.'
                : 'This room is marked as not available. Mark it as open for tenants before starting a rental.';
        }

        // Rules follow the room's rent mode, never whether bed rows happen to exist.
        if ($unit->rent_mode === RentMode::PerBed && $bed === null) {
            $errors['bed'] = 'Choose a bed. This room is rented bed by bed.';
        } elseif ($unit->rent_mode === RentMode::Whole && $bed !== null) {
            $errors['bed'] = 'This room is rented as a whole. Do not choose a bed.';
        } elseif ($bed !== null && (int) $bed->unit_id !== (int) $unit->id) {
            $errors['bed'] = 'That bed is not in this room.';
        }

        if ($override !== null && ! auth()->user()?->isOwner()) {
            $errors['override_reason'] = 'Only the owner can override a date conflict.';
        } elseif ($override !== null && mb_strlen($override) < 10) {
            $errors['override_reason'] = 'Explain the override in at least 10 characters.';
        }

        if ($unit->property->archived_at !== null) {
            $errors['property'] = 'This property is archived. Rentals cannot start in an archived property.';
        } elseif ($override === null && ! isset($errors['bed']) && $this->hasOverlappingAgreement($unit, $bed, $start, $end)) {
            $errors['unit'] = $bed
                ? 'This bed (or the whole room) is already rented for some of these dates.'
                : 'This room already has a rental for some of these dates.';
        }

        if ($residentIds && $unit->rent_mode !== RentMode::Whole) {
            $errors['resident_tenant_ids'] = 'Other residents can only be added when the whole room is rented. Start a separate rental for each bed.';
        } elseif ($residentIds && ($message = $this->residentError($payer, $unit, $residentIds, $start, $end))) {
            $errors['resident_tenant_ids'] = $message;
        }

        if ($this->peakOccupancy($unit, $start, $end) + 1 + count($residentIds) > $unit->capacity) {
            $errors['capacity'] = "This room fits {$unit->capacity} ".str('person')->plural($unit->capacity).' and would be over capacity on some of these dates.';
        }

        return $errors;
    }

    /**
     * Agreements overlap when a.start <= b.end (or open) and b.start <= a.end (or open).
     * Dates decide, not the status column: an ended agreement always has its end_date set.
     */
    private function hasOverlappingAgreement(Unit $unit, ?Bed $bed, string $start, ?string $end): bool
    {
        return $unit->agreements()
            ->when($end, fn ($q) => $q->where('start_date', '<=', $end))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $start))
            // Whole-unit: any agreement conflicts. Per-bed: the same bed or a whole-unit agreement.
            ->when($bed, fn ($q) => $q->where(fn ($w) => $w->where('bed_id', $bed->id)->orWhereNull('bed_id')))
            ->exists();
    }

    /** Highest number of people in the unit on any day of [start, end]; the count only rises on a move-in date. */
    private function peakOccupancy(Unit $unit, string $start, ?string $end): int
    {
        $occupancies = $this->overlapping($unit->occupancies()->getQuery(), $start, $end)->get(['move_in_date', 'move_out_date']);

        $days = $occupancies->toBase()->map(fn ($o) => $o->move_in_date->toDateString())->filter(fn ($d) => $d > $start)->push($start)->unique();

        return $days->map(fn ($day) => $occupancies->filter(
            fn ($o) => $o->move_in_date->toDateString() <= $day && ($o->move_out_date === null || $o->move_out_date->toDateString() >= $day)
        )->count())->max();
    }

    /** @param  int[]  $residentIds */
    private function residentError(Tenant $payer, Unit $unit, array $residentIds, string $start, ?string $end): ?string
    {
        if (in_array($payer->id, $residentIds, true)) {
            return 'The person paying is already living in the room. Do not add them again as a resident.';
        }

        $valid = Tenant::whereIn('id', $residentIds)->where('property_id', $unit->property_id)->whereNull('archived_at')->count();
        if ($valid !== count($residentIds)) {
            return 'Residents must be current (not archived) tenants of this property.';
        }

        $busy = $this->overlapping(Occupancy::query(), $start, $end)->whereIn('tenant_id', $residentIds)->exists();

        return $busy ? 'One of the residents already has a room for some of these dates.' : null;
    }

    /** Occupancies whose stay [move_in, move_out or open] overlaps [start, end or open]. */
    private function overlapping(Builder $query, string $start, ?string $end): Builder
    {
        return $query
            ->when($end, fn ($q) => $q->where('move_in_date', '<=', $end))
            ->where(fn ($q) => $q->whereNull('move_out_date')->orWhere('move_out_date', '>=', $start));
    }
}
