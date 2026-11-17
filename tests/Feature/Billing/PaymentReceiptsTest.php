<?php

namespace Tests\Feature\Billing;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PaymentAcknowledgmentService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Every payment gets a receipt, and each boarder has a list (ledger) of all their payments with those receipts. */
class PaymentReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Property $property;

    private Tenant $tenant;

    private BillingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 09:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        $this->property = Property::factory()->create();
        $this->tenant = Tenant::factory()->create(['property_id' => $this->property->id, 'full_name' => 'DELA CRUZ, Juan']);
        $this->account = BillingAccount::factory()->for($this->property)->create(['payer_tenant_id' => $this->tenant->id]);
        app(ReceivableLedgerService::class)->post($this->account, LedgerEntryType::Opening, 140000, Carbon::parse('2026-10-31'), $this->account, 'Opening');
    }

    private function pay(int $cents, string $on, ?User $by = null): Payment
    {
        return app(PaymentService::class)->record($this->account, ['amount_cents' => $cents, 'paid_on' => $on, 'method' => 'cash'], $by ?? $this->owner);
    }

    public function test_recording_a_payment_issues_a_receipt_and_says_so(): void
    {
        $response = $this->post(route('accounts.payments.store', $this->account), ['amount' => '700', 'paid_on' => '2026-11-17', 'method' => 'cash']);

        $payment = Payment::sole();
        $this->assertNotNull($payment->ack_number);
        $response->assertRedirect(route('payments.show', $payment));
        $this->assertStringContainsString('Receipt '.$payment->ack_number, session('success'));
        $this->get(route('payments.show', $payment))->assertInertia(fn (Assert $p) => $p->where('payment.ack_number', $payment->ack_number));
    }

    public function test_every_payment_has_its_own_receipt_number_titled_payment_receipt(): void
    {
        $first = $this->pay(50000, '2026-11-10');
        $second = $this->pay(20000, '2026-11-17');

        $this->assertNotSame($first->ack_number, $second->ack_number);
        $html = app(PaymentAcknowledgmentService::class)->html($second);
        $this->assertStringContainsString('PAYMENT RECEIPT', $html);
        $this->assertStringContainsString('Receipt No.', $html);
        $this->assertStringContainsString($second->ack_number, $html);
        $this->assertStringContainsString('₱200.00', $html);
    }

    public function test_a_staff_payment_gets_its_receipt_when_the_owner_approves_it(): void
    {
        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $payment = $this->pay(70000, '2026-11-17', $staff);
        $this->assertNull($payment->ack_number);

        app(PaymentService::class)->approve($payment, $this->owner);

        $this->assertNotNull($payment->fresh()->ack_number);
    }

    public function test_the_tenant_page_lists_every_payment_with_its_receipt_newest_first(): void
    {
        $older = $this->pay(50000, '2026-11-10');
        $newer = $this->pay(20000, '2026-11-17');
        app(PaymentService::class)->reverse($older, 'Bounced cheque');

        $this->get(route('tenants.show', $this->tenant))->assertInertia(fn (Assert $p) => $p
            ->has('payments', 2)
            ->where('payments.0.id', $newer->id)
            ->where('payments.0.receipt_number', $newer->ack_number)
            ->where('payments.0.amount', '₱200.00')
            ->where('payments.0.status', 'approved')
            ->where('payments.0.account_no', $this->account->account_no)
            ->where('payments.1.id', $older->id)
            ->where('payments.1.status', 'reversed'));
    }

    public function test_payment_lines_in_the_ledger_link_to_their_receipt(): void
    {
        $payment = $this->pay(50000, '2026-11-10');

        $this->get(route('accounts.statement', ['account' => $this->account, 'from' => '2026-10-01', 'to' => '2026-11-17']))
            ->assertInertia(fn (Assert $p) => $p
                ->where('statement.rows.1.type', 'payment')
                ->where('statement.rows.1.payment_id', $payment->id)
                ->where('statement.rows.1.receipt_number', $payment->ack_number));
    }
}
