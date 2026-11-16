<?php

namespace Tests\Feature\Billing;

use App\Enums\RentMode;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\OneTimeCharge;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\InvoiceGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BillingElectricityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private RentalAgreement $ana;

    private RentalAgreement $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
        $property = Property::factory()->create();
        $unit = Unit::factory()->for($property)->create(['rent_mode' => RentMode::PerBed, 'capacity' => 2]);
        [$this->ana, $this->ben] = collect(['Ana', 'Ben'])->map(function (string $name) use ($property, $unit) {
            $payer = Tenant::factory()->create(['property_id' => $property->id, 'full_name' => $name]);
            $account = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $payer->id]);
            $agreement = RentalAgreement::factory()->for($account, 'billingAccount')->for($unit)->create(['start_date' => '2026-01-01']);
            RentRate::create(['rental_agreement_id' => $agreement->id, 'rent_cents' => 70000, 'effective_from' => '2026-01-01']);

            return $agreement;
        })->all();
        $this->actingAs($this->owner);
    }

    private function rows(): array
    {
        return collect($this->get(route('billing.index', ['year' => 2026, 'month' => 11]))->viewData('page')['props']['rows'])->keyBy('tenant')->all();
    }

    public function test_electricity_typed_before_generating_goes_on_this_months_bill(): void
    {
        $this->assertSame('missing', $this->rows()['Ana']['electricity']);

        $this->post(route('billing.electricity'), ['rental_agreement_id' => $this->ana->id, 'year' => 2026, 'month' => 11, 'amount' => '72.23'])->assertSessionHasNoErrors();
        // Typed again before billing: the amount is replaced, not doubled.
        $this->post(route('billing.electricity'), ['rental_agreement_id' => $this->ana->id, 'year' => 2026, 'month' => 11, 'amount' => '72.50'])->assertSessionHasNoErrors();
        $this->assertSame(1, OneTimeCharge::count());

        $rows = $this->rows();
        $this->assertSame('on_bill', $rows['Ana']['electricity']);
        $this->assertSame(7250, $rows['Ana']['electricity_cents']);
        $this->assertSame('missing', $rows['Ben']['electricity']);

        // Generate takes every active tenant (no picking).
        $this->post(route('billing.generate'), ['year' => 2026, 'month' => 11])->assertRedirect();
        $this->assertSame(2, Invoice::count());
        $ana = Invoice::where('rental_agreement_id', $this->ana->id)->sole();
        $this->assertSame(['Monthly Rental', 'Electricity – October 2026'], $ana->lineItems->pluck('label')->all());
        $this->assertSame(77250, $ana->current_charges_cents);
    }

    public function test_electricity_typed_after_the_bill_goes_on_the_next_bill(): void
    {
        app(InvoiceGenerationService::class)->generate(2026, 11, $this->owner);

        $this->post(route('billing.electricity'), ['rental_agreement_id' => $this->ben->id, 'year' => 2026, 'month' => 11, 'amount' => '84.80'])
            ->assertSessionHas('success', '₱84.80 Electricity – October 2026 (entered late) will be on the next bill.');
        $this->assertSame('next_bill', $this->rows()['Ben']['electricity']);
        $this->assertSame(70000, Invoice::where('rental_agreement_id', $this->ben->id)->sole()->current_charges_cents); // the made bill is not changed

        $this->travelTo(Carbon::parse('2026-12-10 09:00', 'Asia/Manila'));
        app(InvoiceGenerationService::class)->generate(2026, 12, $this->owner);
        $december = Invoice::where('rental_agreement_id', $this->ben->id)->whereMonth('period_start', 12)->sole();
        $this->assertContains('Electricity – October 2026 (entered late)', $december->lineItems->pluck('label')->all());
        $this->assertSame(70000 + 8480, $december->current_charges_cents);

        // Already on a bill: cannot be typed over.
        $this->post(route('billing.electricity'), ['rental_agreement_id' => $this->ben->id, 'year' => 2026, 'month' => 11, 'amount' => '90'])->assertSessionHasErrors('amount');
    }

    public function test_only_the_owner_enters_electricity_and_amounts_are_validated(): void
    {
        $this->post(route('billing.electricity'), ['rental_agreement_id' => $this->ana->id, 'year' => 2026, 'month' => 11, 'amount' => '0'])->assertSessionHasErrors('amount');
        $this->post(route('billing.electricity'), ['rental_agreement_id' => $this->ana->id, 'year' => 2026, 'month' => 11, 'amount' => 'abc'])->assertSessionHasErrors('amount');
        $this->actingAs(User::factory()->create())->post(route('billing.electricity'), ['rental_agreement_id' => $this->ana->id, 'year' => 2026, 'month' => 11, 'amount' => '10'])->assertForbidden();
        $this->assertSame(0, OneTimeCharge::count());
    }
}
