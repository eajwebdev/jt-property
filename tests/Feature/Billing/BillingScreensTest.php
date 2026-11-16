<?php

namespace Tests\Feature\Billing;

use App\Enums\BatchItemStatus;
use App\Enums\InvoiceStatus;
use App\Models\BillingBatch;
use App\Models\BillingBatchItem;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\RentalAgreement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::where('role', 'owner')->orderBy('id')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function props($response): array
    {
        return $response->viewData('page')['props'];
    }

    private function generate(): void
    {
        $this->actingAs($this->owner)->post(route('billing.generate'), ['year' => 2026, 'month' => 11])->assertRedirect();
    }

    private function row(array $props, string $tenant): array
    {
        return collect($props['rows'])->firstWhere('tenant', $tenant);
    }

    public function test_billing_home_lists_billed_months_and_offers_the_next_one(): void
    {
        // Nothing billed yet: no months, start with this month.
        $this->actingAs($this->owner)->get(route('billing.index'))
            ->assertInertia(fn (Assert $p) => $p->component('billing/months')->where('months', [])->where('next', ['year' => 2026, 'month' => 11]));

        $this->generate();

        // November is listed (6 bills, nothing paid yet) and December is next. Generating lands back on the month.
        $this->actingAs($this->owner)->get(route('billing.index'))
            ->assertInertia(fn (Assert $p) => $p->component('billing/months')
                ->has('months', 1)
                ->where('months.0.key', '2026-11')
                ->where('months.0.bills', 6)
                ->where('months.0.paid_bills', 0)
                ->where('months.0.billed_cents', (int) Invoice::sum('current_charges_cents'))
                ->where('next', ['year' => 2026, 'month' => 12]));
        $this->actingAs($this->owner)->post(route('billing.generate'), ['year' => 2026, 'month' => 11])
            ->assertRedirect(route('billing.index', ['year' => 2026, 'month' => 11]));
    }

    public function test_seeded_november_preview_shows_previous_balance_and_rice_cooker(): void
    {
        $response = $this->actingAs($this->owner)->get(route('billing.index', ['year' => 2026, 'month' => 11]));
        $response->assertInertia(fn (Assert $p) => $p->component('billing/index')->has('rows', 6)->where('summary.counts.eligible', 6)->where('canGenerate', true));
        $props = $this->props($response);

        $this->assertSame(180000, $this->row($props, 'Ben Demo')['previous_balance_cents']);
        $ana = collect($this->row($props, 'Ana Demo')['lines'])->firstWhere('label', 'Rice cooker');
        $this->assertSame(20000, $ana['amount_cents']);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, BillingBatch::count());
    }

    public function test_staff_cannot_generate_but_can_view_their_property_only(): void
    {
        $staff = User::where('role', 'staff')->orderBy('id')->firstOrFail();

        $this->actingAs($staff)->post(route('billing.generate'), ['year' => 2026, 'month' => 11])->assertForbidden();
        $this->assertSame(0, Invoice::count());

        $props = $this->props($this->actingAs($staff)->get(route('billing.index', ['year' => 2026, 'month' => 11])));
        $this->assertCount(5, $props['rows']);
        $this->assertFalse($props['canGenerate']);
    }

    public function test_generate_redirects_to_batch_with_row_statuses_and_double_post_is_all_already_billed(): void
    {
        $this->generate();
        $first = BillingBatch::firstOrFail();
        $this->assertSame(6, Invoice::count());

        $page = $this->actingAs($this->owner)->get(route('billing.batches.show', $first));
        $page->assertInertia(fn (Assert $p) => $p->component('billing/batch')->has('items', 6)->where('items.0.status', 'generated'));

        $this->generate();
        $second = BillingBatch::orderByDesc('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(6, Invoice::count());
        $this->assertSame(6, $second->items()->where('status', BatchItemStatus::AlreadyBilled)->count());
        $this->assertSame(6, LedgerEntry::where('type', 'charge')->count());
    }

    public function test_generate_validates_month(): void
    {
        $this->actingAs($this->owner)->post(route('billing.generate'), ['year' => 2026, 'month' => 13])->assertSessionHasErrors('month');
    }

    public function test_failed_item_can_be_retried_by_owner_only(): void
    {
        $agreement = RentalAgreement::firstOrFail();
        $batch = BillingBatch::create(['year' => 2026, 'month' => 11, 'created_by' => $this->owner->id, 'status' => 'done', 'counts' => []]);
        $item = BillingBatchItem::create(['billing_batch_id' => $batch->id, 'rental_agreement_id' => $agreement->id, 'status' => BatchItemStatus::Failed, 'message' => 'boom']);

        $this->actingAs(User::where('role', 'staff')->orderBy('id')->firstOrFail())->post(route('billing.items.retry', $item))->assertForbidden();
        $this->actingAs($this->owner)->post(route('billing.items.retry', $item))->assertRedirect(route('billing.batches.show', $batch));

        $this->assertSame(BatchItemStatus::Generated, $item->fresh()->status);
        $this->assertSame(1, Invoice::count());
    }

    public function test_invoice_page_shows_lines_and_current_total_vs_statement_total(): void
    {
        $this->generate();
        $ben = Invoice::whereHas('billingAccount.payer', fn ($q) => $q->where('full_name', 'Ben Demo'))->firstOrFail();

        $response = $this->actingAs($this->owner)->get(route('invoices.show', $ben));
        $response->assertInertia(fn (Assert $p) => $p->component('billing/invoice')->where('invoice.status', 'overdue')->has('invoice.lines'));
        $invoice = $this->props($response)['invoice'];

        $this->assertSame($ben->current_charges_cents, $invoice['current_total_cents']);
        $this->assertSame(180000, $invoice['previous_balance_cents']);
        $this->assertSame(180000 + $ben->current_charges_cents, $invoice['statement_total_cents']);
        $this->assertSame('Monthly Rental', substr($invoice['lines'][0]['label'], 0, 14));
    }

    public function test_invoice_list_filters_and_shows_status(): void
    {
        $this->generate();

        $this->actingAs($this->owner)->get(route('invoices.index', ['month' => '2026-11']))
            ->assertInertia(fn (Assert $p) => $p->component('billing/invoices')->has('rows', 6));
        // Today is Nov 16: Ben (due the 12th) is overdue, Dan (due the 28th) is not yet.
        $statuses = collect($this->props($this->actingAs($this->owner)->get(route('invoices.index', ['month' => '2026-11'])))['rows'])->pluck('status');
        $this->assertContains('overdue', $statuses);
        $this->assertContains('unpaid', $statuses);
        $this->actingAs($this->owner)->get(route('invoices.index', ['month' => '2026-10']))
            ->assertInertia(fn (Assert $p) => $p->has('rows', 0));
        $this->actingAs(User::where('role', 'staff')->orderBy('id')->firstOrFail())->get(route('invoices.index', ['month' => '2026-11']))
            ->assertInertia(fn (Assert $p) => $p->has('rows', 5));
    }

    public function test_staff_cannot_open_an_invoice_of_another_property(): void
    {
        $this->generate();
        $hillside = Invoice::whereHas('property', fn ($q) => $q->where('code', 'HIL'))->firstOrFail();

        $this->actingAs(User::where('role', 'staff')->orderBy('id')->firstOrFail())->get(route('invoices.show', $hillside))->assertForbidden();
    }

    public function test_void_needs_a_reason_and_is_owner_only(): void
    {
        $this->generate();
        $invoice = Invoice::firstOrFail();

        $this->actingAs($this->owner)->post(route('invoices.void', $invoice), ['reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->actingAs(User::where('role', 'staff')->orderBy('id')->firstOrFail())->post(route('invoices.void', $invoice), ['reason' => 'Wrong amount'])->assertForbidden();
        $this->assertSame(InvoiceStatus::Finalized, $invoice->fresh()->status);

        $this->actingAs($this->owner)->post(route('invoices.void', $invoice), ['reason' => 'Wrong amount'])->assertRedirect();
        $this->assertSame(InvoiceStatus::Void, $invoice->fresh()->status);
        $this->actingAs($this->owner)->get(route('invoices.show', $invoice))
            ->assertInertia(fn (Assert $p) => $p->where('invoice.status', 'void')->where('invoice.void_reason', 'Wrong amount'));
    }

    public function test_tenant_page_flags_replaced_stays_and_first_rent(): void
    {
        $ana = RentalAgreement::whereHas('billingAccount.payer', fn ($q) => $q->where('full_name', 'Ana Demo'))->firstOrFail();
        $tenant = $ana->billingAccount->payer;

        $props = $this->props($this->actingAs($this->owner)->get(route('tenants.show', $tenant)));

        $this->assertSame(250000, $props['accounts'][0]['agreements'][0]['first_rent_cents']);
        $this->assertFalse($props['occupancies'][0]['replaced']);
    }

    public function test_missing_utility_input_is_an_indicator_not_a_block(): void
    {
        // December has no utility bills for the shared/metered units.
        $response = $this->actingAs($this->owner)->get(route('billing.index', ['year' => 2026, 'month' => 12]));
        $props = $this->props($response);

        $this->assertContains('utility_pending:electricity', $this->row($props, 'Ana Demo')['flags']);
        $this->assertSame('eligible', $this->row($props, 'Ana Demo')['status']);
    }
}
