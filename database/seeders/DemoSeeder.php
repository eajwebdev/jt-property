<?php

namespace Database\Seeders;

use App\Enums\DeliveryMethod;
use App\Enums\PropertyType;
use App\Enums\RentMode;
use App\Enums\Role;
use App\Enums\UnitStatus;
use App\Models\ChargeCategory;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Models\UtilityBill;
use App\Services\Billing\OpeningBalanceService;
use App\Services\TenancyService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Fake, clearly labelled "Demo" properties, people and rentals. Safe to run twice. */
class DemoSeeder extends Seeder
{
    public function run(TenancyService $tenancy): void
    {
        if (Property::where('code', 'RIV')->exists()) {
            return;
        }

        $owner = User::where('role', Role::Owner)->orderBy('id')->first();

        // All or nothing, and the acting user is always cleared again, even when something throws.
        try {
            Auth::setUser($owner ?? new User);
            DB::transaction(fn () => $this->seed($tenancy));
        } finally {
            Auth::forgetGuards();
        }
    }

    private function seed(TenancyService $tenancy): void
    {
        $start = Carbon::parse('2026-09-01');

        $riverside = Property::create([
            'name' => 'Riverside Demo Residences',
            'code' => 'RIV',
            'type' => PropertyType::BoardingHouse,
            'address' => '123 Demo Street, Barangay Sample, Demo City, Philippines (fake address)',
            'description' => 'Demo data: boarding house with bed-by-bed rooms.',
        ]);
        $hillside = Property::create([
            'name' => 'Hillside Demo Apartments',
            'code' => 'HIL',
            'type' => PropertyType::Apartment,
            'address' => '45 Sample Road, Demo Heights, Demo City, Philippines (fake address)',
            'description' => 'Demo data: apartment units rented as a whole.',
        ]);

        User::where('email', 'staff@jt.test')->first()?->properties()->syncWithoutDetaching([$riverside->id]);

        $a101 = $this->unit($riverside, 'A101', 4, RentMode::PerBed);
        $a102 = $this->unit($riverside, 'A102', 2, RentMode::PerBed);
        $this->unit($riverside, 'A103', 2, RentMode::PerBed, UnitStatus::Maintenance);
        $b201 = $this->unit($hillside, 'B201', 4, RentMode::Whole);
        $this->unit($hillside, 'B202', 2, RentMode::Whole);

        $beds = fn (Unit $unit) => $unit->beds()->orderBy('id')->get();

        // [name, email, rent in pesos, due day]
        $bedRentals = [
            [$a101, 0, 'Ana Demo', 'ana.demo@example.test', 2500, 5],
            [$a101, 1, 'Ben Demo', 'ben.demo@example.test', 2700, 12],
            [$a101, 2, 'Cara Demo', null, 2400, 18],
            [$a101, 3, 'Dan Demo', 'dan.demo@example.test', 3000, 28],
            [$a102, 0, 'Eli Demo', 'eli.demo@example.test', 2600, 15],
        ];

        $agreements = [];
        foreach ($bedRentals as [$unit, $bedIndex, $name, $email, $pesos, $dueDay]) {
            $tenant = $this->tenant($unit->property, $name, $email);
            $agreements[$name] = $tenancy->startAgreement($tenant, $unit, $beds($unit)[$bedIndex], $pesos * 100, $dueDay, $start);
        }

        $payer = $this->tenant($hillside, 'Santos Family Demo', 'santos.demo@example.test');
        $residents = collect(['Maria Santos Demo', 'Pedro Santos Demo', 'Lita Santos Demo'])
            ->map(fn ($name) => $this->tenant($hillside, $name, null));
        $tenancy->startAgreement($payer, $b201, null, 900000, 10, $start, ['resident_tenant_ids' => $residents->pluck('id')->all()]);

        $this->billingData($agreements['Ana Demo'], $agreements['Ben Demo'], $a101, $b201);
    }

    /** Things the November 2026 bills need: an add-on, an old debt and the month's utility figures. Nothing is invoiced. */
    private function billingData(RentalAgreement $ana, RentalAgreement $ben, Unit $a101, Unit $b201): void
    {
        $ana->recurringCharges()->create([
            'charge_category_id' => ChargeCategory::where('kind', 'appliance')->orderBy('id')->value('id'),
            'label' => 'Rice cooker',
            'amount_cents' => 20000,
            'start_date' => '2026-11-01',
        ]);

        $openings = app(OpeningBalanceService::class);
        $openings->approve($openings->draft($ben->billingAccount, 180000, Carbon::parse('2026-10-31'), null, 'Demo balance carried over from the old notebook.'));

        $month = '2026-11-01';
        foreach ([['electricity', 480000], ['water', 120000]] as [$type, $cents]) {
            UnitUtility::create(['unit_id' => $a101->id, 'type' => $type, 'mode' => 'shared', 'split_method' => 'equal']);
            UtilityBill::create(['unit_id' => $a101->id, 'type' => $type, 'bill_month' => $month, 'amount_cents' => $cents, 'status' => 'draft']);
        }

        // 1,000 kWh used at P11.5432 each.
        UnitUtility::create(['unit_id' => $b201->id, 'type' => 'electricity', 'mode' => 'metered', 'rate_e4' => 115432, 'base_charge_cents' => 0]);
        UtilityBill::create([
            'unit_id' => $b201->id, 'type' => 'electricity', 'bill_month' => $month, 'status' => 'draft',
            'previous_reading_e3' => 5000000, 'current_reading_e3' => 6000000, 'rate_e4' => 115432,
            'base_charge_cents' => 0, 'amount_cents' => 115432 * 1000 / 100,
            'reading_date' => '2026-10-31',
        ]);
    }

    private function unit(Property $property, string $code, int $capacity, RentMode $mode, UnitStatus $status = UnitStatus::Available): Unit
    {
        $unit = $property->units()->create([
            'code' => $code, 'capacity' => $capacity, 'rent_mode' => $mode, 'status' => $status,
        ]);

        // Same rule as UnitController: a per-bed room has exactly `capacity` beds, Bed 1..N.
        if ($mode === RentMode::PerBed) {
            foreach (range(1, $capacity) as $n) {
                $unit->beds()->create(['label' => "Bed $n"]);
            }
        }

        return $unit;
    }

    private function tenant(Property $property, string $name, ?string $email): Tenant
    {
        return $property->tenants()->create([
            'full_name' => $name,
            'email' => $email,
            'mobile' => '0917'.fake('en_PH')->numerify('#######'),
            'preferred_delivery' => DeliveryMethod::Email,
            'email_opt_in' => $email !== null,
            'remarks' => 'Demo data',
        ]);
    }
}
