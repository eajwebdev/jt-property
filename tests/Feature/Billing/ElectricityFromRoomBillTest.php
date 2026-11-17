<?php

namespace Tests\Feature\Billing;

use App\Enums\RentMode;
use App\Enums\UnitStatus;
use App\Models\Bed;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Services\Billing\InvoiceGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Electricity comes from ONE place: the room's bill on the Electricity screen, split between the boarders
 * of the room. ₱1,000 for BIND House Room #1 with 4 boarders = ₱250 each.
 */
class ElectricityFromRoomBillTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Property $property;

    private Unit $room;

    /** @var list<RentalAgreement> */
    private array $boarders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-03 09:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        $this->property = Property::factory()->create();

        $this->post(route('properties.units.store', $this->property), [
            'code' => 'Room #1', 'building' => 'BIND House', 'capacity' => 4,
            'rent_mode' => RentMode::PerBed->value, 'status' => UnitStatus::Available->value,
        ])->assertSessionHasNoErrors();
        $this->room = Unit::sole();

        foreach (Bed::where('unit_id', $this->room->id)->orderBy('id')->get() as $i => $bed) {
            $payer = Tenant::factory()->create(['property_id' => $this->property->id, 'full_name' => "Boarder $i"]);
            $account = BillingAccount::factory()->for($this->property)->create(['payer_tenant_id' => $payer->id]);
            $agreement = RentalAgreement::factory()->for($account, 'billingAccount')->for($this->room)
                ->create(['bed_id' => $bed->id, 'start_date' => '2026-09-01']);
            RentRate::create(['rental_agreement_id' => $agreement->id, 'rent_cents' => 70000, 'effective_from' => '2026-09-01']);
            $this->boarders[] = $agreement;
        }
    }

    private function enterRoomBill(string $amount, string $month = '2026-11'): void
    {
        $this->post(route('utility-bills.store'), ['unit_id' => $this->room->id, 'type' => 'electricity', 'bill_month' => $month, 'amount' => $amount])
            ->assertSessionHasNoErrors();
    }

    /** @return array<int, array<string, mixed>> billing screen rows */
    private function rows(int $month = 11): array
    {
        return $this->get(route('billing.index', ['year' => 2026, 'month' => $month]))->viewData('page')['props']['rows'];
    }

    public function test_new_rooms_default_to_a_shared_master_bill_split_equally(): void
    {
        $config = UnitUtility::where('unit_id', $this->room->id)->where('type', 'electricity')->sole();

        $this->assertSame('shared', $config->mode);
        $this->assertSame('equal', $config->split_method);
    }

    public function test_room_bill_of_1000_is_split_250_each_between_4_boarders(): void
    {
        $this->enterRoomBill('1,000.00');

        foreach ($this->rows() as $row) {
            $this->assertSame('on_bill', $row['electricity'], $row['tenant']);
            $this->assertSame(25000, $row['electricity_cents'], $row['tenant']);
        }

        app(InvoiceGenerationService::class)->generate(2026, 11, $this->owner);
        foreach (Invoice::with('lineItems')->get() as $invoice) {
            $electricity = $invoice->lineItems->filter(fn ($l) => str_starts_with($l->label, 'Electricity'));
            $this->assertSame([25000], $electricity->pluck('amount_cents')->values()->all());
            $this->assertSame(70000 + 25000, $invoice->current_charges_cents);
        }
    }

    public function test_an_uneven_amount_is_split_to_the_centavo_without_losing_any(): void
    {
        $this->enterRoomBill('1,000.03');

        $shares = array_column($this->rows(), 'electricity_cents');
        $this->assertSame(100003, array_sum($shares));
        $this->assertLessThanOrEqual(1, max($shares) - min($shares));
    }

    public function test_before_the_room_bill_is_entered_every_boarder_shows_no_electric_bill_yet(): void
    {
        foreach ($this->rows() as $row) {
            $this->assertSame('missing', $row['electricity']);
        }
    }

    public function test_room_bill_entered_after_billing_goes_on_the_next_bill_once(): void
    {
        app(InvoiceGenerationService::class)->generate(2026, 11, $this->owner);
        $this->enterRoomBill('1,000');

        foreach ($this->rows() as $row) {
            $this->assertSame('next_bill', $row['electricity']);
            $this->assertSame(25000, $row['electricity_cents']);
        }

        $this->travelTo(Carbon::parse('2026-12-03 09:00', 'Asia/Manila'));
        app(InvoiceGenerationService::class)->generate(2026, 12, $this->owner);
        foreach (Invoice::with('lineItems')->whereMonth('period_start', 12)->get() as $december) {
            $late = $december->lineItems->filter(fn ($l) => str_contains($l->label, 'November 2026 (entered late)'));
            $this->assertSame([25000], $late->pluck('amount_cents')->values()->all());
        }
    }

    public function test_room_included_in_rent_needs_no_electricity(): void
    {
        UnitUtility::where('unit_id', $this->room->id)->update(['mode' => 'inclusive']);

        foreach ($this->rows() as $row) {
            $this->assertSame('included', $row['electricity']);
        }
    }

    public function test_the_per_boarder_electricity_entry_on_the_bills_screen_is_gone(): void
    {
        $this->assertFalse(Route::has('billing.electricity'), 'only the room bill can add electricity, so nobody is charged twice');
    }
}
