<?php

namespace Tests\Feature\Billing;

use App\Enums\RentMode;
use App\Models\BillingAccount;
use App\Models\ChargeCategory;
use App\Models\Invoice;
use App\Models\OneTimeCharge;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\RentRate;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Billing\BillingPreviewService;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\InvoiceStatusService;
use App\Services\Billing\OpeningBalanceService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use App\Services\Billing\Statement;
use App\Services\Billing\StatementComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Every payment situation a boarding house meets, run through the real engine (generate bills, record payments,
 * read the statement). One boarder, ₱700 rent a month starting September 2026.
 *
 * In every case three things must agree: the bill preview, the generated statement's "Total amount due",
 * and the account's ledger balance.
 */
class PaymentScenariosTest extends TestCase
{
    use RefreshDatabase;

    private const RENT = 70000;

    private User $owner;

    private BillingAccount $account;

    private RentalAgreement $agreement;

    private PaymentService $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-08-20');
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        $this->payments = app(PaymentService::class);

        $property = Property::factory()->create();
        $unit = Unit::factory()->for($property)->create(['rent_mode' => RentMode::Whole, 'capacity' => 1]);
        $payer = Tenant::factory()->create(['property_id' => $property->id]);
        $this->account = BillingAccount::factory()->for($property)->create(['payer_tenant_id' => $payer->id]);
        $this->agreement = RentalAgreement::factory()->for($this->account, 'billingAccount')->for($unit)->create(['start_date' => '2026-09-01']);
        RentRate::create(['rental_agreement_id' => $this->agreement->id, 'rent_cents' => self::RENT, 'effective_from' => '2026-09-01']);
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    private function at(string $date): void
    {
        $this->travelTo(Carbon::parse($date.' 09:00', 'Asia/Manila'));
    }

    private function bill(int $month): Invoice
    {
        app(InvoiceGenerationService::class)->generate(2026, $month, $this->owner, null, null, [$this->account->id]);

        return $this->invoice($month);
    }

    private function invoice(int $month): Invoice
    {
        return Invoice::where('billing_account_id', $this->account->id)->whereMonth('period_start', $month)
            ->where('status', 'finalized')->firstOrFail();
    }

    private function pay(int $cents, string $on, ?User $by = null): Payment
    {
        return $this->payments->record($this->account, ['amount_cents' => $cents, 'paid_on' => $on, 'method' => 'cash'], $by ?? $this->owner);
    }

    private function statement(int $month): Statement
    {
        return app(StatementComposer::class)->forInvoice($this->invoice($month));
    }

    private function billStatus(int $month): string
    {
        return app(InvoiceStatusService::class)->statusFor($this->invoice($month));
    }

    private function balance(): int
    {
        return app(ReceivableLedgerService::class)->balance($this->account);
    }

    private function preview(int $month): int
    {
        return app(BillingPreviewService::class)->preview(2026, $month, null, null, $this->owner, [$this->account->id])['rows'][0]->estimatedTotalCents;
    }

    /** Preview before generating == statement after generating == what the account owes. */
    private function billAndCheck(int $month, int $expectedTotal): Statement
    {
        $this->assertSame($expectedTotal, $this->preview($month), "preview for month $month");
        $this->bill($month);
        $statement = $this->statement($month);
        $this->assertSame($expectedTotal, $statement->totalDueCents, "statement total for month $month");
        $this->assertSame($expectedTotal, $this->balance(), "ledger balance after billing month $month");
        $this->assertSame(
            $statement->previousCents + $statement->paymentsSinceCents + $statement->currentCents,
            $statement->totalDueCents,
            'the statement lines add up',
        );

        return $statement;
    }

    // ---- scenarios -----------------------------------------------------------------------------------------------

    public function test_no_payment_the_debt_adds_up_and_the_old_bill_turns_overdue(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-10');
        $this->assertSame('overdue', $this->billStatus(9));

        $this->billAndCheck(10, 140000);
        $this->billAndCheck(11, 210000);
    }

    public function test_exact_payment_clears_the_bill(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-03');
        $this->pay(70000, '2026-09-03');

        $this->assertSame('paid', $this->billStatus(9));
        $this->billAndCheck(10, 70000);
    }

    public function test_partial_payment_carries_the_rest_to_the_next_bill(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-03');
        $this->pay(50000, '2026-09-03');

        $this->assertSame('partial', $this->billStatus(9));
        $s = $this->billAndCheck(10, 90000); // ₱200 left + ₱700
        $this->assertSame(20000, $s->previousCents);
        $this->assertSame(20000, $s->pastDueCents, 'the unpaid ₱200 is past due');
    }

    public function test_several_small_payments_in_one_month_add_up(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-04');
        $this->pay(30000, '2026-09-02');
        $this->pay(25000, '2026-09-03');
        $this->assertSame('partial', $this->billStatus(9));
        $this->pay(15000, '2026-09-04');

        $this->assertSame('paid', $this->billStatus(9));
        $this->billAndCheck(10, 70000);
    }

    public function test_advance_payment_before_any_bill_covers_the_coming_months(): void
    {
        $this->pay(140000, '2026-08-20'); // two months paid before moving in

        $this->billAndCheck(9, -70000); // September covered, ₱700 still in advance
        $this->assertSame('paid', $this->billStatus(9));
        $this->billAndCheck(10, 0); // October covered by the rest
        $this->assertSame('paid', $this->billStatus(10));
        $this->billAndCheck(11, 70000); // advance used up
        $this->assertSame('unpaid', $this->billStatus(11));
    }

    public function test_advance_that_is_not_a_whole_month_leaves_the_rest_to_pay(): void
    {
        $this->pay(100000, '2026-08-20'); // ₱1,000: September + ₱300 toward October

        $this->billAndCheck(9, -30000); // September covered, ₱300 credit left
        $this->billAndCheck(10, 40000);
        $this->assertSame('partial', $this->billStatus(10));
    }

    public function test_overpayment_becomes_credit_on_the_next_bill(): void
    {
        $this->billAndCheck(9, 70000);
        $this->billAndCheck(10, 140000);
        $this->at('2026-10-10');
        $this->pay(170000, '2026-10-10');

        $s = $this->billAndCheck(11, 40000);
        $this->assertSame(-30000, $s->previousCents);
        $this->assertSame('paid', $this->billStatus(9));
        $this->assertSame('paid', $this->billStatus(10));
        $this->assertSame('partial', $this->billStatus(11));
    }

    public function test_payment_goes_to_the_oldest_bill_first(): void
    {
        $this->billAndCheck(9, 70000);
        $this->billAndCheck(10, 140000);
        $this->at('2026-10-02');
        $this->pay(70000, '2026-10-02');

        $this->assertSame('paid', $this->billStatus(9));
        $this->assertSame('unpaid', $this->billStatus(10));
    }

    public function test_late_payment_after_the_due_date_still_counts(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-25');
        $this->assertSame('overdue', $this->billStatus(9));
        $this->pay(70000, '2026-09-25');

        $this->assertSame('paid', $this->billStatus(9));
        $this->billAndCheck(10, 70000);
    }

    public function test_payment_recorded_after_the_bill_was_made_shows_under_payments_received(): void
    {
        $this->billAndCheck(9, 70000);
        $this->billAndCheck(10, 140000);
        $this->at('2026-10-03');
        $this->pay(100000, '2026-10-03');

        $s = $this->statement(10);
        $this->assertSame(70000, $s->previousCents);
        $this->assertSame(-100000, $s->paymentsSinceCents);
        $this->assertSame(40000, $s->totalDueCents);
        $this->assertSame(40000, $this->balance());
    }

    public function test_bounced_payment_reversal_puts_the_debt_back(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-03');
        $payment = $this->pay(70000, '2026-09-03');
        $this->assertSame('paid', $this->billStatus(9));

        $this->payments->reverse($payment, 'Cheque bounced');

        $this->assertSame('unpaid', $this->billStatus(9));
        $this->billAndCheck(10, 140000);
    }

    public function test_reversing_an_overpayment_removes_the_credit(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-03');
        $payment = $this->pay(100000, '2026-09-03');
        $this->payments->reverse($payment, 'Recorded by mistake');

        $this->billAndCheck(10, 140000);
    }

    public function test_refund_of_credit_takes_it_off_the_next_bill(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-03');
        $this->pay(100000, '2026-09-03'); // ₱300 extra
        $this->payments->refund($this->account, 30000, 'Gave back the extra');

        $this->billAndCheck(10, 70000);
    }

    public function test_refund_cannot_exceed_the_credit(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-03');
        $this->pay(100000, '2026-09-03'); // ₱300 credit

        $this->expectException(ValidationException::class);
        $this->payments->refund($this->account, 30001, 'Too much');
    }

    public function test_staff_payment_counts_only_after_the_owner_approves_it(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-03');
        $staff = User::factory()->create(); // staff by default
        $payment = $this->pay(70000, '2026-09-03', $staff);

        $this->assertSame('pending', $payment->status);
        $this->assertSame('unpaid', $this->billStatus(9));
        $this->assertSame(70000, $this->balance());

        $this->payments->approve($payment, $this->owner);
        $this->assertSame('paid', $this->billStatus(9));
        $this->billAndCheck(10, 70000);
    }

    public function test_voiding_a_paid_bill_turns_the_payment_into_credit(): void
    {
        $this->billAndCheck(9, 70000);
        $this->at('2026-09-03');
        $this->pay(70000, '2026-09-03');

        app(InvoiceGenerationService::class)->void($this->invoice(9), 'Wrong rent amount', $this->owner);

        $this->assertSame(-70000, $this->balance(), 'the ₱700 paid is now credit');
        $this->billAndCheck(9, 0); // billed again: covered by the credit
        $this->assertSame('paid', $this->billStatus(9));
    }

    public function test_discount_bigger_than_the_bill_leaves_credit(): void
    {
        OneTimeCharge::create([
            'billing_account_id' => $this->account->id, 'rental_agreement_id' => $this->agreement->id,
            'charge_category_id' => ChargeCategory::query()->value('id'), 'label' => 'Promo', 'kind' => 'credit',
            'amount_cents' => 100000, 'bill_month' => '2026-09-01', 'created_by' => $this->owner->id,
        ]);

        $this->billAndCheck(9, -30000);
        $this->assertSame('paid', $this->billStatus(9));
        $this->billAndCheck(10, 40000);
    }

    public function test_discount_equal_to_the_bill_makes_a_zero_bill_that_reads_paid(): void
    {
        OneTimeCharge::create([
            'billing_account_id' => $this->account->id, 'rental_agreement_id' => $this->agreement->id,
            'charge_category_id' => ChargeCategory::query()->value('id'), 'label' => 'Free month', 'kind' => 'credit',
            'amount_cents' => 70000, 'bill_month' => '2026-09-01', 'created_by' => $this->owner->id,
        ]);

        $this->billAndCheck(9, 0);
        $this->at('2026-09-20');
        $this->assertSame('paid', $this->billStatus(9), 'a ₱0 bill is never overdue');
        $this->billAndCheck(10, 70000);
    }

    public function test_extra_one_time_charge_is_added_to_the_bill(): void
    {
        OneTimeCharge::create([
            'billing_account_id' => $this->account->id, 'rental_agreement_id' => $this->agreement->id,
            'charge_category_id' => ChargeCategory::query()->value('id'), 'label' => 'Lost key', 'kind' => 'charge',
            'amount_cents' => 15000, 'bill_month' => '2026-09-01', 'created_by' => $this->owner->id,
        ]);

        $this->billAndCheck(9, 85000);
        $this->at('2026-09-03');
        $this->pay(85000, '2026-09-03');
        $this->assertSame('paid', $this->billStatus(9));
    }

    public function test_old_debt_from_before_the_system_is_paid_first_then_the_bill(): void
    {
        $obs = app(OpeningBalanceService::class);
        $ob = $obs->draft($this->account, 50000, Carbon::parse('2026-08-19'), null, null, 'Old unpaid rent');
        $obs->approve($ob);

        $s = $this->billAndCheck(9, 120000);
        $this->assertSame(50000, $s->previousCents);

        $this->at('2026-09-03');
        $this->pay(60000, '2026-09-03'); // ₱500 clears the old debt, ₱100 goes to September
        $this->assertSame('partial', $this->billStatus(9));
        $this->billAndCheck(10, 130000);
    }

    public function test_paying_everything_owed_in_one_go_clears_every_bill(): void
    {
        $this->billAndCheck(9, 70000);
        $this->billAndCheck(10, 140000);
        $this->billAndCheck(11, 210000);
        $this->at('2026-11-03');
        $this->pay(210000, '2026-11-03');

        foreach ([9, 10, 11] as $m) {
            $this->assertSame('paid', $this->billStatus($m), "month $m");
        }
        $this->assertSame(0, $this->balance());
        $this->billAndCheck(12, 70000);
    }

    public function test_zero_or_future_payments_are_refused(): void
    {
        $this->at('2026-09-03');
        try {
            $this->pay(0, '2026-09-03');
            $this->fail('zero payment accepted');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $this->pay(70000, '2026-09-04');
    }
}
