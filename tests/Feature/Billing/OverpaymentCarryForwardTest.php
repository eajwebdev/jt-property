<?php

namespace Tests\Feature\Billing;

use App\Enums\RentMode;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingPreviewService;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\Statement;
use App\Services\Billing\StatementComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The owner's question, end to end through the real billing engine:
 * ₱700 a month, October statement says ₱1,400 (September unpaid + October), the boarder pays ₱1,700.
 * The extra ₱300 must come off November: November total due = ₱700 - ₱300 = ₱400.
 */
class OverpaymentCarryForwardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private BillingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 09:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);

        $property = Property::factory()->create();
        $unit = Unit::factory()->for($property)->create(['rent_mode' => RentMode::Whole, 'capacity' => 1]);
        $payer = Tenant::factory()->create(['property_id' => $property->id]);
        $this->account = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $payer->id]);
        $agreement = RentalAgreement::factory()->for($this->account, 'billingAccount')->for($unit)->create(['start_date' => '2026-09-01']);
        RentRate::create(['rental_agreement_id' => $agreement->id, 'rent_cents' => 70000, 'effective_from' => '2026-09-01']);

        // September is never paid; October is billed on top of it.
        $this->bill(2026, 9);
        $this->bill(2026, 10);
        $this->assertSame(140000, $this->statement(10)->totalDueCents, 'October statement must say ₱1,400');
    }

    private function bill(int $year, int $month): void
    {
        app(InvoiceGenerationService::class)->generate($year, $month, $this->owner, null, null, [$this->account->id]);
    }

    private function pay(int $cents, string $on): void
    {
        app(PaymentService::class)->record($this->account, ['amount_cents' => $cents, 'paid_on' => $on, 'method' => 'cash'], $this->owner);
    }

    private function statement(int $month): Statement
    {
        $invoice = Invoice::where('billing_account_id', $this->account->id)->whereMonth('period_start', $month)->where('status', 'finalized')->firstOrFail();

        return app(StatementComposer::class)->forInvoice($invoice);
    }

    private function novemberPreviewTotal(): int
    {
        return app(BillingPreviewService::class)->preview(2026, 11, null, null, $this->owner, [$this->account->id])['rows'][0]->estimatedTotalCents;
    }

    public function test_paying_1700_in_october_makes_the_november_bill_400(): void
    {
        $this->pay(170000, '2026-10-10');
        $this->assertSame(40000, $this->novemberPreviewTotal(), 'preview before generating');

        $this->bill(2026, 11);
        $november = $this->statement(11);

        $this->assertSame(-30000, $november->previousCents, 'the ₱300 extra shows as a credit brought forward');
        $this->assertSame(70000, $november->currentCents);
        $this->assertSame(40000, $november->totalDueCents);
    }

    public function test_paying_1700_in_november_before_the_bill_is_made_also_gives_400(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 09:00', 'Asia/Manila'));
        $this->pay(170000, '2026-11-03');
        $this->assertSame(40000, $this->novemberPreviewTotal());

        $this->bill(2026, 11);

        $this->assertSame(40000, $this->statement(11)->totalDueCents);
    }

    public function test_paying_1700_after_the_november_bill_was_made_also_gives_400(): void
    {
        $this->bill(2026, 11);
        $this->travelTo(Carbon::parse('2026-11-17 09:00', 'Asia/Manila'));
        $this->pay(170000, '2026-11-17');

        $november = $this->statement(11);
        $this->assertSame(140000, $november->previousCents);
        $this->assertSame(-170000, $november->paymentsSinceCents);
        $this->assertSame(40000, $november->totalDueCents);
    }

    public function test_paying_more_than_a_whole_month_leaves_credit_for_the_month_after(): void
    {
        $this->pay(250000, '2026-10-10'); // ₱1,100 extra: covers November (₱700) and leaves ₱400

        $this->bill(2026, 11);
        $this->assertSame(-40000, $this->statement(11)->totalDueCents, 'November is fully covered, ₱400 credit left');

        $this->bill(2026, 12);
        $this->assertSame(30000, $this->statement(12)->totalDueCents, 'December: ₱700 - ₱400 credit = ₱300');
    }
}
