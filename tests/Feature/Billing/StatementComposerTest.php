<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Services\Billing\ReceivableLedgerService;
use App\Services\Billing\StatementComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\MakesStatements;
use Tests\TestCase;

class StatementComposerTest extends TestCase
{
    use MakesStatements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStatementFixture();
    }

    private function compose($invoice, ?string $asOf = '2026-11-20')
    {
        return app(StatementComposer::class)->forInvoice($invoice, $asOf ? Carbon::parse($asOf) : null);
    }

    public function test_previous_plus_current_is_total_due(): void
    {
        $s = $this->compose($this->acceptanceInvoice());

        $this->assertSame(180000, $s->previousCents);
        $this->assertSame(415000, $s->currentCents);
        $this->assertSame(0, $s->paymentsSinceCents);
        $this->assertSame(595000, $s->totalDueCents);
    }

    public function test_payment_after_period_start_reduces_total_once_and_previous_stays(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->pay(50000, '2026-11-10', 'GCash ref 123');

        $s = $this->compose($invoice);

        $this->assertSame(180000, $s->previousCents);
        $this->assertSame(-50000, $s->paymentsSinceCents);
        $this->assertCount(1, $s->payments);
        $this->assertSame('GCash ref 123', $s->payments[0]['memo']);
        $this->assertSame(-50000, $s->payments[0]['amount_cents']);
        $this->assertSame(545000, $s->totalDueCents);
    }

    public function test_payment_before_period_start_is_in_previous_not_listed_again(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->pay(50000, '2026-10-20');

        $s = $this->compose($invoice);

        $this->assertSame(130000, $s->previousCents);
        $this->assertSame([], $s->payments);
        $this->assertSame(545000, $s->totalDueCents);
    }

    public function test_payment_after_as_of_is_not_counted(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->pay(50000, '2026-11-25');

        $this->assertSame(595000, $this->compose($invoice, '2026-11-20')->totalDueCents);
        $this->assertSame(545000, $this->compose($invoice, '2026-11-25')->totalDueCents);
    }

    public function test_reversed_payment_nets_out(): void
    {
        $invoice = $this->acceptanceInvoice();
        $entry = $this->pay(50000, '2026-11-10');
        app(ReceivableLedgerService::class)->reverse($entry, 'bounced');

        $s = $this->compose($invoice, '2026-11-30');

        $this->assertSame(0, $s->paymentsSinceCents);
        $this->assertCount(2, $s->payments);
        $this->assertSame(595000, $s->totalDueCents);
    }

    public function test_credit_balance_is_negative(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->pay(700000, '2026-11-10');

        $s = $this->compose($invoice);

        $this->assertSame(-105000, $s->totalDueCents);
        $this->assertTrue($s->isCredit);
    }

    public function test_void_invoice_is_flagged_and_ignores_its_own_entries(): void
    {
        $invoice = $this->acceptanceInvoice();
        $charge = $invoice->ledgerEntries()->where('type', LedgerEntryType::Charge)->first();
        app(ReceivableLedgerService::class)->reverse($charge, 'wrong tenant');
        $invoice->update(['status' => InvoiceStatus::Void, 'voided_at' => now(), 'void_reason' => 'wrong tenant']);

        $s = $this->compose($invoice->fresh(), '2026-11-30');

        $this->assertTrue($s->isVoid);
        $this->assertSame(180000, $s->previousCents);
        $this->assertSame(0, $s->paymentsSinceCents);
    }

    public function test_past_due_is_previous_unpaid_after_payments(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->assertSame(180000, $this->compose($invoice)->pastDueCents);

        $this->pay(50000, '2026-11-10');
        $this->assertSame(130000, $this->compose($invoice)->pastDueCents);

        $this->pay(300000, '2026-11-11');
        $this->assertSame(0, $this->compose($invoice)->pastDueCents);
    }

    public function test_lines_and_header_come_from_the_invoice(): void
    {
        $s = $this->compose($this->acceptanceInvoice());

        $this->assertCount(2, $s->lines);
        $this->assertSame('Monthly rent', $s->lines[0]['label']);
        $this->assertSame('JT-2026-11-0001', $s->number);
        $this->assertSame('Juan Dela Cruz', $s->tenantName);
        $this->assertSame('A-305', $s->unitLabel);
        $this->assertSame($this->account->account_no, $s->accountNo);
    }

    public function test_for_period_gives_running_balance(): void
    {
        $this->acceptanceInvoice();
        $this->pay(50000, '2026-11-10');

        $a = app(StatementComposer::class)->forPeriod($this->account, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));

        $this->assertSame(180000, $a->openingCents);
        $this->assertSame(545000, $a->closingCents);
        $this->assertSame([595000, 545000], array_column($a->rows, 'balance_cents'));
    }
}
