<?php

namespace Tests\Feature;

use App\Models\BillingAccount;
use App\Models\Invoice;
use App\Models\StatementSnapshot;
use App\Models\User;
use App\Services\Billing\PaymentService;
use App\Support\PdfDocument;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Shared web hosting (e.g. Hostinger) has no Node/Chrome. Every PDF link must still work there: when no PDF engine
 * is available the same document is served as a print-ready page (the browser prints / saves it as PDF), never a 500.
 */
class PdfWithoutEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_pdf_link_falls_back_to_a_print_ready_page_when_the_server_cannot_make_pdfs(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('role', 'owner')->orderBy('id')->firstOrFail();
        $this->actingAs($owner);
        $account = BillingAccount::orderBy('id')->firstOrFail();
        $this->post(route('billing.generate'), ['year' => 2026, 'month' => 11, 'account_ids' => [$account->id]])->assertRedirect();
        $payment = app(PaymentService::class)->record($account, ['amount_cents' => 10000, 'paid_on' => '2026-11-16', 'method' => 'cash'], $owner);
        $invoice = Invoice::firstOrFail();

        // The server's situation: Browsershot driver, but no node binary.
        config(['laravel-pdf.driver' => 'browsershot', 'laravel-pdf.browsershot.node_binary' => '/usr/bin/no-such-node']);
        $this->assertFalse(PdfDocument::engineAvailable());

        $links = [
            'statement of accounts' => route('reports.statement-of-accounts', ['as_of' => '2026-11-16']),
            'reports' => route('reports.pdf', ['month' => '2026-11']),
            'account statement' => route('accounts.statement.pdf', ['account' => $account, 'from' => '2026-10-01', 'to' => '2026-11-16']),
            'billing statement' => route('invoices.statement.pdf', $invoice),
            'statement copy' => route('invoices.statement.copy', $invoice),
            'payment receipt' => route('payments.acknowledgment', $payment),
        ];
        foreach ($links as $name => $url) {
            $response = $this->get($url);
            $response->assertOk();
            $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'), $name);
            $html = $response->getContent();
            $this->assertStringContainsString('window.print()', $html, $name);
            $this->assertStringContainsString('@page', $html, $name);
        }

        // A billing statement shown this way is not "issued": no stored PDF / snapshot is made without an engine.
        $this->assertSame(0, StatementSnapshot::count());
    }

    public function test_without_node_the_app_picks_dompdf_by_itself(): void
    {
        // Hostinger-like server: .env says browsershot (or nothing) but there is no node binary.
        $this->assertSame('dompdf', PdfDocument::resolveDriver('browsershot', '/usr/bin/no-such-node'));
        $this->assertSame('dompdf', PdfDocument::resolveDriver(null, '/usr/bin/no-such-node'));
        // An explicit choice of another engine is respected.
        $this->assertSame('cloudflare', PdfDocument::resolveDriver('cloudflare', null));
        $this->assertSame('dompdf', PdfDocument::resolveDriver('dompdf', null));
    }

    public function test_with_dompdf_every_pdf_link_gives_a_real_pdf_on_plain_php_hosting(): void
    {
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-16 09:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('role', 'owner')->orderBy('id')->firstOrFail();
        $this->actingAs($owner);
        $account = BillingAccount::orderBy('id')->firstOrFail();
        $this->post(route('billing.generate'), ['year' => 2026, 'month' => 11, 'account_ids' => [$account->id]])->assertRedirect();
        $payment = app(PaymentService::class)->record($account, ['amount_cents' => 10000, 'paid_on' => '2026-11-16', 'method' => 'cash'], $owner);
        $invoice = Invoice::firstOrFail();

        config(['laravel-pdf.driver' => 'dompdf']);
        $this->assertTrue(PdfDocument::engineAvailable());

        $links = [
            'statement of accounts' => route('reports.statement-of-accounts', ['as_of' => '2026-11-16']),
            'reports' => route('reports.pdf', ['month' => '2026-11']),
            'account statement' => route('accounts.statement.pdf', ['account' => $account, 'from' => '2026-10-01', 'to' => '2026-11-16']),
            'billing statement' => route('invoices.statement.pdf', $invoice),
            'statement copy' => route('invoices.statement.copy', $invoice),
            'payment receipt' => route('payments.acknowledgment', $payment),
        ];
        foreach ($links as $name => $url) {
            $response = $this->get($url);
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $name);
            $pdf = $response instanceof TestResponse && $response->baseResponse instanceof StreamedResponse
                ? $response->streamedContent() : $response->getContent();
            $this->assertStringStartsWith('%PDF', (string) ($pdf ?: file_get_contents($response->baseResponse->getFile()->getPathname())), $name);
        }

        // The billing statement is issued as usual: snapshot frozen and the PDF stored once.
        $this->assertSame(1, StatementSnapshot::count());
    }

    public function test_another_driver_such_as_cloudflare_counts_as_available(): void
    {
        config(['laravel-pdf.driver' => 'cloudflare']);

        $this->assertTrue(PdfDocument::engineAvailable());
    }
}
