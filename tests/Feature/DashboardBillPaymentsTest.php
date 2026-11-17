<?php

namespace Tests\Feature;

use App\Enums\RentMode;
use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\PaymentService;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Dashboard tab "This month's bills": who has not paid, paid part, paid in full or paid ahead on the latest bills. */
class DashboardBillPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** @var array<string, BillingAccount> */
    private array $accounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-01 09:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        $property = Property::factory()->create();
        foreach (['ANA', 'BEN', 'CARL', 'DIA'] as $name) {
            $unit = Unit::factory()->for($property)->create(['rent_mode' => RentMode::Whole, 'capacity' => 1]);
            $payer = Tenant::factory()->create(['property_id' => $property->id, 'full_name' => $name]);
            $account = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $payer->id]);
            $agreement = RentalAgreement::factory()->for($account, 'billingAccount')->for($unit)->create(['start_date' => '2026-09-01', 'due_day' => 5]);
            RentRate::create(['rental_agreement_id' => $agreement->id, 'rent_cents' => 70000, 'effective_from' => '2026-09-01']);
            $this->accounts[$name] = $account;
        }
        $billing = app(InvoiceGenerationService::class);
        $billing->generate(2026, 9, $this->owner);
        $this->travelTo(Carbon::parse('2026-09-03 09:00', 'Asia/Manila'));
        foreach ($this->accounts as $account) {
            $this->pay($account, 70000, '2026-09-03'); // September settled by everyone
        }
        $this->travelTo(Carbon::parse('2026-10-01 09:00', 'Asia/Manila'));
        $billing->generate(2026, 10, $this->owner);
        $this->travelTo(Carbon::parse('2026-10-04 09:00', 'Asia/Manila'));
        $this->pay($this->accounts['BEN'], 30000, '2026-10-04');   // part of October
        $this->pay($this->accounts['CARL'], 70000, '2026-10-04');  // exactly October
        $this->pay($this->accounts['DIA'], 100000, '2026-10-04');  // October + ₱300 ahead
    }

    private function pay(BillingAccount $account, int $cents, string $on): void
    {
        app(PaymentService::class)->record($account, ['amount_cents' => $cents, 'paid_on' => $on, 'method' => 'cash'], $this->owner);
    }

    public function test_the_latest_billed_month_shows_who_has_not_paid_paid_part_paid_or_paid_ahead(): void
    {
        $data = app(DashboardService::class)->billPayments(null);

        $this->assertSame('2026-10', $data['month']);
        $this->assertSame(['unpaid' => 1, 'partial' => 1, 'paid' => 1, 'advance' => 1], $data['counts']);
        $this->assertSame(280000, $data['billed_cents']);
        $this->assertSame(70000 + 40000, $data['unpaid_cents']); // ANA all, BEN the rest
        $rows = collect($data['rows'])->keyBy('tenant');
        $this->assertSame('unpaid', $rows['ANA']['status']);
        $this->assertSame(0, $rows['ANA']['paid_cents']);
        $this->assertSame('partial', $rows['BEN']['status']);
        $this->assertSame(30000, $rows['BEN']['paid_cents']);
        $this->assertSame(40000, $rows['BEN']['remaining_cents']);
        $this->assertSame('paid', $rows['CARL']['status']);
        $this->assertSame('advance', $rows['DIA']['status']);
        $this->assertSame(-30000, $rows['DIA']['balance_cents']);
        $this->assertFalse($rows['ANA']['overdue'], 'not yet past the 5th');
        // Unpaid first, so the ones to follow up are on top.
        $this->assertSame(['ANA', 'BEN'], collect($data['rows'])->take(2)->pluck('tenant')->all());
    }

    public function test_after_the_due_date_the_unpaid_ones_are_overdue(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 09:00', 'Asia/Manila'));

        $rows = collect(app(DashboardService::class)->billPayments(null)['rows'])->keyBy('tenant');

        $this->assertTrue($rows['ANA']['overdue']);
        $this->assertTrue($rows['BEN']['overdue']);
        $this->assertFalse($rows['CARL']['overdue']);
    }

    public function test_the_tab_label_shows_how_many_have_not_paid_in_full(): void
    {
        $this->get(route('dashboard'))->assertInertia(fn (Assert $p) => $p
            ->where('metrics.bill_month', '2026-10')
            ->where('metrics.bills_not_fully_paid', 2));
    }

    public function test_with_no_bills_yet_the_tab_says_so(): void
    {
        $data = app(DashboardService::class)->billPayments([999999]);

        $this->assertNull($data['month']);
        $this->assertSame([], $data['rows']);
    }
}
