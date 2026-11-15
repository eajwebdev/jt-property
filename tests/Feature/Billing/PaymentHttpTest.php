<?php

namespace Tests\Feature\Billing;

use App\Enums\LedgerEntryType;
use App\Models\BillingAccount;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ReceivableLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PaymentHttpTest extends TestCase
{
    use RefreshDatabase;

    private Property $property;

    private BillingAccount $account;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->property = Property::factory()->create(['code' => 'JT1']);
        $this->account = BillingAccount::factory()->for($this->property)->create();
        $this->owner = User::factory()->owner()->create();
        $this->actingAs($this->owner);
        app(ReceivableLedgerService::class)->post($this->account, LedgerEntryType::Opening, 180000, Carbon::parse('2026-10-01'), $this->account, 'Opening');
    }

    private function payload(array $o = []): array
    {
        return array_merge(['amount' => '500', 'paid_on' => '2026-11-16', 'method' => 'cash'], $o);
    }

    private function staff(bool $assigned = true): User
    {
        $staff = User::factory()->create();
        if ($assigned) {
            $staff->properties()->attach($this->property);
        }

        return $staff;
    }

    public function test_owner_records_an_approved_payment_with_proof(): void
    {
        $this->post(route('accounts.payments.store', $this->account), $this->payload(['proof' => UploadedFile::fake()->image('p.jpg')]))->assertRedirect();

        $payment = Payment::firstOrFail();
        $this->assertSame('approved', $payment->status);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->assertSame(130000, app(ReceivableLedgerService::class)->balance($this->account));
    }

    public function test_staff_in_property_records_a_pending_payment(): void
    {
        $this->actingAs($this->staff());

        $this->post(route('accounts.payments.store', $this->account), $this->payload())->assertRedirect();

        $this->assertSame('pending', Payment::firstOrFail()->status);
        $this->assertSame(0, LedgerEntry::where('type', LedgerEntryType::Payment)->count());
    }

    public function test_staff_outside_the_property_and_accountant_cannot_record(): void
    {
        $this->actingAs($this->staff(false))->post(route('accounts.payments.store', $this->account), $this->payload())->assertForbidden();
        $this->actingAs(User::factory()->accountant()->create())->post(route('accounts.payments.store', $this->account), $this->payload())->assertForbidden();
        $this->assertSame(0, Payment::count());
    }

    public function test_only_owner_approves_reverses_refunds_and_writes_off(): void
    {
        $payment = app(PaymentService::class)->record($this->account, ['amount_cents' => 1000, 'paid_on' => '2026-11-16', 'method' => 'cash'], $this->staff());
        $debit = LedgerEntry::where('type', LedgerEntryType::Opening)->first();
        $this->actingAs($this->staff());

        $this->post(route('payments.approve', $payment))->assertForbidden();
        $this->post(route('payments.reverse', $payment), ['reason' => 'x'])->assertForbidden();
        $this->post(route('accounts.refunds.store', $this->account), ['amount' => '1', 'reason' => 'x'])->assertForbidden();
        $this->post(route('ledger-entries.write-off', $debit), ['amount' => '1', 'reason' => 'x'])->assertForbidden();

        $this->actingAs($this->owner)->post(route('payments.approve', $payment))->assertRedirect();
        $this->assertSame('approved', $payment->fresh()->status);
        $this->post(route('payments.reverse', $payment), ['reason' => 'Bounced'])->assertRedirect();
        $this->assertSame('reversed', $payment->fresh()->status);
    }

    public function test_write_off_and_refund_over_http(): void
    {
        $debit = LedgerEntry::where('type', LedgerEntryType::Opening)->first();
        $this->post(route('ledger-entries.write-off', $debit), ['amount' => '1,800', 'reason' => 'Forgiven'])->assertRedirect();
        $this->post(route('accounts.payments.store', $this->account), $this->payload())->assertRedirect();
        $this->post(route('accounts.refunds.store', $this->account), ['amount' => '500', 'reason' => 'Moved out'])->assertRedirect();

        $this->assertSame(0, app(ReceivableLedgerService::class)->balance($this->account));
        $this->post(route('accounts.refunds.store', $this->account), ['amount' => '1', 'reason' => 'x'])->assertSessionHasErrors('amount');
    }

    public function test_proof_is_downloadable_only_by_authorized_users(): void
    {
        $this->post(route('accounts.payments.store', $this->account), $this->payload(['proof' => UploadedFile::fake()->image('p.jpg')]));
        $payment = Payment::firstOrFail();

        $this->get(route('payments.proof', $payment))->assertOk();
        $this->actingAs($this->staff(false))->get(route('payments.proof', $payment))->assertForbidden();
    }

    public function test_proof_rejects_bad_types_and_big_files(): void
    {
        $this->post(route('accounts.payments.store', $this->account), $this->payload(['proof' => UploadedFile::fake()->create('x.exe', 10)]))->assertSessionHasErrors('proof');
        $this->post(route('accounts.payments.store', $this->account), $this->payload(['proof' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')]))->assertSessionHasErrors('proof');
        $this->assertSame(0, Payment::count());
    }

    public function test_duplicate_reference_is_a_validation_error(): void
    {
        $this->post(route('accounts.payments.store', $this->account), $this->payload(['method' => 'gcash', 'reference' => 'R1']))->assertRedirect();
        $this->post(route('accounts.payments.store', $this->account), $this->payload(['method' => 'gcash', 'reference' => 'R1']))->assertSessionHasErrors('reference');
    }

    public function test_preview_returns_the_server_computed_allocation(): void
    {
        $this->getJson(route('accounts.payments.preview', $this->account).'?amount=2,000')
            ->assertOk()
            ->assertJsonPath('lines.0.apply_cents', 180000)
            ->assertJsonPath('credit_cents', 20000);
    }

    public function test_collections_lists_balances_with_aging_and_filters(): void
    {
        $this->get(route('collections.index'))->assertInertia(fn (Assert $p) => $p
            ->component('collections/index')
            ->has('rows', 1)
            ->where('rows.0.balance_cents', 180000)
            ->where('rows.0.aging', '31-60')
        );

        $this->get(route('collections.index', ['status' => 'credit']))->assertInertia(fn (Assert $p) => $p->has('rows', 0));
        $this->get(route('collections.index', ['status' => 'overdue']))->assertInertia(fn (Assert $p) => $p->has('rows', 1));
    }

    public function test_payment_detail_page(): void
    {
        $this->post(route('accounts.payments.store', $this->account), $this->payload());
        $payment = Payment::firstOrFail();

        $this->get(route('payments.show', $payment))->assertInertia(fn (Assert $p) => $p
            ->component('payments/show')
            ->where('payment.ack_number', 'ACK-JT1-2026-00001')
            ->has('allocations', 1)
        );
    }
}
