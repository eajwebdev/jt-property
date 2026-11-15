<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\Property;
use App\Models\RentRate;
use App\Models\StatementSnapshot;
use App\Models\User;
use App\Services\Billing\ReceivableLedgerService;
use App\Services\Billing\StatementPdfService;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesStatements;
use Tests\TestCase;

class StatementPdfTest extends TestCase
{
    use MakesStatements;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpStatementFixture(['full_name' => 'Peña Muñoz-Álvarez']);
    }

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf);
    }

    public function test_preview_html_shows_acceptance_numbers_and_peso_and_accents(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->pay(50000, '2026-11-10', 'GCash ref 123');

        $html = $this->actingAs($this->owner())->get("/invoices/{$invoice->id}/statement")->assertOk()->getContent();

        $this->assertStringContainsString('MONTHLY BILLING STATEMENT', $html);
        $this->assertStringContainsString('Peña Muñoz-Álvarez', $html);
        $this->assertStringContainsString('₱1,800.00', $html);
        $this->assertStringContainsString('₱4,150.00', $html);
        $this->assertStringContainsString('-₱500.00', $html);
        $this->assertStringContainsString('₱5,450.00', $html);
        $this->assertSame(1, substr_count($html, 'GCash ref 123'), 'the payment is listed once');
        $this->assertStringContainsString('01 Nov 2026', $html);
        $this->assertStringContainsString('05 Nov 2026', $html);
        $this->assertStringNotContainsString('Credit balance', $html);
    }

    public function test_credit_balance_is_labelled(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->pay(700000, '2026-11-10');

        $html = $this->actingAs($this->owner())->get("/invoices/{$invoice->id}/statement")->getContent();

        $this->assertStringContainsString('Credit balance', $html);
        $this->assertStringContainsString('-₱1,050.00', $html);
    }

    public function test_void_invoice_has_watermark(): void
    {
        $invoice = $this->acceptanceInvoice();
        $invoice->update(['status' => InvoiceStatus::Void, 'voided_at' => now(), 'void_reason' => 'Wrong tenant']);

        $this->actingAs($this->owner())->get("/invoices/{$invoice->id}/statement")->assertSee('class="watermark"', false);
    }

    public function test_settings_instructions_and_notes_are_shown(): void
    {
        $invoice = $this->acceptanceInvoice();
        $owner = $this->owner();
        Settings::set('statement_notes', 'Pay on time to avoid penalties.', $owner);
        Settings::set('payment_instructions', [['method' => 'gcash', 'label' => 'GCash', 'account_name' => 'JT Property', 'account_number' => '0917-890-1234', 'notes' => null]], $owner);

        $this->actingAs($owner)->get("/invoices/{$invoice->id}/statement")
            ->assertSee('Pay on time to avoid penalties.')->assertSee('0917-890-1234');
    }

    public function test_access_policy(): void
    {
        $invoice = $this->acceptanceInvoice();
        $url = "/invoices/{$invoice->id}/statement";

        $assigned = User::factory()->create();
        $assigned->properties()->attach($this->property);
        $other = User::factory()->create();
        $other->properties()->attach(Property::factory()->create());

        $this->get($url)->assertRedirect();
        $this->actingAs($other)->get($url)->assertForbidden();
        $this->actingAs($other)->get($url.'.pdf')->assertForbidden();
        $this->actingAs($other)->get("/invoices/{$invoice->id}/statement-copy.pdf")->assertForbidden();
        $this->actingAs($assigned)->get($url)->assertOk();
        $this->actingAs(User::factory()->accountant()->create())->get($url)->assertOk();
        $this->assertSame(0, StatementSnapshot::count(), 'a denied request creates nothing');
    }

    public function test_real_pdf_is_rendered_stored_and_snapshotted(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->pay(50000, '2026-11-10');

        $path = app(StatementPdfService::class)->render($invoice, $this->owner());

        $this->assertSame('statements/2026/JT-2026-11-0001.pdf', $path);
        $bytes = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThanOrEqual(1, $this->pageCount($bytes));

        $snap = StatementSnapshot::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(hash('sha256', $bytes), $snap->sha256);
        $this->assertSame(180000, $snap->totals['previous']);
        $this->assertSame(-50000, $snap->totals['payments_since']);
        $this->assertSame(415000, $snap->totals['current']);
        $this->assertSame(545000, $snap->totals['total_due']);
    }

    public function test_download_serves_stored_file_and_does_not_rerender(): void
    {
        $invoice = $this->acceptanceInvoice();
        $owner = $this->owner();

        $first = $this->actingAs($owner)->get("/invoices/{$invoice->id}/statement.pdf")->assertOk();
        $this->assertSame('application/pdf', $first->headers->get('content-type'));
        $sha = StatementSnapshot::first()->sha256;
        $this->pay(100000, '2026-11-12');

        $second = $this->actingAs($owner)->get("/invoices/{$invoice->id}/statement.pdf")->assertOk();

        $this->assertSame(1, StatementSnapshot::count());
        $this->assertSame($sha, hash('sha256', $second->streamedContent()));
    }

    public function test_snapshot_is_unchanged_by_later_rent_change_and_payment(): void
    {
        $invoice = $this->acceptanceInvoice();
        $service = app(StatementPdfService::class);
        $service->render($invoice, $this->owner());
        $before = StatementSnapshot::first()->only(['totals', 'sha256', 'path']);

        RentRate::create(['rental_agreement_id' => $this->agreement->id, 'rent_cents' => 999900, 'effective_from' => '2026-12-01']);
        $this->pay(100000, '2026-11-15');
        app(ReceivableLedgerService::class)->post($this->account, LedgerEntryType::Charge, 123400, now(), $this->account);

        $service->render($invoice->fresh(), $this->owner());
        $this->assertSame($before, StatementSnapshot::first()->only(['totals', 'sha256', 'path']));

        $copy = $service->statement($invoice->fresh());
        $this->assertSame(595000, $copy->totalDueCents, 'snapshot totals, not live ledger');
        $this->assertSame(180000, $copy->previousCents);
    }

    public function test_copy_route_rerenders_from_snapshot_with_identical_totals(): void
    {
        $invoice = $this->acceptanceInvoice();
        $this->pay(50000, '2026-11-10');
        $owner = $this->owner();
        $this->actingAs($owner)->get("/invoices/{$invoice->id}/statement.pdf")->assertOk();
        $this->pay(77700, '2026-11-14');

        $html = app(StatementPdfService::class)->html($invoice->fresh());
        $this->assertStringContainsString('₱5,450.00', $html);
        $this->assertStringNotContainsString('₱4,673.00', $html);

        $copy = $this->actingAs($owner)->get("/invoices/{$invoice->id}/statement-copy.pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $copy->streamedContent());
        $this->assertSame(1, StatementSnapshot::count());
    }

    public function test_long_names_and_forty_lines_paginate(): void
    {
        $this->setUpStatementFixture(['full_name' => str_repeat('Maria Concepcion de los Santos y Bautista ', 3)]);
        $lines = [];
        for ($i = 1; $i <= 40; $i++) {
            $lines[] = ["Charge line {$i} with a fairly long description label", 10000 + $i];
        }
        $invoice = $this->makeInvoice('JT-2026-11-0099', '2026-11-01', '2026-11-30', $lines);

        $path = app(StatementPdfService::class)->render($invoice, $this->owner());
        $bytes = Storage::disk('local')->get($path);

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThanOrEqual(2, $this->pageCount($bytes));
    }
}
