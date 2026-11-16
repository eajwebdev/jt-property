<?php

namespace Tests\Feature\Billing;

use App\Enums\DeliveryMethod;
use App\Enums\RentMode;
use App\Jobs\SendStatementEmailJob;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Models\UtilityBill;
use App\Services\Billing\InvoiceGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LateUtilityAndEmailTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Unit $unit;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-12-10 09:00', 'Asia/Manila'));
        $this->property = Property::factory()->create();
        $this->unit = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::PerBed, 'capacity' => 2]);
        UnitUtility::create(['unit_id' => $this->unit->id, 'type' => 'electricity', 'mode' => 'shared', 'split_method' => 'equal']);
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
    }

    private function agreement(string $name, ?string $email = null): RentalAgreement
    {
        $payer = Tenant::factory()->create([
            'property_id' => $this->property->id, 'full_name' => $name, 'email' => $email,
            'preferred_delivery' => $email ? DeliveryMethod::Email : DeliveryMethod::Print, 'email_opt_in' => $email !== null,
        ]);
        $account = BillingAccount::factory()->for($this->property)->create(['payer_tenant_id' => $payer->id]);
        $agreement = RentalAgreement::factory()->for($account, 'billingAccount')->for($this->unit)->create(['start_date' => '2026-01-01']);
        RentRate::create(['rental_agreement_id' => $agreement->id, 'rent_cents' => 70000, 'effective_from' => '2026-01-01']);

        return $agreement;
    }

    private function bill(RentalAgreement $a, int $month): Invoice
    {
        return Invoice::where('rental_agreement_id', $a->id)->whereMonth('period_start', $month)->sole();
    }

    public function test_bill_goes_out_without_electricity_and_late_electricity_lands_on_the_next_bill_once(): void
    {
        $ana = $this->agreement('Ana');
        $ben = $this->agreement('Ben');
        $billing = app(InvoiceGenerationService::class);

        // November: no electric bill entered yet. The bills are still made, with a "not yet entered" line.
        $billing->generate(2026, 11, $this->owner);
        $november = $this->bill($ana, 11);
        $this->assertSame(70000, $november->current_charges_cents);
        $this->assertSame(['Monthly Rental', 'Electricity – not yet entered (will be added to a later bill)'], $november->lineItems->pluck('label')->all());
        $this->assertSame([70000, 0], $november->lineItems->pluck('amount_cents')->all());

        // The owner enters November's electric bill late (after the November bills went out).
        UtilityBill::create(['unit_id' => $this->unit->id, 'type' => 'electricity', 'bill_month' => '2026-11-01', 'amount_cents' => 100001, 'status' => 'draft']);
        UtilityBill::create(['unit_id' => $this->unit->id, 'type' => 'electricity', 'bill_month' => '2026-12-01', 'amount_cents' => 80000, 'status' => 'draft']);

        // December: December electricity + November's late electricity, split by who lived there in November.
        $billing->generate(2026, 12, $this->owner);
        $anaDec = $this->bill($ana, 12);
        $benDec = $this->bill($ben, 12);
        $this->assertContains('Electricity – November 2026 (entered late)', $anaDec->lineItems->pluck('label')->all());
        $late = fn (Invoice $i) => (int) $i->lineItems->firstWhere('label', 'Electricity – November 2026 (entered late)')->amount_cents;
        $this->assertSame(100001, $late($anaDec) + $late($benDec)); // to the centavo, once
        $this->assertSame(70000 + 40000 + $late($anaDec), $anaDec->current_charges_cents);
        $this->assertSame('billed', UtilityBill::whereDate('bill_month', '2026-11-01')->value('status'));

        // January: November is not charged again.
        $this->travelTo(Carbon::parse('2027-01-10 09:00', 'Asia/Manila'));
        $billing->generate(2027, 1, $this->owner);
        $this->assertNotContains('Electricity – November 2026 (entered late)', Invoice::where('rental_agreement_id', $ana->id)->whereYear('period_start', 2027)->sole()->lineItems->pluck('label')->all());
    }

    public function test_send_all_emails_each_generated_statement_once_and_one_bill_can_be_sent_again(): void
    {
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $ana = $this->agreement('Ana', 'ana@example.test');
        $this->agreement('Ben', 'ben@example.test');
        $this->agreement('Cara'); // print delivery
        app(InvoiceGenerationService::class)->generate(2026, 12, $this->owner);

        $this->post(route('billing.email-month'), ['year' => 2026, 'month' => 12])
            ->assertSessionHas('success', '2 statement emails queued. 0 were already emailed (use Send again on a bill to resend). 1 skipped (no email address or print delivery).');
        Queue::assertPushed(SendStatementEmailJob::class, 2);

        // Pressing it again does not email anyone twice.
        $this->post(route('billing.email-month'), ['year' => 2026, 'month' => 12])
            ->assertSessionHas('success', '0 statement emails queued. 2 were already emailed (use Send again on a bill to resend). 1 skipped (no email address or print delivery).');
        Queue::assertPushed(SendStatementEmailJob::class, 2);

        // One by one: send a single statement again.
        $this->post(route('invoices.email', $this->bill($ana, 12)), ['resend' => 1])->assertSessionHas('success');
        Queue::assertPushed(SendStatementEmailJob::class, 3);

        // Staff cannot mass-email.
        $staff = User::factory()->create();
        $this->actingAs($staff)->post(route('billing.email-month'), ['year' => 2026, 'month' => 12])->assertForbidden();
    }
}
