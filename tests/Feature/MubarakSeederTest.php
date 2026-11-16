<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\OneTimeCharge;
use App\Models\OpeningBalance;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\SecurityDeposit;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingPreviewService;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\ReceivableLedgerService;
use App\Services\Billing\StatementOfAccountsReport;
use Database\Seeders\MubarakBoardingHouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MubarakSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_matches_the_october_2026_statement_of_accounts_sheet(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $owner = User::factory()->owner()->create();
        $this->seed(MubarakBoardingHouseSeeder::class);
        $this->seed(MubarakBoardingHouseSeeder::class); // safe to run twice

        $property = Property::where('code', 'MBH')->sole();
        $this->assertSame('1366594', $property->dti_registration_no);
        $this->assertSame(27, Unit::where('property_id', $property->id)->count());
        $this->assertSame(2, Unit::where('property_id', $property->id)->where('code', 'Room #2')->whereIn('building', ['BIND House', 'Creekside House'])->count());

        // People: the sheet's 72 rows = 61 current + 11 previous; SHANGCO is on both lists (one person, two accounts).
        $this->assertSame(71, Tenant::where('property_id', $property->id)->count());
        $this->assertSame(61, Tenant::where('property_id', $property->id)->whereNull('archived_at')->count());
        $this->assertSame(10, Tenant::where('property_id', $property->id)->whereNotNull('archived_at')->count());
        $this->assertSame(2, BillingAccount::whereHas('payer', fn ($q) => $q->where('full_name', 'SHANGCO, Windel Gasapo'))->count());
        $this->assertSame(60, RentalAgreement::count()); // RALO, Lhougan: free stay, no rental

        // Current boarders = the sheet's Current Boarders Sub-Total (rate 54,800 · BH 99,099.26 · EB 8,333.55 · 107,432.81).
        $report = app(StatementOfAccountsReport::class)->build($property, Carbon::parse('2026-10-05'));
        $current = $report['sections']['Current Boarders'];
        $this->assertCount(60, $current);
        $this->assertSame(5480000, $current->sum('rate_cents'));
        $this->assertSame(9909926, $current->sum('columns.rent'));
        $this->assertSame(833355, $current->sum('columns.electric'));
        $this->assertSame(10743281, $report['totals']['total']);
        $row = fn (string $name) => $current->firstWhere('name', $name);
        $this->assertSame('Creekside House - Room #5 · Bed 1', $row('ACABAL, Praise Rodriguez')['room']);
        $this->assertSame('October 2026 BH & September 2026 EB accounts', $row('ACABAL, Praise Rodriguez')['remarks']);
        $this->assertSame('Balance of September + October 2026 BH accounts (no deposit)', $row('BACONGCO, Jundil & Trixie Mae Alyawan')['remarks']);
        $this->assertSame(8480, $row('SHANGCO, Windel Gasapo')['total_cents']);
        $this->assertSame(1407699, $row('SERILLA, Joevani Taberos')['total_cents']);

        // Previous boarders with accounts: every row of the sheet (BH 6,461.15 + EB 781.56 = 7,242.71) is owed on its account.
        $previous = BillingAccount::where('property_id', $property->id)->whereDoesntHave('agreements')->get();
        $this->assertCount(11, $previous);
        $ledger = app(ReceivableLedgerService::class);
        $this->assertSame(646115 + 78156, $previous->sum(fn ($a) => $ledger->balance($a)));
        $this->assertSame(248166, $ledger->balance($previous->first(fn ($a) => $a->payer->full_name === 'SHANGCO, Windel Gasapo')));

        // October = the first system bills: rent + the September electricity as one-time charges.
        $this->assertSame(60, Invoice::whereDate('period_start', '2026-10-01')->count());
        $this->assertSame(OneTimeCharge::count(), OneTimeCharge::whereNotNull('invoice_id')->count());
        $acabal = Invoice::whereHas('billingAccount.payer', fn ($q) => $q->where('full_name', 'ACABAL, Praise Rodriguez'))->sole();
        $this->assertSame(['Monthly Rental', 'Electricity – September 2026'], $acabal->lineItems->pluck('label')->all());
        $this->assertSame(77223, $acabal->current_charges_cents);
        $this->assertSame(['2026-09-30'], OpeningBalance::pluck('as_of')->map->toDateString()->unique()->values()->all());

        // Deposits only for boarders the sheet does not mark "(no deposit)".
        $this->assertFalse(SecurityDeposit::whereHas('billingAccount.payer', fn ($q) => $q->where('full_name', 'ALYAWAN, Joy Panlaan'))->exists());
        $this->assertTrue(SecurityDeposit::whereHas('billingAccount.payer', fn ($q) => $q->where('full_name', 'ACABAL, Praise Rodriguez'))->where('amount_cents', 70000)->exists());

        // November: unpaid October + previous unpaid is the previous balance; October cannot be billed twice.
        $preview = collect(app(BillingPreviewService::class)->preview(2026, 11, $property->id, null, $owner)['rows'])->keyBy('tenantLabel');
        $this->assertSame(77223, $preview['ACABAL, Praise Rodriguez']->previousBalanceCents);
        $this->assertSame(161026, $preview['ALIPO-ON, Erika Dinaguit']->previousBalanceCents);
        $again = app(InvoiceGenerationService::class)->generate(2026, 10, $owner, $property->id);
        $this->assertSame(0, $again->items()->where('status', 'generated')->count());
    }

    public function test_room_numbers_are_unique_per_building(): void
    {
        $owner = User::factory()->owner()->create();
        $property = Property::factory()->create();
        $this->actingAs($owner);
        $room = ['code' => 'Room #2', 'capacity' => 2, 'rent_mode' => 'per_bed', 'status' => 'available'];

        $this->post(route('properties.units.store', $property), $room + ['building' => 'Creekside House'])->assertSessionHasNoErrors();
        $this->post(route('properties.units.store', $property), $room + ['building' => 'BIND House'])->assertSessionHasNoErrors();
        $this->post(route('properties.units.store', $property), $room + ['building' => 'BIND House'])->assertSessionHasErrors('code');
        $this->post(route('properties.units.store', $property), $room)->assertSessionHasNoErrors();
        $this->post(route('properties.units.store', $property), $room)->assertSessionHasErrors('code'); // no building still clashes with no building
        $this->assertSame('BIND House - ROOM #2', Unit::where('building', 'BIND House')->sole()->label);
        $this->assertSame('ROOM #2', Unit::whereNull('building')->sole()->label);
    }
}
