<?php

namespace Tests\Unit\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\ActivityLog;
use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\RentalAgreement;
use App\Models\User;
use App\Services\Billing\ReceivableLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class ReceivableLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceivableLedgerService $ledger;

    private BillingAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new ReceivableLedgerService;
        $this->account = BillingAccount::factory()->create();
    }

    private function entry(LedgerEntryType $type, int $cents, string $date): LedgerEntry
    {
        return $this->ledger->post($this->account, $type, $cents, CarbonImmutable::parse($date), $this->account);
    }

    public function test_post_records_a_signed_entry_with_source_creator_and_audit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $entry = $this->ledger->post($this->account, LedgerEntryType::Payment, -50_000, CarbonImmutable::parse('2026-11-10'), $this->account, 'GCash ref 123');

        $entry->refresh();
        $this->assertSame(LedgerEntryType::Payment, $entry->type);
        $this->assertSame(-50_000, $entry->amount_cents);
        $this->assertSame('2026-11-10', $entry->effective_date->toDateString());
        $this->assertNotNull($entry->posted_at);
        $this->assertSame('GCash ref 123', $entry->memo);
        $this->assertSame($user->id, $entry->created_by);
        $this->assertTrue($entry->source->is($this->account));
        $this->assertSame(1, ActivityLog::where('action', 'ledger.posted')->where('subject_id', $entry->id)->count());
    }

    public function test_posting_with_an_invoice_source_links_the_invoice(): void
    {
        $agreement = RentalAgreement::factory()->create(['billing_account_id' => $this->account->id]);
        $invoice = Invoice::create([
            'billing_account_id' => $this->account->id,
            'rental_agreement_id' => $agreement->id,
            'property_id' => $this->account->property_id,
            'number' => 'TST-202611-0001',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'issue_date' => '2026-10-29',
            'due_date' => '2026-11-05',
            'status' => InvoiceStatus::Finalized,
            'current_charges_cents' => 415_000,
            'idempotency_key' => "agreement:{$agreement->id}:cycle:2026-11-01",
            'finalized_at' => now(),
        ]);

        $entry = $this->ledger->post($this->account, LedgerEntryType::Charge, 415_000, $invoice->issue_date, $invoice);

        $this->assertSame($invoice->id, $entry->fresh()->invoice_id);
        $this->assertTrue($entry->source->is($invoice));
        $this->assertNull($this->entry(LedgerEntryType::Payment, -1_000, '2026-11-02')->fresh()->invoice_id);
    }

    public function test_balance_sums_signed_amounts_with_as_of_cutoff(): void
    {
        $this->entry(LedgerEntryType::Opening, 180_000, '2026-10-31');
        $this->entry(LedgerEntryType::Charge, 415_000, '2026-11-01');
        $this->entry(LedgerEntryType::Payment, -50_000, '2026-11-10');
        $this->entry(LedgerEntryType::Charge, 415_000, '2026-12-01');

        $this->assertSame(180_000, $this->ledger->balance($this->account, CarbonImmutable::parse('2026-10-31')));
        $this->assertSame(595_000, $this->ledger->balance($this->account, CarbonImmutable::parse('2026-11-09')));
        $this->assertSame(545_000, $this->ledger->balance($this->account, CarbonImmutable::parse('2026-11-10')));
        $this->assertSame(960_000, $this->ledger->balance($this->account));
        $this->assertSame(0, $this->ledger->balance(BillingAccount::factory()->create()));
    }

    public function test_post_rejects_zero_amount(): void
    {
        $this->expectException(ValidationException::class);
        $this->entry(LedgerEntryType::Charge, 0, '2026-11-01');
    }

    public function test_database_rejects_zero_amount(): void
    {
        $this->expectException(QueryException::class);
        DB::table('ledger_entries')->insert([
            'billing_account_id' => $this->account->id,
            'type' => 'charge',
            'amount_cents' => 0,
            'effective_date' => '2026-11-01',
            'source_type' => 'x',
            'source_id' => 1,
        ]);
    }

    public function test_reverse_posts_negated_entry_and_balance_returns_to_zero(): void
    {
        $charge = $this->entry(LedgerEntryType::Charge, 415_000, '2026-11-01');

        $reversal = $this->ledger->reverse($charge, 'Wrong unit billed');

        $this->assertSame(LedgerEntryType::Reversal, $reversal->type);
        $this->assertSame(-415_000, $reversal->amount_cents);
        $this->assertSame($charge->id, $reversal->reverses_id);
        $this->assertSame('Wrong unit billed', $reversal->memo);
        $this->assertSame(0, $this->ledger->balance($this->account));
        $this->assertSame(1, ActivityLog::where('action', 'ledger.reversed')->count());
    }

    public function test_reversing_twice_is_rejected(): void
    {
        $charge = $this->entry(LedgerEntryType::Charge, 415_000, '2026-11-01');
        $this->ledger->reverse($charge, 'first');

        try {
            $this->ledger->reverse($charge, 'second');
            $this->fail('Second reversal should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entry', $e->errors());
        }
        $this->assertSame(2, LedgerEntry::count());
    }

    public function test_reversing_a_reversal_is_rejected(): void
    {
        $reversal = $this->ledger->reverse($this->entry(LedgerEntryType::Payment, -50_000, '2026-11-10'), 'bounced');

        try {
            $this->ledger->reverse($reversal, 'again');
            $this->fail('Reversing a reversal should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('entry', $e->errors());
        }
    }

    public function test_database_allows_only_one_reversal_per_entry(): void
    {
        $charge = $this->entry(LedgerEntryType::Charge, 1_000, '2026-11-01');
        $this->ledger->reverse($charge, 'first');

        $this->expectException(QueryException::class);
        DB::table('ledger_entries')->insert([
            'billing_account_id' => $this->account->id,
            'type' => 'reversal',
            'amount_cents' => -1_000,
            'effective_date' => '2026-11-01',
            'source_type' => 'x',
            'source_id' => 1,
            'reverses_id' => $charge->id,
        ]);
    }

    public function test_ledger_entries_cannot_be_updated(): void
    {
        $entry = $this->entry(LedgerEntryType::Charge, 1_000, '2026-11-01');

        $this->expectException(LogicException::class);
        $entry->update(['amount_cents' => 2_000]);
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        $entry = $this->entry(LedgerEntryType::Charge, 1_000, '2026-11-01');

        $this->expectException(LogicException::class);
        $entry->delete();
    }
}
