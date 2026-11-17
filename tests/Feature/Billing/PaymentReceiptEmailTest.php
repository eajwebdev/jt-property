<?php

namespace Tests\Feature\Billing;

use App\Enums\LedgerEntryType;
use App\Mail\PaymentReceiptMail;
use App\Models\ActivityLog;
use App\Models\BillingAccount;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** A payment's receipt can be emailed to the boarder (or any address the owner types), as a PDF attachment. */
class PaymentReceiptEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Property $property;

    private BillingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-17 09:00', 'Asia/Manila'));
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        $this->property = Property::factory()->create();
        $tenant = Tenant::factory()->create(['property_id' => $this->property->id, 'full_name' => 'DELA CRUZ, Juan', 'email' => 'juan@example.com']);
        $this->account = BillingAccount::factory()->for($this->property)->create(['payer_tenant_id' => $tenant->id]);
        app(ReceivableLedgerService::class)->post($this->account, LedgerEntryType::Opening, 140000, Carbon::parse('2026-10-31'), $this->account, 'Opening');
    }

    private function pay(?User $by = null): Payment
    {
        return app(PaymentService::class)->record($this->account, ['amount_cents' => 70000, 'paid_on' => '2026-11-17', 'method' => 'cash'], $by ?? $this->owner);
    }

    public function test_the_receipt_is_emailed_to_the_boarder_with_the_pdf_attached(): void
    {
        $payment = $this->pay();

        $this->post(route('payments.receipt.email', $payment))->assertRedirect()->assertSessionHas('success');

        Mail::assertQueued(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail) => $mail->hasTo('juan@example.com') && $mail->payment->is($payment));
        $payment->refresh();
        $this->assertNotNull($payment->receipt_emailed_at);
        $this->assertSame('juan@example.com', $payment->receipt_emailed_to);
        $this->assertTrue(ActivityLog::where('action', 'payment.receipt_emailed')->exists());
        $this->get(route('payments.show', $payment))->assertInertia(fn (Assert $p) => $p
            ->where('payment.receipt_emailed_to', 'juan@example.com')
            ->where('payment.tenant_email', 'juan@example.com'));
    }

    public function test_the_mail_carries_the_receipt_pdf_and_its_number(): void
    {
        config(['laravel-pdf.driver' => 'dompdf']);
        $payment = $this->pay();
        $mail = new PaymentReceiptMail($payment);

        $this->assertStringContainsString($payment->ack_number, $mail->envelope()->subject);
        $this->assertStringContainsString('₱700.00', $mail->render());
        $attachment = $mail->attachments()[0];
        $this->assertSame($payment->ack_number.'.pdf', $attachment->as);
        $bytes = $attachment->attachWith(fn ($path) => file_get_contents($path), fn ($data) => $data());
        $this->assertStringStartsWith('%PDF', (string) $bytes);
    }

    public function test_another_address_can_be_typed_and_must_be_valid(): void
    {
        $payment = $this->pay();

        $this->post(route('payments.receipt.email', $payment), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->post(route('payments.receipt.email', $payment), ['email' => 'parent@example.com'])->assertSessionHasNoErrors();

        Mail::assertQueued(PaymentReceiptMail::class, fn ($mail) => $mail->hasTo('parent@example.com'));
    }

    public function test_a_boarder_without_email_needs_an_address(): void
    {
        $this->account->payer->update(['email' => null]);
        $payment = $this->pay();

        $this->post(route('payments.receipt.email', $payment))->assertSessionHasErrors('email');
        Mail::assertNothingQueued();
    }

    public function test_no_receipt_yet_or_reversed_payment_cannot_be_emailed(): void
    {
        $staff = User::factory()->create();
        $staff->properties()->attach($this->property);
        $pending = $this->pay($staff);
        $this->post(route('payments.receipt.email', $pending))->assertSessionHasErrors('payment');

        $reversed = $this->pay();
        app(PaymentService::class)->reverse($reversed, 'Bounced');
        $this->post(route('payments.receipt.email', $reversed))->assertSessionHasErrors('payment');

        Mail::assertNothingQueued();
    }

    public function test_the_ledger_page_lists_every_payment_with_its_receipt_and_the_boarders_email(): void
    {
        $first = $this->pay();
        $second = $this->pay();
        $this->post(route('payments.receipt.email', $first));

        $this->get(route('accounts.statement', $this->account))->assertInertia(fn (Assert $p) => $p
            ->where('account.email', 'juan@example.com')
            ->where('balance_cents', 0)
            ->has('payments', 2)
            ->where('payments.0.id', $second->id)
            ->where('payments.0.receipt_number', $second->ack_number)
            ->where('payments.1.receipt_emailed_to', 'juan@example.com'));
    }

    public function test_staff_of_another_property_cannot_email_it(): void
    {
        $payment = $this->pay();
        $stranger = User::factory()->create();
        $stranger->properties()->attach(Property::factory()->create());

        $this->actingAs($stranger)->post(route('payments.receipt.email', $payment))->assertForbidden();
        Mail::assertNothingQueued();
    }
}
