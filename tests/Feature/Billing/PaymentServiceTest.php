<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Property;
use App\Models\RentalAgreement;
use App\Models\User;
use App\Services\Billing\InvoiceGenerationService;
use App\Services\Billing\InvoiceStatusService;
use App\Services\Billing\OpeningBalanceService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private BillingAccount $account;

    private User $owner;

    private PaymentService $service;

    private ReceivableLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->property = Property::factory()->create(['code' => 'JT1']);
        $this->account = BillingAccount::factory()->for($this->property)->create();
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        $this->service = app(PaymentService::class);
        $this->ledger = app(ReceivableLedgerService::class);
    }

    private function opening(int $cents = 180000, string $date = '2026-10-01'): LedgerEntry
    {
        return $this->ledger->post($this->account, LedgerEntryType::Opening, $cents, Carbon::parse($date), $this->account, 'Opening');
    }

    /** @return array{0: Invoice, 1: LedgerEntry} */
    private function invoice(int $cents = 415000, string $due = '2026-11-20', string $effective = '2026-11-01'): array
    {
        $agreement = RentalAgreement::factory()->for($this->account, 'billingAccount')->create();
        $invoice = Invoice::create([
            'billing_account_id' => $this->account->id, 'rental_agreement_id' => $agreement->id, 'property_id' => $this->property->id,
            'number' => 'N-'.uniqid(), 'period_start' => $effective, 'period_end' => '2026-11-30', 'issue_date' => $effective, 'due_date' => $due,
            'status' => InvoiceStatus::Finalized, 'current_charges_cents' => $cents, 'finalized_at' => now(),
            'idempotency_key' => 'k-'.uniqid(),
        ]);
        $charge = $this->ledger->post($this->account, LedgerEntryType::Charge, $cents, Carbon::parse($effective), $invoice);

        return [$invoice, $charge];
    }

    private function pay(int $cents, array $extra = [], ?User $by = null): Payment
    {
        return $this->service->record($this->account, array_merge([
            'amount_cents' => $cents, 'paid_on' => '2026-11-16', 'method' => 'cash',
        ], $extra), $by ?? $this->owner);
    }

    public function test_acceptance_pay_500_against_opening_and_invoice(): void
    {
        $opening = $this->opening();
        $this->invoice();

        $payment = $this->pay(50000);

        $this->assertSame(545000, $this->ledger->balance($this->account));
        $this->assertEquals(50000, PaymentAllocation::where('ledger_entry_id', $opening->id)->sum('amount_cents'));
        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Payment)->count());
        $this->assertSame(-50000, LedgerEntry::where('type', LedgerEntryType::Payment)->value('amount_cents'));
        $this->assertSame('approved', $payment->status);
    }

    public function test_oldest_first_across_opening_and_two_invoices(): void
    {
        $opening = $this->opening(180000, '2026-09-30');
        [, $c1] = $this->invoice(100000, '2026-10-05', '2026-10-01');
        [, $c2] = $this->invoice(200000, '2026-11-05', '2026-11-01');

        $payment = $this->pay(350000);

        $by = $payment->allocations()->pluck('amount_cents', 'ledger_entry_id');
        $this->assertSame(180000, $by[$opening->id]);
        $this->assertSame(100000, $by[$c1->id]);
        $this->assertSame(70000, $by[$c2->id]);
    }

    public function test_overpay_leaves_credit_and_unallocated_remainder(): void
    {
        $this->opening(100000);

        $payment = $this->pay(150000);

        $this->assertSame(-50000, $this->ledger->balance($this->account));
        $this->assertEquals(100000, $payment->allocations()->sum('amount_cents'));
        $this->assertSame(50000, $this->service->unallocated($payment));
    }

    public function test_partial_payment_marks_invoice_partial_then_paid(): void
    {
        [$invoice] = $this->invoice(100000);
        $statuses = app(InvoiceStatusService::class);
        $this->assertSame('unpaid', $statuses->statusFor($invoice));

        $this->pay(40000);
        $this->assertSame('partial', $statuses->statusFor($invoice));

        $this->pay(60000);
        $this->assertSame('paid', $statuses->statusFor($invoice));
    }

    public function test_overdue_and_void_statuses(): void
    {
        [$late] = $this->invoice(100000, '2026-11-10');
        [$void] = $this->invoice(100000, '2026-11-30');
        $statuses = app(InvoiceStatusService::class);
        $this->assertSame('overdue', $statuses->statusFor($late));

        $this->ledger->reverse(LedgerEntry::where('invoice_id', $void->id)->first(), 'void');
        $void->update(['status' => InvoiceStatus::Void, 'voided_at' => now(), 'void_reason' => 'x']);
        $this->assertSame('void', $statuses->statusFor($void->fresh()));
    }

    public function test_manual_allocation_overrides_oldest_first(): void
    {
        $opening = $this->opening();
        [, $charge] = $this->invoice(415000);
        $payment = $this->service->record($this->account, ['amount_cents' => 100000, 'paid_on' => '2026-11-16', 'method' => 'cash',
            'allocations' => [$charge->id => 100000]], $this->owner);

        $this->assertSame([$charge->id => 100000], $payment->allocations()->pluck('amount_cents', 'ledger_entry_id')->all());
        $this->assertSame(180000, $this->service->remaining($opening));
    }

    public function test_allocations_never_exceed_payment_or_debit(): void
    {
        $this->opening(100000);
        [, $charge] = $this->invoice(50000);
        $payment = $this->pay(30000, ['allocations' => []]);
        $debit = LedgerEntry::where('type', LedgerEntryType::Opening)->first();

        try {
            $this->service->allocate($payment, [$charge->id => 30000]);
            $this->fail('payment is already fully allocated');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }

        $second = $this->pay(500000, ['allocations' => [$debit->id => 70000]]);
        try {
            $this->service->allocate($second, [$debit->id => 1]);
            $this->fail('debit is already covered');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('allocations', $e->errors());
        }
        $this->assertSame(0, $this->service->remaining($debit));
        // 70,000 manual, then the remainder is auto-applied to the invoice (50,000 owed).
        $this->assertEquals(120000, $second->allocations()->sum('amount_cents'));
    }

    public function test_reverse_restores_invoice_status_and_keeps_allocation_rows(): void
    {
        [$invoice] = $this->invoice(100000);
        $payment = $this->pay(100000);
        $statuses = app(InvoiceStatusService::class);
        $this->assertSame('paid', $statuses->statusFor($invoice));

        $this->service->reverse($payment, 'Bounced');

        $this->assertSame('unpaid', $statuses->statusFor($invoice));
        $this->assertSame(100000, $this->ledger->balance($this->account));
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame(1, PaymentAllocation::whereNotNull('reversed_at')->count());
        $this->assertSame(1, PaymentAllocation::count());
    }

    public function test_reversing_twice_is_rejected(): void
    {
        $this->opening();
        $payment = $this->pay(1000);
        $this->service->reverse($payment, 'x');

        $this->expectException(ValidationException::class);
        $this->service->reverse($payment, 'x');
    }

    public function test_staff_payment_is_pending_and_not_on_the_ledger_until_approved(): void
    {
        $this->opening();
        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);

        $payment = $this->pay(50000, [], $staff);

        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntryType::Payment)->count());
        $this->assertSame(180000, $this->ledger->balance($this->account));
        $this->assertNull($payment->ack_number);

        $this->service->approve($payment, $this->owner);

        $this->assertSame(130000, $this->ledger->balance($this->account));
        $this->assertSame('ACK-JT1-2026-00001', $payment->fresh()->ack_number);
        $this->assertSame($this->owner->id, $payment->fresh()->approved_by);
    }

    public function test_approving_twice_posts_once(): void
    {
        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $this->opening();
        $payment = $this->pay(50000, [], $staff);

        $this->service->approve($payment, $this->owner);
        $this->service->approve($payment, $this->owner);

        $this->assertSame(1, LedgerEntry::where('type', LedgerEntryType::Payment)->count());
        $this->assertEquals(50000, $payment->allocations()->sum('amount_cents'));
    }

    public function test_ack_numbers_increment_per_property_and_year(): void
    {
        $this->opening();
        $a = $this->pay(1000);
        $b = $this->pay(1000);

        $this->assertSame('ACK-JT1-2026-00001', $a->fresh()->ack_number);
        $this->assertSame('ACK-JT1-2026-00002', $b->fresh()->ack_number);
    }

    public function test_reference_must_be_unique_per_method(): void
    {
        $this->pay(1000, ['method' => 'gcash', 'reference' => 'R-1']);
        $this->pay(1000, ['method' => 'bank_transfer', 'reference' => 'R-1']);

        $this->expectException(ValidationException::class);
        $this->pay(1000, ['method' => 'gcash', 'reference' => 'R-1']);
    }

    public function test_null_references_do_not_collide(): void
    {
        $this->pay(1000);
        $this->pay(1000);

        $this->assertSame(2, Payment::count());
    }

    public function test_refund_is_limited_to_the_credit(): void
    {
        $this->opening(100000);
        $this->pay(150000);

        try {
            $this->service->refund($this->account, 50001, 'Too much');
            $this->fail('refund beyond the credit');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->service->refund($this->account, 50000, 'Moved out');
        $this->assertSame(0, $this->ledger->balance($this->account));
        $this->assertSame(50000, LedgerEntry::where('type', LedgerEntryType::Refund)->value('amount_cents'));
    }

    public function test_debts_can_no_longer_be_written_off(): void
    {
        // Write-off was removed: a debt is settled only by payments, credits or a bill correction.
        $this->assertFalse(method_exists($this->service, 'writeOff'));
    }

    public function test_future_dated_payment_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->pay(1000, ['paid_on' => '2026-11-17']);
    }

    public function test_voiding_an_invoice_releases_the_payment_allocation(): void
    {
        [$invoice] = $this->invoice(100000);
        $payment = $this->pay(100000);
        $this->assertSame(0, $this->service->unallocated($payment));

        app(InvoiceGenerationService::class)->void($invoice, 'Wrong amount', $this->owner);

        $this->assertSame(100000, $this->service->unallocated($payment));
        $this->assertSame(-100000, $this->ledger->balance($this->account));
        $opening = $this->opening(30000);
        $this->service->allocate($payment, [$opening->id => 30000]);
        $this->assertSame(70000, $this->service->unallocated($payment));
    }

    public function test_credit_is_applied_to_a_later_invoice(): void
    {
        $payment = $this->pay(150000);
        [$invoice] = $this->invoice(100000);

        $this->service->applyCredit($this->account);

        $this->assertSame('paid', app(InvoiceStatusService::class)->statusFor($invoice));
        $this->assertSame(50000, $this->service->unallocated($payment));
        $this->service->applyCredit($this->account);
        $this->assertEquals(100000, $payment->allocations()->sum('amount_cents'));
    }

    public function test_manual_partial_split_has_its_remainder_applied_oldest_first(): void
    {
        $opening = $this->opening(100000);
        [$invoice, $charge] = $this->invoice(100000);

        $payment = $this->pay(150000, ['allocations' => [$charge->id => 60000]]);

        $by = $payment->allocations()->pluck('amount_cents', 'ledger_entry_id');
        $this->assertSame(60000, $by[$charge->id]);
        $this->assertSame(90000, $by[$opening->id]);
        $this->assertSame('partial', app(InvoiceStatusService::class)->statusFor($invoice));
    }

    public function test_approving_an_opening_balance_applies_waiting_credit(): void
    {
        $payment = $this->pay(30000);
        $svc = app(OpeningBalanceService::class);

        $ob = $svc->draft($this->account, 100000, Carbon::parse('2026-10-31'), null, null);
        $svc->approve($ob);

        $this->assertSame(0, $this->service->unallocated($payment));
        $this->assertSame(70000, $this->ledger->balance($this->account));

        $svc->reverse($ob, 'Mistake');
        $this->assertSame(30000, $this->service->unallocated($payment));
    }

    public function test_reference_can_be_reused_after_the_payment_is_reversed(): void
    {
        $bounced = $this->pay(1000, ['method' => 'check', 'reference' => 'CHK-9']);
        $this->service->reverse($bounced, 'Bounced');

        $again = $this->pay(1000, ['method' => 'check', 'reference' => 'CHK-9']);

        $this->assertSame('approved', $again->status);
    }

    public function test_a_write_off_already_in_the_ledger_still_counts(): void
    {
        // Ledger entries are permanent: any write-off made before the feature was removed keeps settling its debt.
        [$invoice, $charge] = $this->invoice(100000);
        $this->pay(60000);
        $this->ledger->post($this->account, LedgerEntryType::Writeoff, -40000, today(), $charge, 'Forgiven before removal');

        $statuses = app(InvoiceStatusService::class);
        $this->assertSame('paid', $statuses->statusFor($invoice));
        $this->assertSame(40000, $statuses->writtenOffCents($invoice));
    }
}
