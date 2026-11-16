<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
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
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingMonthLockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private RentalAgreement $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-12-10 09:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
        $property = Property::factory()->create();
        $unit = Unit::factory()->for($property)->create(['rent_mode' => RentMode::Whole]);
        $payer = Tenant::factory()->create(['property_id' => $property->id, 'full_name' => 'Ana']);
        $account = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $payer->id]);
        $this->ana = RentalAgreement::factory()->for($account, 'billingAccount')->for($unit)->create(['start_date' => '2026-01-01']);
        RentRate::create(['rental_agreement_id' => $this->ana->id, 'rent_cents' => 70000, 'effective_from' => '2026-01-01']);
        $this->actingAs($this->owner);
    }

    private function bill(int $month): Invoice
    {
        return Invoice::where('rental_agreement_id', $this->ana->id)->whereMonth('period_start', $month)->where('status', InvoiceStatus::Finalized)->sole();
    }

    public function test_an_older_month_is_closed_and_the_latest_month_can_be_deleted_and_billed_again(): void
    {
        $billing = app(InvoiceGenerationService::class);
        $billing->generate(2026, 11, $this->owner);
        // December electricity, entered before December is billed.
        $this->post(route('billing.electricity'), ['rental_agreement_id' => $this->ana->id, 'year' => 2026, 'month' => 12, 'amount' => '84.80'])->assertSessionHasNoErrors();
        $billing->generate(2026, 12, $this->owner);
        $this->assertSame(70000 + 8480, $this->bill(12)->current_charges_cents);
        // Ana pays part of December.
        app(PaymentService::class)->record($this->ana->billingAccount, ['amount_cents' => 30000, 'paid_on' => '2026-12-10', 'method' => 'cash'], $this->owner);

        // November is closed: view only.
        $this->get(route('billing.index', ['year' => 2026, 'month' => 11]))->assertInertia(fn (Assert $p) => $p
            ->where('closedBy', 'December 2026')->where('canGenerate', false)->where('canDeleteMonth', false)->where('canEmail', true));
        $this->get(route('invoices.show', $this->bill(11)))->assertInertia(fn (Assert $p) => $p->where('canVoid', false));
        $this->post(route('invoices.void', $this->bill(11)), ['reason' => 'Trying to change November'])->assertSessionHasErrors('invoice');
        $this->post(route('billing.electricity'), ['rental_agreement_id' => $this->ana->id, 'year' => 2026, 'month' => 11, 'amount' => '50'])->assertSessionHasErrors('amount');
        $this->post(route('billing.void-month'), ['year' => 2026, 'month' => 11, 'reason' => 'Trying to delete November'])->assertSessionHasErrors('month');
        $this->assertSame(InvoiceStatus::Finalized, $this->bill(11)->status);

        // December is the latest: it can be deleted (a reason is required).
        $this->get(route('billing.index', ['year' => 2026, 'month' => 12]))->assertInertia(fn (Assert $p) => $p->where('closedBy', null)->where('canDeleteMonth', true));
        $this->post(route('billing.void-month'), ['year' => 2026, 'month' => 12, 'reason' => 'x'])->assertSessionHasErrors('reason');
        $this->post(route('billing.void-month'), ['year' => 2026, 'month' => 12, 'reason' => 'Wrong electric bills'])->assertRedirect();

        $this->assertSame(0, Invoice::where('rental_agreement_id', $this->ana->id)->whereMonth('period_start', 12)->where('status', InvoiceStatus::Finalized)->count());
        $this->assertNull(OneTimeCharge::sole()->invoice_id); // electricity back to waiting
        $ledger = app(ReceivableLedgerService::class);
        $this->assertSame(70000 - 30000, $ledger->balance($this->ana->billingAccount)); // only November left; the payment is credit
        $this->get(route('billing.index', ['year' => 2026, 'month' => 11]))->assertInertia(fn (Assert $p) => $p->where('closedBy', null)); // November is the latest again

        // Generate December again: rent + the same electricity, billed once.
        $billing->generate(2026, 12, $this->owner);
        $this->assertSame(70000 + 8480, $this->bill(12)->current_charges_cents);
        $this->assertSame(70000 + 70000 + 8480 - 30000, $ledger->balance($this->ana->billingAccount));
    }
}
