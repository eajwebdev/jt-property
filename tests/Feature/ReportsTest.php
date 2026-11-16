<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\UnitStatus;
use App\Models\BillingAccount;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\AgingReportService;
use App\Services\Billing\PaymentAcknowledgmentService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_aging_and_dashboard_reconcile_with_ledger_and_approved_payments(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $property = Property::factory()->create();
        Unit::factory()->for($property)->create(['capacity' => 2]);
        Unit::factory()->for($property)->create(['capacity' => 3, 'status' => UnitStatus::Maintenance]);
        $account = BillingAccount::factory()->for($property)->create();
        $owner = User::factory()->owner()->create();
        $this->actingAs($owner);
        app(ReceivableLedgerService::class)->post($account, LedgerEntryType::Opening, 180000, Carbon::parse('2026-10-01'), $account, 'Opening');
        $payment = app(PaymentService::class)->record($account, ['amount_cents' => 50000, 'paid_on' => '2026-11-16', 'method' => 'cash'], $owner);

        $report = app(AgingReportService::class)->forUser($owner);
        $this->assertSame(130000, $report['totals']['31-60']);
        $this->assertSame(130000, $report['net_receivable_cents']);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')->where('metrics.still_due_cents', 130000)
            ->where('metrics.overdue_cents', 130000)->where('metrics.collected_cents', 50000)
            ->where('metrics.vacant', 2)->etc());
        $this->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')->where('aging.totals.31-60', 130000)
            ->where('monthly.collected_cents', 50000)
            ->where('propertySummary.0.net_receivable_cents', 130000)
            ->where('unpaidOpeningsCents', 130000)->etc());
        $this->get(route('accounts.statement', ['account' => $account, 'from' => '2026-10-01', 'to' => '2026-11-16']))
            ->assertInertia(fn (Assert $page) => $page->component('accounts/statement')
                ->where('statement.opening_cents', 0)->where('statement.closing_cents', 130000)->etc());
        $accountPdf = $this->get(route('accounts.statement.pdf', ['account' => $account, 'from' => '2026-10-01', 'to' => '2026-11-16']));
        $accountPdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $accountPdf->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $accountPdf->streamedContent());

        $html = app(PaymentAcknowledgmentService::class)->html($payment);
        $this->assertStringContainsString($payment->ack_number, $html);
        $this->assertStringContainsString('not a BIR official receipt', $html);

        $csv = $this->get(route('reports.aging.csv'));
        $csv->assertOk();
        $this->assertStringContainsString('31-60', $csv->streamedContent());
        $this->assertStringContainsString('130000', $csv->streamedContent());
        $pdf = $this->get(route('reports.pdf', ['property_id' => $property->id, 'month' => '2026-11']));
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $pdf->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());
        $acknowledgment = $this->get(route('payments.acknowledgment', $payment));
        $acknowledgment->assertOk();
        $this->assertStringStartsWith('inline;', $acknowledgment->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $acknowledgment->streamedContent());
    }

    public function test_staff_reports_are_scoped_to_assigned_properties(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $allowed = Property::factory()->create();
        $other = Property::factory()->create();
        $staff = User::factory()->create();
        $staff->properties()->attach($allowed);
        $otherAccount = BillingAccount::factory()->for($other)->create();
        app(ReceivableLedgerService::class)->post($otherAccount, LedgerEntryType::Opening, 90000, Carbon::parse('2026-10-01'), $otherAccount, 'Opening');

        $this->actingAs($staff)->get(route('reports.index'))->assertInertia(fn (Assert $page) => $page
            ->component('reports/index')->where('aging.rows', [])->etc());
        $this->get(route('reports.aging.csv', ['property_id' => $other->id]))->assertForbidden();
        $this->get(route('reports.pdf', ['property_id' => $other->id]))->assertForbidden();
        $this->get(route('accounts.statement', $otherAccount))->assertForbidden();
        $this->get(route('accounts.statement.pdf', $otherAccount))->assertForbidden();
        $this->get(route('audit.index'))->assertForbidden();
        $this->get(route('calendar.index', ['month' => '2026-11']))->assertInertia(fn (Assert $page) => $page
            ->component('calendar/index')->where('propertyId', null)->where('events', [])->etc());
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')->where('metrics.still_due_cents', 0)->etc());
    }

    public function test_pending_payment_has_no_acknowledgment_document(): void
    {
        $property = Property::factory()->create();
        $account = BillingAccount::factory()->for($property)->create();
        $staff = User::factory()->create();
        $staff->properties()->attach($property);
        $payment = app(PaymentService::class)->record($account, ['amount_cents' => 1000, 'paid_on' => today()->toDateString(), 'method' => 'cash'], $staff);

        $this->actingAs($staff)->get(route('payments.acknowledgment', $payment))->assertNotFound();
    }

    public function test_calendar_shows_each_demo_agreements_november_due_date(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('role', 'owner')->orderBy('id')->firstOrFail();

        $this->actingAs($owner)->get(route('calendar.index', ['month' => '2026-11']))
            ->assertInertia(fn (Assert $page) => $page->component('calendar/index')->has('events', 6)->etc());
        $this->get(route('audit.index'))->assertOk();
    }
}
