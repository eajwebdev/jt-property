<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\ChargeCategory;
use App\Models\Invoice;
use App\Models\OneTimeCharge;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use App\Services\Billing\StatementOfAccountsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class StatementOfAccountsReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_amounts_are_split_into_the_sheet_columns_with_period_remarks(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        $property = Property::factory()->create(['name' => 'Mubarak Boarding House', 'dti_registration_no' => '1366594']);
        $unit = Unit::factory()->for($property)->create(['building' => 'Creekside House', 'code' => 'R5']);
        UnitUtility::create(['unit_id' => $unit->id, 'type' => 'electricity', 'mode' => 'fixed', 'fixed_cents' => 7223]);

        $payer = Tenant::factory()->create(['property_id' => $property->id, 'full_name' => 'ACABAL, Praise Rodriguez']);
        $account = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $payer->id]);
        $agreement = RentalAgreement::factory()->for($account, 'billingAccount')->for($unit)->create(['start_date' => '2026-10-01']);
        RentRate::create(['rental_agreement_id' => $agreement->id, 'rent_cents' => 70000, 'effective_from' => '2026-10-01']);
        OneTimeCharge::create([
            'billing_account_id' => $account->id, 'rental_agreement_id' => $agreement->id,
            'charge_category_id' => ChargeCategory::where('name', 'One Month Advance')->value('id'), 'label' => 'One month advance',
            'kind' => 'charge', 'amount_cents' => 70000, 'bill_month' => '2026-10-01', 'created_by' => $owner->id,
        ]);
        app(InvoiceGenerationService::class)->generate(2026, 10, $owner);
        app(InvoiceGenerationService::class)->generate(2026, 11, $owner);
        // October = 700 rent + 72.23 EB + 700 advance; November = 700 + 72.23. ₱1,000 pays October's rent, EB, then part of the advance.
        app(PaymentService::class)->record($account, ['amount_cents' => 100000, 'paid_on' => '2026-11-10', 'method' => 'cash'], $owner);

        $formerPayer = Tenant::factory()->create(['property_id' => $property->id, 'full_name' => 'ZAMORA, Left Early']);
        $former = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $formerPayer->id]);
        app(ReceivableLedgerService::class)->post($former, LedgerEntryType::Opening, 50000, Carbon::parse('2026-09-01'), $former, 'Opening');

        // No date chosen = as of the latest bill's due date, which is before the 10 Nov payment.
        $latestDue = Invoice::where('property_id', $property->id)->max('due_date');
        $this->assertSame(Carbon::parse($latestDue)->toDateString(), app(StatementOfAccountsReport::class)->defaultAsOf($property)->toDateString());
        // October 1,472.23 + November 772.23 (neither paid yet on that date). Former boarders are not on the sheet.
        $this->assertSame(147223 + 77223, app(StatementOfAccountsReport::class)->build($property)['totals']['total']);

        $report = app(StatementOfAccountsReport::class)->build($property, today());

        $current = $report['sections']['Current Boarders']->sole();
        $this->assertSame('Creekside House - R5', $current['room']);
        $this->assertSame(70000, $current['rate_cents']);
        $this->assertSame(['rent' => 70000, 'electric' => 7223, 'advance' => 47223, 'deposit' => 0, 'key' => 0, 'others' => 0], $current['columns']);
        $this->assertSame(124446, $current['total_cents']);
        $this->assertSame(124446, app(ReceivableLedgerService::class)->balance($account));
        $this->assertSame('November 2026 BH & November 2026 EB accounts + One month advance (no deposit)', $current['remarks']);

        // The moved-out boarder's 500.00 is not listed (current boarders only), but stays owed on their account.
        $this->assertSame(['Current Boarders'], array_keys($report['sections']));
        $this->assertSame(124446, $report['totals']['total']);
        $this->assertSame(50000, app(ReceivableLedgerService::class)->balance($former));

        $pdf = $this->get(route('reports.statement-of-accounts', ['property_id' => $property->id]));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
    }

    public function test_period_remarks_read_like_the_paper_sheet(): void
    {
        $report = app(StatementOfAccountsReport::class);

        $this->assertSame('September-October 2026', $report->periods(['2026-10' => false, '2026-09' => false]));
        $this->assertSame('Balance of September + October 2026', $report->periods(['2026-09' => true, '2026-10' => false]));
        $this->assertSame('Balance of August + September-October 2026', $report->periods(['2026-08' => true, '2026-09' => false, '2026-10' => false]));
        $this->assertSame('December 2025-January 2026', $report->periods(['2025-12' => false, '2026-01' => false]));
        $this->assertSame('June-July 2026, September 2026', $report->periods(['2026-06' => false, '2026-07' => false, '2026-09' => false]));
    }

    public function test_staff_cannot_print_another_propertys_sheet(): void
    {
        $other = Property::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->get(route('reports.statement-of-accounts', ['property_id' => $other->id]))->assertForbidden();
    }
}
