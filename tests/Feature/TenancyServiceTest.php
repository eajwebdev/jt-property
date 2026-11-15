<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\RentMode;
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
use App\Services\TenancyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TenancyServiceTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private TenancyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $this->service = app(TenancyService::class);
        $this->actingAs(User::factory()->owner()->create());
    }

    private function perBedUnit(string $code = 'A101', int $capacity = 4): Unit
    {
        $unit = Unit::factory()->for($this->property)->create(['code' => $code, 'capacity' => $capacity, 'rent_mode' => RentMode::PerBed]);
        foreach (range(1, $capacity) as $n) {
            $unit->beds()->create(['label' => "Bed $n"]);
        }

        return $unit;
    }

    private function wholeUnit(string $code = 'B201', int $capacity = 4): Unit
    {
        return Unit::factory()->for($this->property)->create(['code' => $code, 'capacity' => $capacity, 'rent_mode' => RentMode::Whole]);
    }

    private function tenant(string $name = 'Demo Tenant'): Tenant
    {
        return Tenant::factory()->for($this->property)->create(['full_name' => $name]);
    }

    private function bed(Unit $unit, int $n): Bed
    {
        return $unit->beds()->where('label', "Bed $n")->firstOrFail();
    }

    private function date(string $date): Carbon
    {
        return Carbon::parse($date);
    }

    /** Asserts the call fails validation on $key and returns all error keys. */
    private function assertRejected(string $key, callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors(), 'Errors were: '.json_encode($e->errors()));

            return array_keys($e->errors());
        }

        $this->fail("Expected a validation error on [$key].");
    }

    private function counts(): array
    {
        return [
            BillingAccount::count(), RentalAgreement::count(), RentRate::count(),
            Occupancy::count(), SecurityDeposit::count(), ActivityLog::count(),
        ];
    }

    public function test_room_a101_four_tenants_each_with_own_rent_and_due_day(): void
    {
        $unit = $this->perBedUnit();
        $start = $this->date('2026-12-01');
        $people = [['Ana', 250000, 5], ['Ben', 270000, 12], ['Cara', 240000, 18], ['Dan', 300000, 28]];

        $agreements = [];
        foreach ($people as $i => [$name, $rent, $due]) {
            $agreements[$name] = $this->service->startAgreement($this->tenant("Demo $name"), $unit, $this->bed($unit, $i + 1), $rent, $due, $start);
        }

        $this->assertSame(4, BillingAccount::count());
        $this->assertSame(4, BillingAccount::distinct()->count('payer_tenant_id'));
        foreach ($people as [$name, $rent, $due]) {
            $this->assertSame($rent, $agreements[$name]->rentOn('2026-12-15'));
            $this->assertSame($due, $agreements[$name]->due_day);
            $this->assertNull($agreements[$name]->rentOn('2026-11-30'));
        }
        $this->assertSame(4, $unit->occupiedCountOn('2026-12-01'));
        $this->assertSame(4, Occupancy::where('is_payer', true)->count());
        $this->assertSame(4, ActivityLog::where('action', 'agreement.created')->count());
    }

    public function test_fifth_tenant_in_capacity_four_room_is_rejected_and_nothing_written(): void
    {
        $unit = $this->perBedUnit();
        foreach (range(1, 4) as $n) {
            $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, $n), 250000, 5, $this->date('2026-12-01'));
        }
        $before = $this->counts();

        $this->assertRejected('capacity', fn () => $this->service->startAgreement($this->tenant('Demo Eve'), $unit, $this->bed($unit, 1), 250000, 5, $this->date('2026-12-10')));

        $this->assertSame($before, $this->counts());
    }

    public function test_capacity_counts_peak_concurrency_not_every_past_occupancy(): void
    {
        $unit = $this->perBedUnit('C1', 1);
        $bed = $this->bed($unit, 1);

        $this->service->startAgreement($this->tenant(), $unit, $bed, 200000, 5, $this->date('2026-01-01'), ['end_date' => '2026-03-31']);
        $this->service->startAgreement($this->tenant(), $unit, $bed, 200000, 5, $this->date('2026-04-01'), ['end_date' => '2026-06-30']);
        $this->service->startAgreement($this->tenant(), $unit, $bed, 200000, 5, $this->date('2026-07-01'));

        $this->assertSame(3, RentalAgreement::count());

        // Two people one after another on Bed 1 during the new open-ended stay never exceed 1 + 1.
        $two = $this->perBedUnit('C3', 2);
        Occupancy::factory()->create(['unit_id' => $two->id, 'move_in_date' => '2026-01-01', 'move_out_date' => '2026-03-31']);
        Occupancy::factory()->create(['unit_id' => $two->id, 'move_in_date' => '2026-04-01']);
        $this->service->startAgreement($this->tenant(), $two, $this->bed($two, 2), 200000, 5, $this->date('2026-01-01'));
        $this->assertSame(4, RentalAgreement::count());
    }

    public function test_capacity_counts_occupancies_starting_later_in_the_new_period(): void
    {
        $unit = $this->perBedUnit('C2', 2);
        Occupancy::factory()->create(['unit_id' => $unit->id, 'move_in_date' => '2027-01-01']);
        Occupancy::factory()->create(['unit_id' => $unit->id, 'move_in_date' => '2027-02-01']);

        // Free on 2026-12-01, but full from 2027-02-01 while this open-ended rental would still run.
        $this->assertRejected('capacity', fn () => $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, 1), 200000, 5, $this->date('2026-12-01')));

        // Ending before the second move-in fits.
        $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, 1), 200000, 5, $this->date('2026-12-01'), ['end_date' => '2027-01-31']);
        $this->assertSame(1, RentalAgreement::count());
    }

    public function test_whole_unit_payer_with_residents(): void
    {
        $unit = $this->wholeUnit();
        $payer = $this->tenant('Demo Payer');
        $residents = collect(range(1, 3))->map(fn ($n) => $this->tenant("Demo Resident $n"));

        $agreement = $this->service->startAgreement($payer, $unit, null, 1000000, 10, $this->date('2026-12-01'), [
            'resident_tenant_ids' => $residents->pluck('id')->all(),
        ]);

        $this->assertTrue($agreement->isWholeUnit());
        $this->assertSame(1, BillingAccount::count());
        $this->assertSame($payer->id, $agreement->billingAccount->payer_tenant_id);
        $this->assertSame(1, RentalAgreement::count());
        $this->assertSame(4, Occupancy::where('rental_agreement_id', $agreement->id)->count());
        $this->assertSame(1, Occupancy::where('is_payer', true)->count());
        $this->assertSame($payer->id, Occupancy::where('is_payer', true)->value('tenant_id'));
        $this->assertSame(1000000, $agreement->rentOn('2026-12-01'));
    }

    public function test_whole_unit_overlap_rejected_until_first_ends(): void
    {
        $unit = $this->wholeUnit();
        $this->service->startAgreement($this->tenant(), $unit, null, 1000000, 10, $this->date('2026-01-01'), ['end_date' => '2026-06-30']);

        $this->assertRejected('unit', fn () => $this->service->startAgreement($this->tenant(), $unit, null, 1000000, 10, $this->date('2026-03-01')));
        // End date is inclusive: the first tenant still has the room on 30 June.
        $this->assertRejected('unit', fn () => $this->service->startAgreement($this->tenant(), $unit, null, 1000000, 10, $this->date('2026-06-30')));
        // A rental ending before the first one starts also overlaps if it reaches into it.
        $this->assertRejected('unit', fn () => $this->service->startAgreement($this->tenant(), $unit, null, 1000000, 10, $this->date('2025-12-01'), ['end_date' => '2026-01-01']));

        $this->service->startAgreement($this->tenant(), $unit, null, 1000000, 10, $this->date('2026-07-01'));
        $this->service->startAgreement($this->tenant(), $unit, null, 1000000, 10, $this->date('2025-11-01'), ['end_date' => '2025-12-31']);
        $this->assertSame(3, RentalAgreement::count());
    }

    public function test_whole_agreement_conflicts_with_any_active_per_bed_agreement_on_the_unit(): void
    {
        // A unit that was switched to whole while a per-bed rental was still running.
        $unit = $this->wholeUnit('D1', 2);
        $bed = $unit->beds()->create(['label' => 'Bed 1']);
        $account = BillingAccount::factory()->create(['property_id' => $this->property->id]);
        RentalAgreement::factory()->create(['billing_account_id' => $account->id, 'unit_id' => $unit->id, 'bed_id' => $bed->id, 'start_date' => '2026-01-01', 'end_date' => '2026-06-30']);

        $this->assertRejected('unit', fn () => $this->service->startAgreement($this->tenant(), $unit, null, 500000, 10, $this->date('2026-06-01')));

        $this->service->startAgreement($this->tenant(), $unit, null, 500000, 10, $this->date('2026-07-01'));
        $this->assertSame(2, RentalAgreement::count());
    }

    public function test_per_bed_conflicts_with_same_bed_or_whole_unit_agreement_only(): void
    {
        $unit = $this->perBedUnit('E1', 2);
        $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, 1), 250000, 5, $this->date('2026-01-01'), ['end_date' => '2026-06-30']);

        $this->assertRejected('unit', fn () => $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, 1), 250000, 5, $this->date('2026-06-30')));
        $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, 1), 250000, 5, $this->date('2026-07-01'));
        $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, 2), 250000, 5, $this->date('2026-03-01'), ['end_date' => '2026-03-31']);

        // A whole-unit agreement on a per-bed room (legacy data) blocks every bed for its dates.
        $account = BillingAccount::factory()->create(['property_id' => $this->property->id]);
        RentalAgreement::factory()->create(['billing_account_id' => $account->id, 'unit_id' => $unit->id, 'bed_id' => null, 'start_date' => '2026-04-01', 'end_date' => '2026-04-30']);
        $this->assertRejected('unit', fn () => $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, 2), 250000, 5, $this->date('2026-04-15'), ['end_date' => '2026-04-20']));
    }

    public function test_bed_rules_follow_the_rent_mode(): void
    {
        $whole = $this->wholeUnit();
        $whole->beds()->create(['label' => 'Bed 1']); // leftover bed must not make it per-bed
        $perBed = $this->perBedUnit();
        $otherUnit = $this->perBedUnit('Z9', 1);

        $this->assertRejected('bed', fn () => $this->service->startAgreement($this->tenant(), $whole, $whole->beds()->first(), 250000, 5, $this->date('2026-12-01')));
        $this->assertRejected('bed', fn () => $this->service->startAgreement($this->tenant(), $perBed, null, 250000, 5, $this->date('2026-12-01')));
        $this->assertRejected('bed', fn () => $this->service->startAgreement($this->tenant(), $perBed, $this->bed($otherUnit, 1), 250000, 5, $this->date('2026-12-01')));
        $this->assertSame(0, RentalAgreement::count());
    }

    public function test_owner_override_bypasses_overlap_and_is_logged(): void
    {
        $unit = $this->wholeUnit('F1', 2);
        $this->service->startAgreement($this->tenant(), $unit, null, 500000, 10, $this->date('2026-01-01'));

        $agreement = $this->service->startAgreement($this->tenant(), $unit, null, 500000, 10, $this->date('2026-02-01'), [
            'override_reason' => 'Sharing agreed in writing by both tenants',
        ]);

        $this->assertSame('Sharing agreed in writing by both tenants', $agreement->override_reason);
        $log = ActivityLog::where('action', 'agreement.created')->where('subject_id', $agreement->id)->firstOrFail();
        $this->assertSame('Sharing agreed in writing by both tenants', $log->properties['override_reason']);
    }

    public function test_override_never_bypasses_capacity_or_unit_status(): void
    {
        $unit = $this->perBedUnit();
        foreach (range(1, 4) as $n) {
            $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, $n), 250000, 5, $this->date('2026-12-01'));
        }
        $before = $this->counts();
        $override = ['override_reason' => 'Owner says this is fine for now'];

        $keys = $this->assertRejected('capacity', fn () => $this->service->startAgreement($this->tenant(), $unit, $this->bed($unit, 1), 250000, 5, $this->date('2026-12-01'), $override));
        $this->assertNotContains('unit', $keys);
        $this->assertSame($before, $this->counts());

        $repair = $this->wholeUnit('G1');
        $repair->update(['status' => UnitStatus::Maintenance]);
        $this->assertRejected('unit_status', fn () => $this->service->startAgreement($this->tenant(), $repair, null, 250000, 5, $this->date('2026-12-01'), $override));
    }

    public function test_override_requires_owner_and_a_real_reason(): void
    {
        $unit = $this->wholeUnit();
        $this->service->startAgreement($this->tenant(), $unit, null, 500000, 10, $this->date('2026-01-01'));

        $this->assertRejected('override_reason', fn () => $this->service->startAgreement($this->tenant(), $unit, null, 500000, 10, $this->date('2026-02-01'), ['override_reason' => 'ok']));

        $this->actingAs(User::factory()->create()); // staff
        $this->assertRejected('override_reason', fn () => $this->service->startAgreement($this->tenant(), $unit, null, 500000, 10, $this->date('2026-02-01'), ['override_reason' => 'A long enough reason here']));
        $this->assertSame(1, RentalAgreement::count());
    }

    public function test_unit_under_maintenance_or_unavailable_is_rejected(): void
    {
        foreach ([UnitStatus::Maintenance, UnitStatus::Unavailable] as $status) {
            $unit = Unit::factory()->for($this->property)->create(['status' => $status]);
            $this->assertRejected('unit_status', fn () => $this->service->startAgreement($this->tenant(), $unit, null, 250000, 5, $this->date('2026-12-01')));
        }
        $this->assertSame(0, RentalAgreement::count());
    }

    public function test_archived_tenant_or_property_cannot_start(): void
    {
        $unit = $this->wholeUnit();
        $archived = Tenant::factory()->for($this->property)->create(['archived_at' => now()]);
        $this->assertRejected('tenant', fn () => $this->service->startAgreement($archived, $unit, null, 250000, 5, $this->date('2026-12-01')));

        $outsider = Tenant::factory()->create();
        $this->assertRejected('tenant', fn () => $this->service->startAgreement($outsider, $unit, null, 250000, 5, $this->date('2026-12-01')));

        $this->property->update(['archived_at' => now()]);
        $this->assertRejected('unit', fn () => $this->service->startAgreement($this->tenant(), $unit->fresh(), null, 250000, 5, $this->date('2026-12-01')));
        $this->assertSame(0, RentalAgreement::count());
    }

    public function test_residents_must_be_free_active_tenants_of_the_same_property_and_whole_unit_only(): void
    {
        $unit = $this->wholeUnit();
        $start = $this->date('2026-12-01');
        $archived = Tenant::factory()->for($this->property)->create(['archived_at' => now()]);
        $outsider = Tenant::factory()->create();
        $busy = $this->tenant('Demo Busy');
        Occupancy::factory()->for($busy)->create(['move_in_date' => '2026-01-01']);
        $payer = $this->tenant('Demo Payer');

        foreach ([$archived->id, $outsider->id, $busy->id, $payer->id, 999999] as $id) {
            $this->assertRejected('resident_tenant_ids', fn () => $this->service->startAgreement($payer, $unit, null, 500000, 5, $start, ['resident_tenant_ids' => [$id]]));
        }

        $perBed = $this->perBedUnit();
        $this->assertRejected('resident_tenant_ids', fn () => $this->service->startAgreement($payer, $perBed, $this->bed($perBed, 1), 250000, 5, $start, ['resident_tenant_ids' => [$this->tenant()->id]]));
        $this->assertSame(0, RentalAgreement::count());

        // A resident whose previous stay ended before the start date is fine.
        $moved = $this->tenant('Demo Moved');
        Occupancy::factory()->for($moved)->create(['move_in_date' => '2026-01-01', 'move_out_date' => '2026-11-30']);
        $this->service->startAgreement($payer, $unit, null, 500000, 5, $start, ['resident_tenant_ids' => [$moved->id]]);
        $this->assertSame(2, Occupancy::where('unit_id', $unit->id)->count());
    }

    public function test_residents_count_towards_capacity(): void
    {
        $unit = $this->wholeUnit('H1', 2);
        $residents = [$this->tenant()->id, $this->tenant()->id];

        $this->assertRejected('capacity', fn () => $this->service->startAgreement($this->tenant(), $unit, null, 500000, 5, $this->date('2026-12-01'), ['resident_tenant_ids' => $residents]));
        $this->assertSame(0, Occupancy::count());
    }

    public function test_payer_reuses_active_billing_account_and_deposit_is_recorded(): void
    {
        $unit = $this->perBedUnit();
        $payer = $this->tenant();
        $closed = BillingAccount::factory()->create(['property_id' => $this->property->id, 'payer_tenant_id' => $payer->id, 'status' => AccountStatus::Closed]);
        $active = BillingAccount::factory()->create(['property_id' => $this->property->id, 'payer_tenant_id' => $payer->id, 'status' => AccountStatus::Active]);

        $agreement = $this->service->startAgreement($payer, $unit, $this->bed($unit, 1), 250000, 5, $this->date('2026-12-01'), [
            'deposit_cents' => 500000, 'cycle_start_day' => 3, 'issue_lead_days' => 10, 'prorate' => true,
        ]);

        $this->assertSame($active->id, $agreement->billing_account_id);
        $this->assertNotSame($closed->id, $agreement->billing_account_id);
        $this->assertSame([3, 10, true], [$agreement->cycle_start_day, $agreement->issue_lead_days, $agreement->prorate]);
        $deposit = SecurityDeposit::sole();
        $this->assertSame([500000, $active->id, $agreement->id], [$deposit->amount_cents, $deposit->billing_account_id, $deposit->rental_agreement_id]);
        $this->assertSame('2026-12-01', RentRate::sole()->effective_from->toDateString());
    }
}
