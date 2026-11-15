<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Enums\RentMode;
use App\Enums\Role;
use App\Models\BillingAccount;
use App\Models\ChargeCategory;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\OneTimeCharge;
use App\Models\Property;
use App\Models\RecurringCharge;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Models\UtilityExclusion;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\BillingPreviewService;
use App\Services\Billing\PreviewRow;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingPreviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Unit $unit;

    private BillingPreviewService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $this->unit = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::PerBed, 'capacity' => 4]);
        $this->service = app(BillingPreviewService::class);
    }

    private function agreement(int $rent = 350000, array $overrides = [], ?Unit $unit = null, array $tenant = []): RentalAgreement
    {
        $payer = Tenant::factory()->create(array_merge(['property_id' => $this->property->id], $tenant));
        $account = BillingAccount::factory()->for($this->property)->create(['payer_tenant_id' => $payer->id]);
        $agreement = RentalAgreement::factory()->for($account, 'billingAccount')->for($unit ?? $this->unit)
            ->create(array_merge(['start_date' => '2026-01-01'], $overrides));
        if ($rent > 0) {
            RentRate::create(['rental_agreement_id' => $agreement->id, 'rent_cents' => $rent, 'effective_from' => $agreement->start_date->toDateString()]);
        }

        return $agreement;
    }

    private function utility(string $type, string $mode, array $extra = [], ?Unit $unit = null): void
    {
        UnitUtility::create(array_merge(['unit_id' => ($unit ?? $this->unit)->id, 'type' => $type, 'mode' => $mode], $extra));
    }

    private function row(array $preview, RentalAgreement $a): PreviewRow
    {
        return collect($preview['rows'])->firstWhere('agreementId', $a->id);
    }

    public function test_preview_issues_no_insert_update_or_delete(): void
    {
        $this->agreement();
        $this->agreement(300000);

        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        $this->service->preview(2026, 11);

        $this->assertSame([], $writes);
    }

    public function test_a101_four_tenants_get_their_own_due_dates_and_rents(): void
    {
        $rows = [];
        foreach ([[5, 350000], [12, 400000], [18, 450000], [28, 500000]] as [$due, $rent]) {
            $rows[$due] = $this->agreement($rent, ['due_day' => $due]);
        }

        $preview = $this->service->preview(2026, 11, $this->property->id);

        $this->assertCount(4, $preview['rows']);
        foreach ([[5, 350000], [12, 400000], [18, 450000], [28, 500000]] as [$due, $rent]) {
            $row = $this->row($preview, $rows[$due]);
            $this->assertSame(sprintf('2026-11-%02d', $due), $row->dueDate);
            $this->assertSame($rent, $row->currentChargesCents);
            $this->assertSame('rent', $row->lines[0]['category']);
            $this->assertSame('eligible', $row->status);
        }
        $this->assertSame(4, $preview['summary']['counts']['eligible']);
        $this->assertSame(1700000, $preview['summary']['total_new_charges_cents']);
    }

    public function test_acceptance_row_shows_current_4150_previous_1800_estimated_5950(): void
    {
        $a = $this->agreement(350000);
        RecurringCharge::create(['rental_agreement_id' => $a->id, 'charge_category_id' => ChargeCategory::where('name', 'Rice cooker')->value('id'), 'label' => 'Rice cooker', 'amount_cents' => 20000, 'start_date' => '2026-01-01']);
        $this->utility('electricity', 'fixed', ['fixed_cents' => 30000]);
        $this->utility('water', 'fixed', ['fixed_cents' => 15000]);
        app(ReceivableLedgerService::class)->post($a->billingAccount, LedgerEntryType::Opening, 180000, now()->setDate(2026, 10, 1), $a, 'Opening');

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame(415000, $row->currentChargesCents);
        $this->assertSame(180000, $row->previousBalanceCents);
        $this->assertSame(595000, $row->estimatedTotalCents);
        $this->assertSame(['rent', 'recurring', 'utility', 'utility'], array_column($row->lines, 'category'));
    }

    public function test_tenant_without_email_is_flagged_but_eligible(): void
    {
        $a = $this->agreement(350000, [], null, ['email' => null]);

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame('eligible', $row->status);
        $this->assertContains('no_email', $row->flags);
    }

    public function test_missing_water_bill_needs_review_with_reason(): void
    {
        $a = $this->agreement(350000);
        $this->utility('water', 'metered');

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame('needs_review', $row->status);
        $this->assertContains('needs_utility_input:water', $row->flags);
        $this->assertSame(350000, $row->currentChargesCents);
    }

    public function test_exclusion_clears_the_utility_review_flag(): void
    {
        $a = $this->agreement(350000);
        $this->utility('water', 'metered');
        UtilityExclusion::create(['rental_agreement_id' => $a->id, 'type' => 'water', 'bill_month' => '2026-11-01', 'reason' => 'Away']);

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame('eligible', $row->status);
        $this->assertNotContains('needs_utility_input:water', $row->flags);
    }

    public function test_existing_finalized_invoice_is_already_billed(): void
    {
        $a = $this->agreement(350000);
        $cycle = app(BillingCycleResolver::class)->cycleFor($a, 2026, 11);
        Invoice::create([
            'billing_account_id' => $a->billing_account_id, 'rental_agreement_id' => $a->id, 'property_id' => $this->property->id,
            'number' => 'X-1', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'issue_date' => '2026-10-29', 'due_date' => '2026-11-05',
            'status' => InvoiceStatus::Finalized, 'current_charges_cents' => 350000, 'finalized_at' => now(),
            'idempotency_key' => 'agreement:'.$a->id.':cycle:'.$cycle->periodStart->toDateString(),
        ]);

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame('already_billed', $row->status);
        $this->assertContains('existing_invoice', $row->flags);
    }

    public function test_agreement_without_a_rent_rate_is_needs_review_never_billed_as_zero(): void
    {
        $a = $this->agreement(0);

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame('needs_review', $row->status);
        $this->assertContains('no_rent_rate', $row->flags);
        $this->assertSame([], $row->lines);
    }

    public function test_one_time_credit_reduces_current_charges(): void
    {
        $a = $this->agreement(350000);
        OneTimeCharge::create(['billing_account_id' => $a->billing_account_id, 'rental_agreement_id' => $a->id, 'charge_category_id' => ChargeCategory::where('name', 'Repair')->value('id'), 'label' => 'Goodwill', 'kind' => 'credit', 'amount_cents' => 50000, 'bill_month' => '2026-11-01']);

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame(300000, $row->currentChargesCents);
        $this->assertSame(-50000, $row->lines[1]['amount_cents']);
    }

    public function test_agreements_outside_the_month_are_not_listed(): void
    {
        $this->agreement(350000, ['start_date' => '2026-12-01']);
        $this->agreement(350000, ['start_date' => '2026-01-01', 'end_date' => '2026-10-31']);

        $this->assertSame([], $this->service->preview(2026, 11)['rows']);
    }

    public function test_staff_see_only_assigned_properties(): void
    {
        $mine = $this->agreement();
        $otherProperty = Property::factory()->create();
        $otherUnit = Unit::factory()->for($otherProperty)->create();
        $payer = Tenant::factory()->create(['property_id' => $otherProperty->id]);
        $account = BillingAccount::factory()->for($otherProperty)->create(['payer_tenant_id' => $payer->id]);
        $theirs = RentalAgreement::factory()->for($account, 'billingAccount')->for($otherUnit)->create();
        RentRate::create(['rental_agreement_id' => $theirs->id, 'rent_cents' => 100000, 'effective_from' => '2026-01-01']);

        $staff = User::factory()->create(['role' => Role::Staff]);
        $staff->properties()->attach($this->property);

        $ids = array_column($this->service->preview(2026, 11, null, null, $staff)['rows'], 'agreementId');

        $this->assertSame([$mine->id], $ids);
        $this->assertCount(2, $this->service->preview(2026, 11)['rows']);
    }

    public function test_query_count_does_not_grow_past_a_constant_per_agreement(): void
    {
        $count = function () {
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            $this->service->preview(2026, 11);

            return $n;
        };

        foreach (range(1, 5) as $i) {
            $this->agreement();
        }
        $five = $count();
        foreach (range(1, 15) as $i) {
            $this->agreement();
        }
        $twenty = $count();

        $this->assertLessThanOrEqual(10 * 15, $twenty - $five);
        fwrite(STDERR, "\nQUERIES 5 agreements={$five} 20 agreements={$twenty}\n");
    }

    private function invoiceFor(RentalAgreement $a, array $overrides = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'billing_account_id' => $a->billing_account_id, 'rental_agreement_id' => $a->id, 'property_id' => $this->property->id,
            'number' => 'N-'.uniqid(), 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'issue_date' => '2026-10-29', 'due_date' => '2026-11-05',
            'status' => InvoiceStatus::Finalized, 'current_charges_cents' => 123400, 'finalized_at' => now(),
            'idempotency_key' => 'agreement:'.$a->id.':cycle:2026-11-01:'.uniqid(),
        ], $overrides));
        InvoiceLineItem::create(['invoice_id' => $invoice->id, 'sort' => 1, 'category' => 'rent', 'label' => 'Stored rent', 'amount_cents' => 123400]);

        return $invoice;
    }

    public function test_previous_balance_excludes_the_cycles_own_invoice_charge(): void
    {
        $a = $this->agreement(350000);
        $ledger = app(ReceivableLedgerService::class);
        $ledger->post($a->billingAccount, LedgerEntryType::Opening, 180000, now()->setDate(2026, 10, 1), $a, 'Opening');
        $invoice = $this->invoiceFor($a, ['status' => InvoiceStatus::Finalized]);
        $charge = $ledger->post($a->billingAccount, LedgerEntryType::Charge, 123400, now()->setDate(2026, 10, 29), $a);
        $charge->forceFill(['invoice_id' => $invoice->id])->saveQuietly();

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame(180000, $row->previousBalanceCents);
    }

    public function test_previous_balance_stays_when_the_invoice_was_voided_and_reversed(): void
    {
        $a = $this->agreement(350000);
        $ledger = app(ReceivableLedgerService::class);
        $ledger->post($a->billingAccount, LedgerEntryType::Opening, 180000, now()->setDate(2026, 10, 1), $a, 'Opening');
        $invoice = $this->invoiceFor($a);
        $charge = $ledger->post($a->billingAccount, LedgerEntryType::Charge, 123400, now()->setDate(2026, 10, 29), $a);
        $charge->forceFill(['invoice_id' => $invoice->id])->saveQuietly();
        $reversal = $ledger->reverse($charge, 'void');
        $reversal->forceFill(['invoice_id' => $invoice->id])->saveQuietly();
        $invoice->update(['status' => InvoiceStatus::Void, 'voided_at' => now()]);

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame(180000, $row->previousBalanceCents);
        $this->assertSame('eligible', $row->status);
    }

    public function test_already_billed_row_returns_the_stored_invoice_not_a_recomputation(): void
    {
        $a = $this->agreement(350000);
        $invoice = $this->invoiceFor($a, ['number' => 'RIV-202611-0001']);

        $row = $this->row($this->service->preview(2026, 11), $a);

        $this->assertSame('already_billed', $row->status);
        $this->assertSame(123400, $row->currentChargesCents);
        $this->assertSame('Stored rent', $row->lines[0]['label']);
        $this->assertSame('RIV-202611-0001', $row->invoiceNumber);
    }

    public function test_account_level_charge_goes_to_the_lowest_id_agreement_with_a_cycle_this_month(): void
    {
        $old = $this->agreement(350000, ['end_date' => '2026-06-30']);
        $first = $this->agreement(350000);
        $second = RentalAgreement::factory()->for($first->billingAccount, 'billingAccount')->for($this->unit)->create(['start_date' => '2026-01-01']);
        RentRate::create(['rental_agreement_id' => $second->id, 'rent_cents' => 100000, 'effective_from' => '2026-01-01']);
        OneTimeCharge::create(['billing_account_id' => $first->billing_account_id, 'charge_category_id' => ChargeCategory::where('name', 'Repair')->value('id'), 'label' => 'Fix', 'kind' => 'charge', 'amount_cents' => 5000, 'bill_month' => '2026-11-01']);

        $preview = $this->service->preview(2026, 11);

        $this->assertSame($first->id, $this->service->accountChargeAgreementId($first->billingAccount, 2026, 11));
        $this->assertSame(355000, $this->row($preview, $first)->currentChargesCents);
        $this->assertSame(100000, $this->row($preview, $second)->currentChargesCents);
    }

    public function test_account_level_charge_in_a_transfer_month_goes_to_the_old_agreement(): void
    {
        $old = $this->agreement(350000, ['end_date' => '2026-11-14']);
        $new = RentalAgreement::factory()->for($old->billingAccount, 'billingAccount')->for($this->unit)
            ->create(['start_date' => '2026-11-15', 'replaces_agreement_id' => $old->id]);
        RentRate::create(['rental_agreement_id' => $new->id, 'rent_cents' => 350000, 'effective_from' => '2026-11-15']);
        OneTimeCharge::create(['billing_account_id' => $old->billing_account_id, 'charge_category_id' => ChargeCategory::where('name', 'Repair')->value('id'), 'label' => 'Fix', 'kind' => 'charge', 'amount_cents' => 5000, 'bill_month' => '2026-11-01']);

        $preview = $this->service->preview(2026, 11);

        $this->assertSame($old->id, $this->service->accountChargeAgreementId($old->billingAccount, 2026, 11));
        $this->assertContains('one_time', array_column($this->row($preview, $old)->lines, 'category'));
        $this->assertNotContains('one_time', array_column($this->row($preview, $new)->lines, 'category'));
    }

    public function test_summary_counts_a_shared_accounts_previous_balance_once(): void
    {
        $a = $this->agreement(350000);
        $b = RentalAgreement::factory()->for($a->billingAccount, 'billingAccount')->for($this->unit)->create(['start_date' => '2026-01-01']);
        RentRate::create(['rental_agreement_id' => $b->id, 'rent_cents' => 100000, 'effective_from' => '2026-01-01']);
        app(ReceivableLedgerService::class)->post($a->billingAccount, LedgerEntryType::Opening, 180000, now()->setDate(2026, 10, 1), $a, 'Opening');

        $summary = $this->service->preview(2026, 11)['summary'];

        $this->assertSame(180000, $summary['total_previous_outstanding_cents']);
        $this->assertSame(450000 + 180000, $summary['estimated_statement_total_cents']);
    }
}
