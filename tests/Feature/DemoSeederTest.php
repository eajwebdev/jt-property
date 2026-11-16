<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Models\UtilityBill;
use App\Services\Billing\ReceivableLedgerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_is_seeded(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Property::count());

        $a101 = Unit::where('code', 'A101')->firstOrFail();
        $this->assertSame(4, $a101->beds()->count());
        $agreements = $a101->agreements()->get();
        $this->assertCount(4, $agreements);
        $this->assertEqualsCanonicalizing([5, 12, 18, 28], $agreements->pluck('due_day')->all());
        $this->assertCount(4, $agreements->map(fn ($a) => $a->rentOn('2026-09-01'))->unique());
        $this->assertNull(Tenant::where('full_name', 'like', 'Cara%')->firstOrFail()->email);

        $b201 = Unit::where('code', 'B201')->firstOrFail();
        $this->assertSame(1, $b201->agreements()->count());
        $this->assertSame(4, $b201->occupancies()->count());
        $this->assertSame(900000, $b201->agreements()->first()->rentOn('2026-09-01'));
        $this->assertSame(0, Unit::where('code', 'B202')->firstOrFail()->agreements()->count());
        $this->assertSame('maintenance', Unit::where('code', 'A103')->firstOrFail()->displayStatus());
        $this->assertSame(0, RentalAgreement::whereNull('unit_id')->count());
    }

    public function test_santos_payer_and_residents_are_linked_to_b201(): void
    {
        $this->seed(DatabaseSeeder::class);

        $b201 = Unit::where('code', 'B201')->firstOrFail();
        $payer = $b201->occupancies()->whereHas('tenant', fn ($q) => $q->where('full_name', 'Santos Family Demo'))->firstOrFail();
        $this->assertTrue($payer->is_payer);
        $this->assertSame(3, $b201->occupancies()->where('is_payer', false)->count());
        $this->assertSame(1, $b201->occupancies()->pluck('rental_agreement_id')->unique()->count());
    }

    public function test_demo_billing_data_is_seeded_but_nothing_is_invoiced(): void
    {
        $this->seed(DatabaseSeeder::class);

        $ana = RentalAgreement::whereHas('billingAccount.payer', fn ($q) => $q->where('full_name', 'Ana Demo'))->firstOrFail();
        $rice = $ana->recurringCharges()->firstOrFail();
        $this->assertSame(['Rice cooker', 20000, '2026-11-01'], [$rice->label, $rice->amount_cents, $rice->start_date->toDateString()]);

        $ben = BillingAccount::whereHas('payer', fn ($q) => $q->where('full_name', 'Ben Demo'))->firstOrFail();
        $this->assertSame(180000, app(ReceivableLedgerService::class)->balance($ben));
        $this->assertSame('approved', OpeningBalance::where('billing_account_id', $ben->id)->firstOrFail()->status);

        $a101 = Unit::where('code', 'A101')->firstOrFail();
        $this->assertSame(['shared', 'shared'], UnitUtility::where('unit_id', $a101->id)->orderBy('type')->pluck('mode')->all());
        $this->assertSame(2, UtilityBill::where('unit_id', $a101->id)->count());
        $this->assertSame('metered', UnitUtility::where('unit_id', Unit::where('code', 'B201')->value('id'))->where('type', 'electricity')->value('mode'));
        $this->assertSame(1, UtilityBill::where('unit_id', Unit::where('code', 'B201')->value('id'))->count());
        $this->assertSame(0, Invoice::count());
    }

    public function test_staff_can_access_riverside_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $staff = User::where('email', 'staff@jt.test')->firstOrFail();

        $this->assertTrue($staff->canAccessProperty(Property::where('code', 'RIV')->firstOrFail()));
        $this->assertFalse($staff->canAccessProperty(Property::where('code', 'HIL')->firstOrFail()));
    }

    public function test_seeding_twice_does_not_duplicate(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Property::count());
        $this->assertSame(6, RentalAgreement::count());
    }
}
