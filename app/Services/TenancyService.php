<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\AgreementStatus;
use App\Enums\RentMode;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\Occupancy;
use App\Models\RentalAgreement;
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
        return DB::transaction(function () use ($payer, $unit, $bed, $rentCents, $dueDay, $startDate, $options) {
            $unit = Unit::with('property')->lockForUpdate()->findOrFail($unit->id);
            $start = $startDate->toDateString();
            $end = isset($options['end_date']) ? Carbon::parse($options['end_date'])->toDateString() : null;
            $residentIds = array_values(array_unique(array_map('intval', $options['resident_tenant_ids'] ?? [])));
            $override = trim((string) ($options['override_reason'] ?? '')) ?: null;

            $errors = $this->ruleErrors($payer, $unit, $bed, $start, $end, $residentIds, $override);
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $account = $payer->billingAccounts()
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
                $account->deposits()->create(['rental_agreement_id' => $agreement->id, 'amount_cents' => $depositCents, 'received_on' => $start]);
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
        });
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
            $errors['unit'] = 'This property is archived. Rentals cannot start in an archived property.';
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

    /** Active agreements overlap when a.start <= b.end (or open) and b.start <= a.end (or open). */
    private function hasOverlappingAgreement(Unit $unit, ?Bed $bed, string $start, ?string $end): bool
    {
        return $unit->agreements()
            ->where('status', AgreementStatus::Active)
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
