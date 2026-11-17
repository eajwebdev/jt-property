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
use App\Models\UtilityBill;
use App\Services\Billing\BillingPreviewService;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\ReceivableLedgerService;
use App\Services\Billing\StatementOfAccountsReport;
use Database\Seeders\MubarakBoardingHouseSeeder;
use Database\Seeders\MubarakPreviousBoardersSeeder;
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

        // People: exactly the sheet's 61 "Current Boarders" (the sheet has no previous-boarders section).
        $this->assertSame(61, Tenant::where('property_id', $property->id)->count());
        $this->assertSame(0, Tenant::where('property_id', $property->id)->whereNotNull('archived_at')->count());
        $this->assertSame(61, RentalAgreement::count()); // RALO, Lhougan included: a ₱0 free stay (student assistant)

        // The printed sheet, row by row, = database/seeders/data/mubarak-statement-2026-10.json (checked against the .xlsx).
        $sheet = json_decode((string) file_get_contents(database_path('seeders/data/mubarak-statement-2026-10.json')), true);
        $report = app(StatementOfAccountsReport::class)->build($property, Carbon::parse('2026-10-05'));
        $current = $report['sections']['Current Boarders']->values();
        $this->assertSame(['Current Boarders'], array_keys($report['sections']));
        $this->assertCount(61, $current);
        foreach ($sheet as $i => $paper) {
            $line = $current[$i];
            $no = ($i + 1).'. '.$paper['name'];
            $this->assertSame($paper['name'], $line['name'], $no);
            $this->assertSame($paper['building'].' - '.$paper['room'], $line['room'], $no);
            $this->assertSame($paper['rate_cents'], $line['rate_cents'], $no);
            $this->assertSame($paper['rent_cents'], $line['columns']['rent'], $no);
            $this->assertSame($paper['electric_cents'], $line['columns']['electric'], $no);
            $this->assertSame(0, $line['columns']['advance'] + $line['columns']['deposit'] + $line['columns']['key'] + $line['columns']['others'], $no);
            $this->assertSame($paper['total_cents'], $line['total_cents'], $no);
            $this->assertSame($paper['remark'], $line['remarks'], $no);
        }
        // GRAND TOTAL: rate 54,800.00 · monthly due 99,099.26 · electric 8,333.55 · total 107,432.81.
        $this->assertSame(5480000, $report['totals']['rate']);
        $this->assertSame(9909926, $report['totals']['rent']);
        $this->assertSame(833355, $report['totals']['electric']);
        $this->assertSame(10743281, $report['totals']['total']);

        // The paper's remarks belong to October 2026; later sheets write their own.
        $later = app(StatementOfAccountsReport::class)->build($property, Carbon::parse('2026-11-05'))['sections']['Current Boarders'];
        $this->assertNotSame('x', $later->firstWhere('name', 'VILLACAMPA, Joven Rey Cabunson')['remarks']);

        // A free stay pays no rent and no share of the room's electric bill (BIND House Room #2 is split 3 ways).
        $bind2 = Unit::where('building', 'BIND House')->where('code', 'Room #2')->sole();
        UtilityBill::create(['unit_id' => $bind2->id, 'type' => 'electricity', 'bill_month' => '2026-11-01', 'amount_cents' => 30000, 'status' => 'draft']);
        $shares = collect(app(BillingPreviewService::class)->preview(2026, 11, $property->id, null, $owner)['rows'])
            ->filter(fn ($r) => str_starts_with($r->unitLabel, 'BIND House - Room #2'))
            ->mapWithKeys(fn ($r) => [$r->tenantLabel => collect($r->lines)->where('category', 'utility')->sum('amount_cents')]);
        $this->assertSame(0, $shares['RALO, Lhougan Daquiaog']);
        $this->assertSame([10000, 10000, 10000], $shares->except('RALO, Lhougan Daquiaog')->values()->all());
        UtilityBill::query()->delete();

        // October = the first system bills: rent + the September electricity as one-time charges.
        $this->assertSame(61, Invoice::whereDate('period_start', '2026-10-01')->count());
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

    public function test_previous_boarders_are_a_separate_optional_seed(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        User::factory()->owner()->create();
        $this->seed(MubarakBoardingHouseSeeder::class);
        $this->seed(MubarakPreviousBoardersSeeder::class);
        $this->seed(MubarakPreviousBoardersSeeder::class); // safe to run twice

        // 11 old accounts (BH 6,461.15 + EB 781.56 = 7,242.71); SHANGCO is also a current boarder (one person, two accounts).
        $property = Property::where('code', 'MBH')->sole();
        $previous = BillingAccount::where('property_id', $property->id)->whereDoesntHave('agreements')->get();
        $this->assertCount(11, $previous);
        $ledger = app(ReceivableLedgerService::class);
        $this->assertSame(646115 + 78156, $previous->sum(fn ($a) => $ledger->balance($a)));
        $this->assertSame(2, BillingAccount::whereHas('payer', fn ($q) => $q->where('full_name', 'SHANGCO, Windel Gasapo'))->count());
        // Not on the printed sheet: it lists current boarders only.
        $report = app(StatementOfAccountsReport::class)->build($property, Carbon::parse('2026-10-05'));
        $this->assertCount(61, $report['sections']['Current Boarders']);
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
