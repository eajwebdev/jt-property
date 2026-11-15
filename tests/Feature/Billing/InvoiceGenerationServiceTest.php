<?php

namespace Tests\Feature\Billing;

use App\Enums\BatchItemStatus;
use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Enums\RentMode;
use App\Jobs\GenerateMonthlyBillsJob;
use App\Models\BillingAccount;
use App\Models\ChargeCategory;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\LedgerEntry;
use App\Models\OneTimeCharge;
use App\Models\Property;
use App\Models\RecurringCharge;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitUtility;
use App\Models\User;
use App\Models\UtilityAllocation;
use App\Models\UtilityBill;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\ReceivableLedgerService;
use App\Services\TenancyService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InvoiceGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private Unit $unit;

    private User $owner;

    private InvoiceGenerationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = Property::factory()->create();
        $this->unit = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::PerBed, 'capacity' => 4]);
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        $this->service = app(InvoiceGenerationService::class);
    }

    private function agreement(int $rent = 350000, array $overrides = [], ?Unit $unit = null, ?Property $property = null): RentalAgreement
    {
        $property ??= $this->property;
        $payer = Tenant::factory()->create(['property_id' => $property->id]);
        $account = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $payer->id]);
        $agreement = RentalAgreement::factory()->for($account, 'billingAccount')->for($unit ?? $this->unit)
            ->create(array_merge(['start_date' => '2026-01-01'], $overrides));
        if ($rent > 0) {
            RentRate::create(['rental_agreement_id' => $agreement->id, 'rent_cents' => $rent, 'effective_from' => $agreement->start_date->toDateString()]);
        }

        return $agreement;
    }

    private function recurring(RentalAgreement $a, string $label, int $cents): RecurringCharge
    {
        return RecurringCharge::create(['rental_agreement_id' => $a->id, 'charge_category_id' => ChargeCategory::where('name', 'Rice cooker')->value('id'), 'label' => $label, 'amount_cents' => $cents, 'start_date' => '2026-01-01']);
    }

    private function oneTime(RentalAgreement $a, string $kind, int $cents, string $label = 'Broken key'): OneTimeCharge
    {
        return OneTimeCharge::create([
            'billing_account_id' => $a->billing_account_id, 'rental_agreement_id' => $a->id,
            'charge_category_id' => ChargeCategory::first()->id, 'label' => $label, 'kind' => $kind,
            'amount_cents' => $cents, 'bill_month' => '2026-11-01', 'created_by' => $this->owner->id,
        ]);
    }

    private function utility(string $type, string $mode, array $extra = [], ?Unit $unit = null): void
    {
        UnitUtility::create(array_merge(['unit_id' => ($unit ?? $this->unit)->id, 'type' => $type, 'mode' => $mode], $extra));
    }

    private function balance(RentalAgreement $a): int
    {
        return app(ReceivableLedgerService::class)->balance($a->billingAccount);
    }

    public function test_acceptance_invoice_4150_ledger_charge_and_balance_5950(): void
    {
        $a = $this->agreement(350000, ['due_day' => 12]);
        $this->recurring($a, 'Rice cooker', 20000);
        $this->utility('electricity', 'fixed', ['fixed_cents' => 30000]);
        $this->utility('water', 'fixed', ['fixed_cents' => 15000]);
        app(ReceivableLedgerService::class)->post($a->billingAccount, LedgerEntryType::Opening, 180000, now()->setDate(2026, 10, 1), $a, 'Opening');

        $batch = $this->service->generate(2026, 11, $this->owner);

        $invoice = Invoice::sole();
        $this->assertSame(415000, $invoice->current_charges_cents);
        $this->assertSame(InvoiceStatus::Finalized, $invoice->status);
        $this->assertSame('2026-11-12', $invoice->due_date->toDateString());
        $this->assertSame($this->property->code.'-202611-0001', $invoice->number);
        $this->assertSame("agreement:{$a->id}:cycle:2026-11-01", $invoice->idempotency_key);
        $this->assertSame(4, $invoice->lineItems()->count());
        $this->assertSame(415000, (int) $invoice->lineItems()->sum('amount_cents'));
        $charge = LedgerEntry::where('type', LedgerEntryType::Charge)->sole();
        $this->assertSame(415000, $charge->amount_cents);
        $this->assertSame($invoice->id, $charge->invoice_id);
        $this->assertSame($invoice->issue_date->toDateString(), $charge->effective_date->toDateString());
        $this->assertSame(595000, $this->balance($a));
        $this->assertSame(BatchItemStatus::Generated, $batch->items->sole()->status);
        $this->assertSame(1, $batch->counts['generated']);
        $this->assertSame('done', $batch->status);
    }

    public function test_generate_twice_creates_one_invoice_one_charge_and_consumes_one_time_once(): void
    {
        $a = $this->agreement();
        $cooker = $this->oneTime($a, 'charge', 20000, 'Rice cooker');

        $this->service->generate(2026, 11, $this->owner);
        $second = $this->service->generate(2026, 11, $this->owner);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Charge)->count());
        $this->assertSame(1, InvoiceLineItem::where('label', 'Rice cooker')->count());
        $this->assertSame(Invoice::sole()->id, $cooker->fresh()->invoice_id);
        $this->assertSame(BatchItemStatus::AlreadyBilled, $second->items->sole()->status);
        $this->assertSame(Invoice::sole()->id, $second->items->sole()->invoice_id);
    }

    public function test_retry_of_a_generated_item_does_not_duplicate(): void
    {
        $this->agreement();
        $item = $this->service->generate(2026, 11, $this->owner)->items->sole();

        $retried = $this->service->retry($item);

        $this->assertSame(BatchItemStatus::Generated, $retried->status);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_only_the_owner_can_generate_void_or_reissue(): void
    {
        $this->agreement();
        $staff = User::factory()->create();

        try {
            $this->service->generate(2026, 11, $staff);
            $this->fail('Staff generated bills.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->service->generate(2026, 11, $this->owner);
        $this->expectException(HttpException::class);
        $this->service->void(Invoice::sole(), 'Wrong rent entered', $staff);
    }

    public function test_missing_rent_rate_is_needs_review_and_no_invoice(): void
    {
        $this->agreement(0);

        $item = $this->service->generate(2026, 11, $this->owner)->items->sole();

        $this->assertSame(BatchItemStatus::NeedsReview, $item->status);
        $this->assertStringContainsString('no_rent_rate', $item->message);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_agreement_without_a_cycle_this_month_is_not_in_the_batch(): void
    {
        $this->agreement(350000, ['start_date' => '2026-01-01', 'end_date' => '2026-03-31']);

        $this->assertSame(0, $this->service->generate(2026, 11, $this->owner)->items()->count());
    }

    public function test_over_100_agreements_two_properties_no_leakage_and_one_failure_isolated_then_retried(): void
    {
        $other = Property::factory()->create();
        $all = [];
        for ($i = 0; $i < 110; $i++) {
            $property = $i % 2 === 0 ? $this->property : $other;
            $unit = Unit::factory()->for($property)->create(['rent_mode' => RentMode::Whole, 'capacity' => 2]);
            $a = $this->agreement(300000 + $i * 100, ['due_day' => 1 + $i % 28], $unit, $property);
            $this->recurring($a, "Addon {$a->id}", 1000 + $i);
            $all[$a->id] = $i;
        }
        // Agreement 7's unit has a meter reading that goes backwards: its utility line cannot be built.
        $bad = RentalAgreement::findOrFail(array_keys($all)[7]);
        $this->utility('electricity', 'metered', [], $bad->unit);
        $badBill = UtilityBill::create(['unit_id' => $bad->unit_id, 'type' => 'electricity', 'bill_month' => '2026-11-01', 'previous_reading_e3' => 500000, 'current_reading_e3' => 100000, 'rate_e4' => 100000, 'base_charge_cents' => 0, 'amount_cents' => 0, 'status' => 'draft']);

        $batch = $this->service->generate(2026, 11, $this->owner);

        $this->assertSame(109, Invoice::count());
        $this->assertSame(109, LedgerEntry::where('type', LedgerEntryType::Charge)->count());
        $this->assertSame(109, $batch->counts['generated']);
        $this->assertSame(1, $batch->counts['failed']);
        $failed = $batch->items()->where('status', 'failed')->sole();
        $this->assertSame($bad->id, $failed->rental_agreement_id);
        $this->assertNotEmpty($failed->message);
        $this->assertSame(0, Invoice::where('rental_agreement_id', $bad->id)->count());
        $this->assertSame(0, LedgerEntry::where('billing_account_id', $bad->billing_account_id)->count());

        foreach (Invoice::with('lineItems')->get() as $invoice) {
            $i = $all[$invoice->rental_agreement_id];
            $this->assertSame([300000 + $i * 100, 1000 + $i], $invoice->lineItems->pluck('amount_cents')->all());
            $this->assertSame(array_sum($invoice->lineItems->pluck('amount_cents')->all()), $invoice->current_charges_cents);
            $this->assertSame($invoice->rentalAgreement->unit->property_id, $invoice->property_id);
            $this->assertStringStartsWith($invoice->property->code.'-202611-', $invoice->number);
        }
        $this->assertSame(Invoice::count(), Invoice::distinct()->count('number'));

        // Fix the reading and retry just that item.
        $badBill->update(['previous_reading_e3' => 100000, 'current_reading_e3' => 150000]);
        $retried = $this->service->retry($failed);

        $this->assertSame(BatchItemStatus::Generated, $retried->status);
        $this->assertNotNull($retried->invoice_id);
        $this->assertSame(110, Invoice::count());
        $this->assertSame('billed', $badBill->fresh()->status);
    }

    public function test_rent_change_after_finalization_keeps_old_lines_and_applies_next_month(): void
    {
        $a = $this->agreement(350000);
        $this->service->generate(2026, 11, $this->owner);

        app(TenancyService::class)->changeRent($a, 400000, Carbon::parse('2026-12-01'), 'Annual increase');
        $this->service->generate(2026, 12, $this->owner);

        $nov = Invoice::whereDate('period_start', '2026-11-01')->sole();
        $dec = Invoice::whereDate('period_start', '2026-12-01')->sole();
        $this->assertSame(350000, $nov->current_charges_cents);
        $this->assertSame([350000], $nov->lineItems->pluck('amount_cents')->all());
        $this->assertSame(400000, $dec->current_charges_cents);
        $this->assertSame($this->property->code.'-202612-0001', $dec->number);
    }

    public function test_changing_rent_inside_an_invoiced_period_is_rejected(): void
    {
        $a = $this->agreement(350000);
        $this->service->generate(2026, 11, $this->owner);

        try {
            app(TenancyService::class)->changeRent($a, 400000, Carbon::parse('2026-11-10'), 'Mid-month');
            $this->fail('Rent changed inside an invoiced period.');
        } catch (ValidationException $e) {
            $this->assertSame('A bill was already issued for this period — void it first or pick a later date.', $e->errors()['effective_from'][0]);
        }
        $this->assertSame(1, RentRate::where('rental_agreement_id', $a->id)->count());

        // Once the invoice is voided the date is free again.
        $this->service->void(Invoice::sole(), 'Wrong rent entered', $this->owner);
        app(TenancyService::class)->changeRent($a, 400000, Carbon::parse('2026-11-10'), 'Mid-month');
        $this->assertSame(2, RentRate::where('rental_agreement_id', $a->id)->count());
    }

    public function test_move_out_and_transfer_inside_an_invoiced_period_are_rejected(): void
    {
        $a = $this->agreement(350000);
        $other = Unit::factory()->for($this->property)->create(['rent_mode' => RentMode::Whole, 'capacity' => 2]);
        $this->service->generate(2026, 11, $this->owner);
        $message = 'A bill was already issued for this period — void it first or pick a later date.';

        try {
            app(TenancyService::class)->moveOut($a, Carbon::parse('2026-11-20'));
            $this->fail('Moved out inside an invoiced period.');
        } catch (ValidationException $e) {
            $this->assertSame($message, collect($e->errors())->flatten()->first());
        }
        try {
            app(TenancyService::class)->transfer($a, $other, null, Carbon::parse('2026-11-15'));
            $this->fail('Transferred inside an invoiced period.');
        } catch (ValidationException $e) {
            $this->assertSame($message, collect($e->errors())->flatten()->first());
        }
        $this->assertNull($a->fresh()->end_date);

        // The first day after the invoiced period is fine.
        app(TenancyService::class)->moveOut($a, Carbon::parse('2026-12-31'));
        $this->assertSame('2026-12-31', $a->fresh()->end_date->toDateString());
    }

    public function test_move_out_on_the_last_day_of_a_billed_period_is_allowed_but_earlier_is_not(): void
    {
        $a = $this->agreement(350000);
        $this->service->generate(2026, 11, $this->owner);

        try {
            app(TenancyService::class)->moveOut($a, Carbon::parse('2026-11-29'));
            $this->fail('Moved out before the end of an invoiced period.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('move_out_date', $e->errors());
        }

        app(TenancyService::class)->moveOut($a, Carbon::parse('2026-11-30'));
        $this->assertSame('2026-11-30', $a->fresh()->end_date->toDateString());
    }

    public function test_rent_change_boundaries_backdated_rejected_day_after_period_allowed(): void
    {
        $a = $this->agreement(350000);
        $this->service->generate(2026, 11, $this->owner);

        foreach (['2026-10-15', '2026-11-30'] as $date) {
            try {
                app(TenancyService::class)->changeRent($a, 400000, Carbon::parse($date), 'Backdated');
                $this->fail("Rent changed from {$date}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('effective_from', $e->errors());
            }
        }

        app(TenancyService::class)->changeRent($a, 400000, Carbon::parse('2026-12-01'), 'Next cycle');
        $this->assertSame(2, RentRate::where('rental_agreement_id', $a->id)->count());
    }

    public function test_recurring_charge_end_boundary_period_end_allowed_earlier_rejected(): void
    {
        $a = $this->agreement(350000);
        $charge = $this->recurring($a, 'Rice cooker', 20000);
        $this->service->generate(2026, 11, $this->owner);

        $this->post(route('recurring-charges.end', $charge), ['end_date' => '2026-11-29'])->assertSessionHasErrors('end_date');
        $this->post(route('recurring-charges.end', $charge), ['end_date' => '2026-11-30'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-30', $charge->fresh()->end_date->toDateString());
    }

    public function test_ending_a_recurring_charge_inside_an_invoiced_period_is_rejected(): void
    {
        $a = $this->agreement(350000);
        $charge = $this->recurring($a, 'Rice cooker', 20000);
        $this->service->generate(2026, 11, $this->owner);

        $this->post(route('recurring-charges.end', $charge), ['end_date' => '2026-11-20'])
            ->assertSessionHasErrors(['end_date' => 'A bill was already issued for this period — void it first or pick a later date.']);
        $this->assertNull($charge->fresh()->end_date);

        $this->post(route('recurring-charges.end', $charge), ['end_date' => '2026-12-31'])->assertSessionHasNoErrors();
    }

    public function test_one_time_charge_for_an_invoiced_month_is_rejected(): void
    {
        $a = $this->agreement(350000);
        $this->service->generate(2026, 11, $this->owner);

        $this->post(route('one-time-charges.store'), [
            'billing_account_id' => $a->billing_account_id, 'charge_category_id' => ChargeCategory::first()->id,
            'label' => 'Late', 'kind' => 'charge', 'amount' => '100.00', 'bill_month' => '2026-11',
        ])->assertSessionHasErrors('bill_month');
        $this->assertSame(0, OneTimeCharge::count());

        $this->post(route('one-time-charges.store'), [
            'billing_account_id' => $a->billing_account_id, 'charge_category_id' => ChargeCategory::first()->id,
            'label' => 'Next', 'kind' => 'charge', 'amount' => '100.00', 'bill_month' => '2026-12',
        ])->assertSessionHasNoErrors();
    }

    public function test_void_reverses_the_charge_keeps_number_and_lines_and_drops_balance(): void
    {
        $a = $this->agreement(350000);
        $this->recurring($a, 'Rice cooker', 20000);
        app(ReceivableLedgerService::class)->post($a->billingAccount, LedgerEntryType::Opening, 180000, now()->setDate(2026, 10, 1), $a, 'Opening');
        $this->service->generate(2026, 11, $this->owner);
        $invoice = Invoice::sole();
        $this->assertSame(550000, $this->balance($a));

        $this->service->void($invoice, 'Wrong rent entered', $this->owner);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Void, $invoice->status);
        $this->assertSame('Wrong rent entered', $invoice->void_reason);
        $this->assertSame($this->owner->id, $invoice->voided_by);
        $this->assertNotNull($invoice->voided_at);
        $this->assertSame(2, $invoice->lineItems()->count());
        $this->assertSame(180000, $this->balance($a));
        $reversal = LedgerEntry::where('type', LedgerEntryType::Reversal)->sole();
        $this->assertSame(-370000, $reversal->amount_cents);
        $this->assertSame($invoice->id, $reversal->invoice_id);
    }

    public function test_void_needs_a_reason_and_only_works_once(): void
    {
        $this->agreement();
        $this->service->generate(2026, 11, $this->owner);
        $invoice = Invoice::sole();

        try {
            $this->service->void($invoice, 'no', $this->owner);
            $this->fail('Short reason accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->assertSame(InvoiceStatus::Finalized, $invoice->fresh()->status);

        $this->service->void($invoice, 'Entered by mistake', $this->owner);
        try {
            $this->service->void($invoice->fresh(), 'Entered by mistake', $this->owner);
            $this->fail('Voided twice.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice', $e->errors());
        }
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Reversal)->count());
    }

    public function test_reissue_makes_a_new_invoice_with_new_number_and_key_and_does_not_rebill_one_time_charges(): void
    {
        $a = $this->agreement(350000);
        $charge = $this->oneTime($a, 'charge', 20000);
        $this->service->generate(2026, 11, $this->owner);
        $old = Invoice::sole();
        $this->assertSame(370000, $old->current_charges_cents);
        $this->service->void($old, 'Wrong rent entered', $this->owner);

        $new = $this->service->reissue($old->fresh(), $this->owner)->items->sole();

        $this->assertSame(BatchItemStatus::Generated, $new->status);
        $invoice = $new->invoice;
        $this->assertNotSame($old->number, $invoice->number);
        $this->assertSame($old->idempotency_key.':reissue:1', $invoice->idempotency_key);
        $this->assertSame(350000, $invoice->current_charges_cents);
        $this->assertSame($old->id, $charge->fresh()->invoice_id, 'The one-time charge stays on the void invoice.');
        $this->assertSame(350000, $this->balance($a));

        // A second void + reissue gets the next suffix.
        $this->service->void($invoice, 'Wrong again', $this->owner);
        $again = $this->service->reissue($invoice->fresh(), $this->owner)->items->sole()->invoice;
        $this->assertSame($old->idempotency_key.':reissue:2', $again->idempotency_key);
    }

    public function test_reissue_of_a_finalized_invoice_is_rejected(): void
    {
        $this->agreement();
        $this->service->generate(2026, 11, $this->owner);

        $this->expectException(ValidationException::class);
        $this->service->reissue(Invoice::sole(), $this->owner);
    }

    public function test_generate_after_a_void_reissues_automatically_and_twice_is_idempotent(): void
    {
        $this->agreement();
        $this->service->generate(2026, 11, $this->owner);
        $this->service->void(Invoice::sole(), 'Wrong rent entered', $this->owner);

        $this->service->generate(2026, 11, $this->owner);
        $this->service->generate(2026, 11, $this->owner);

        $this->assertSame(2, Invoice::count());
        $this->assertSame(1, Invoice::where('status', 'finalized')->count());
    }

    public function test_credits_exceeding_charges_make_a_credit_entry_and_zero_total_posts_nothing(): void
    {
        $a = $this->agreement(350000);
        $this->oneTime($a, 'credit', 500000, 'Goodwill');
        $b = $this->agreement(350000);
        $this->oneTime($b, 'credit', 350000, 'Free month');

        $this->service->generate(2026, 11, $this->owner);

        $invoiceA = Invoice::where('rental_agreement_id', $a->id)->sole();
        $this->assertSame(-150000, $invoiceA->current_charges_cents);
        $entry = LedgerEntry::where('billing_account_id', $a->billing_account_id)->sole();
        $this->assertSame(LedgerEntryType::Credit, $entry->type);
        $this->assertSame(-150000, $entry->amount_cents);
        $this->assertSame(-150000, $this->balance($a));

        $invoiceB = Invoice::where('rental_agreement_id', $b->id)->sole();
        $this->assertSame(0, $invoiceB->current_charges_cents);
        $this->assertSame(0, LedgerEntry::where('billing_account_id', $b->billing_account_id)->count());
    }

    public function test_utility_allocations_are_persisted_then_bill_is_billed_once_for_two_agreements_of_a_unit(): void
    {
        $a = $this->agreement(350000);
        $b = $this->agreement(350000);
        $this->utility('water', 'shared', ['split_method' => 'equal']);
        $bill = UtilityBill::create(['unit_id' => $this->unit->id, 'type' => 'water', 'bill_month' => '2026-11-01', 'base_charge_cents' => 0, 'amount_cents' => 100001, 'status' => 'draft']);

        $this->service->generate(2026, 11, $this->owner, null, [$a->id]);

        $this->assertSame('billed', $bill->fresh()->status);
        $allocations = UtilityAllocation::where('utility_bill_id', $bill->id)->orderBy('rental_agreement_id')->get();
        $this->assertSame([$a->id, $b->id], $allocations->pluck('rental_agreement_id')->all());
        $this->assertSame(100001, $allocations->sum('amount_cents'));

        // The other agreement is generated later: allocations are not duplicated, its share matches.
        $this->service->generate(2026, 11, $this->owner, null, [$b->id]);

        $this->assertSame(2, UtilityAllocation::where('utility_bill_id', $bill->id)->count());
        $lineA = Invoice::where('rental_agreement_id', $a->id)->sole()->lineItems->firstWhere('category', 'utility');
        $lineB = Invoice::where('rental_agreement_id', $b->id)->sole()->lineItems->firstWhere('category', 'utility');
        $this->assertSame($allocations[0]->amount_cents, $lineA->amount_cents);
        $this->assertSame($allocations[1]->amount_cents, $lineB->amount_cents);
        $this->assertSame(100001, $lineA->amount_cents + $lineB->amount_cents);
    }

    public function test_property_filter_and_agreement_filter_limit_the_batch(): void
    {
        $other = Property::factory()->create();
        $a = $this->agreement();
        $this->agreement(350000, [], Unit::factory()->for($other)->create(['rent_mode' => RentMode::Whole, 'capacity' => 2]), $other);

        $this->service->generate(2026, 11, $this->owner, $this->property->id);

        $this->assertSame([$a->id], Invoice::pluck('rental_agreement_id')->all());
    }

    public function test_job_generates_and_is_unique_per_month_and_property(): void
    {
        $this->agreement();
        $job = new GenerateMonthlyBillsJob(2026, 11, $this->owner->id, $this->property->id);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('2026-11-'.$this->property->id, $job->uniqueId());

        $job->handle($this->service);
        $job->handle($this->service);

        $this->assertSame(1, Invoice::count());
    }
}
