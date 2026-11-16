<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAccount;
use App\Models\ChargeCategory;
use App\Models\OneTimeCharge;
use App\Models\Property;
use App\Models\RecurringCharge;
use App\Models\RentalAgreement;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\OneTimeChargeService;
use App\Services\Billing\RecurringChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChargeServicesTest extends TestCase
{
    use RefreshDatabase;

    private RentalAgreement $agreement;

    protected function setUp(): void
    {
        parent::setUp();

        $property = Property::factory()->create();
        $account = BillingAccount::factory()->for($property)->create();
        $this->agreement = RentalAgreement::factory()->for($account, 'billingAccount')->create(['start_date' => '2026-01-01']);
    }

    private function riceCooker(array $overrides = []): RecurringCharge
    {
        return RecurringCharge::create(array_merge([
            'rental_agreement_id' => $this->agreement->id,
            'charge_category_id' => ChargeCategory::where('name', 'Rice cooker')->value('id'),
            'label' => 'Rice cooker',
            'amount_cents' => 20000,
            'start_date' => '2026-11-01',
        ], $overrides));
    }

    /** @return Collection<int, array{label: string, amount_cents: int, category_id: int, source: RecurringCharge}> */
    private function lines(int $year, int $month): Collection
    {
        $cycle = app(BillingCycleResolver::class)->cycleFor($this->agreement->fresh(), $year, $month);

        return app(RecurringChargeService::class)->linesFor($this->agreement->fresh(), $cycle);
    }

    public function test_default_categories_are_seeded_as_system(): void
    {
        $this->assertSame(12, ChargeCategory::where('is_system', true)->count());
        $this->assertTrue(ChargeCategory::where('name', 'Rice cooker')->where('kind', 'appliance')->exists());
    }

    public function test_rice_cooker_is_on_every_cycle_exactly_once(): void
    {
        $charge = $this->riceCooker();

        foreach ([[2026, 11], [2026, 12]] as [$y, $m]) {
            $lines = $this->lines($y, $m);
            $this->assertCount(1, $lines);
            $line = $lines->first();
            $this->assertSame('Rice cooker', $line['label']);
            $this->assertSame(20000, $line['amount_cents']);
            $this->assertSame($charge->charge_category_id, $line['category_id']);
            $this->assertTrue($line['source']->is($charge));
        }
        $this->assertCount(0, $this->lines(2026, 10));
    }

    public function test_ended_charge_is_not_on_later_cycles(): void
    {
        $this->riceCooker(['end_date' => '2026-12-31']);

        $this->assertCount(1, $this->lines(2026, 12));
        $this->assertCount(0, $this->lines(2027, 1));
    }

    public function test_inactive_charge_is_never_included(): void
    {
        $this->riceCooker(['active' => false]);

        $this->assertCount(0, $this->lines(2026, 11));
    }

    public function test_charge_starting_mid_cycle_is_included(): void
    {
        $this->riceCooker(['start_date' => '2026-11-15']);

        $this->assertCount(1, $this->lines(2026, 11));
    }

    public function test_charge_starting_after_the_cycle_is_not_included(): void
    {
        $this->riceCooker(['start_date' => '2026-12-01']);

        $this->assertCount(0, $this->lines(2026, 11));
    }

    public function test_cycles_that_start_mid_month_use_the_cycle_dates(): void
    {
        $this->agreement->update(['cycle_start_day' => 15]);
        $this->riceCooker(['start_date' => '2026-11-10', 'end_date' => '2026-11-20']);

        // Nov cycle is Nov 15 - Dec 14: still overlaps. The Dec cycle (Dec 15 on) does not.
        $this->assertCount(1, $this->lines(2026, 11));
        $this->assertCount(0, $this->lines(2026, 12));
    }

    public function test_charge_of_another_agreement_is_not_included(): void
    {
        $other = RentalAgreement::factory()->create(['start_date' => '2026-01-01']);
        $this->riceCooker(['rental_agreement_id' => $other->id]);

        $this->assertCount(0, $this->lines(2026, 11));
    }

    private function oneTime(array $overrides = []): OneTimeCharge
    {
        return OneTimeCharge::create(array_merge([
            'billing_account_id' => $this->agreement->billing_account_id,
            'charge_category_id' => ChargeCategory::where('name', 'Repair')->value('id'),
            'label' => 'Door lock',
            'kind' => 'charge',
            'amount_cents' => 35000,
            'bill_month' => '2026-11-01',
        ], $overrides));
    }

    public function test_pending_one_time_charges_for_the_month_credit_is_negative(): void
    {
        $this->oneTime();
        $this->oneTime(['label' => 'Goodwill', 'kind' => 'credit', 'amount_cents' => 10000]);
        $this->oneTime(['label' => 'December only', 'bill_month' => '2026-12-01']);

        $pending = app(OneTimeChargeService::class)->pendingFor($this->agreement->billingAccount, 2026, 11);

        $this->assertSame([35000, -10000], $pending->map->signed_cents->all());
    }

    public function test_consumed_one_time_charges_are_not_returned_again(): void
    {
        $charge = $this->oneTime();
        $charge->update(['invoice_id' => $this->makeInvoiceId()]);

        $this->assertCount(0, app(OneTimeChargeService::class)->pendingFor($this->agreement->billingAccount, 2026, 11));
    }

    public function test_one_time_charges_of_other_accounts_are_not_returned(): void
    {
        $this->oneTime(['billing_account_id' => BillingAccount::factory()->create()->id]);

        $this->assertCount(0, app(OneTimeChargeService::class)->pendingFor($this->agreement->billingAccount, 2026, 11));
    }

    private function makeInvoiceId(): int
    {
        return DB::table('invoices')->insertGetId([
            'billing_account_id' => $this->agreement->billing_account_id,
            'rental_agreement_id' => $this->agreement->id,
            'property_id' => $this->agreement->billingAccount->property_id,
            'number' => 'T-202611-0001',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
            'issue_date' => '2026-10-29', 'due_date' => '2026-11-05',
            'status' => 'finalized', 'current_charges_cents' => 0,
            'idempotency_key' => 'k1', 'finalized_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
