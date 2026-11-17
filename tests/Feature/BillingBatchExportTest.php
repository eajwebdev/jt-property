<?php

namespace Tests\Feature;

use App\Jobs\SendStatementEmailJob;
use App\Mail\MonthlyStatementMail;
use App\Models\BillingAccount;
use App\Models\BillingBatch;
use App\Models\EmailDelivery;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\User;
use App\Services\Billing\BatchExportService;
use App\Services\Billing\BillingEmailService;
use App\Services\Billing\StatementPdfService;
use App\Support\Money;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class BillingBatchExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);
    }

    private function owner(): User
    {
        return User::where('role', 'owner')->orderBy('id')->firstOrFail();
    }

    public function test_selected_accounts_are_the_only_accounts_billed(): void
    {
        $selected = BillingAccount::orderBy('id')->firstOrFail();
        $response = $this->actingAs($this->owner())->get(route('billing.index', ['year' => 2026, 'month' => 11, 'account_ids' => [$selected->id]]));
        $response->assertOk();
        $this->assertEqualsCanonicalizing([$selected->id], array_column($response->viewData('page')['props']['rows'], 'account_id'));

        $this->post(route('billing.generate'), ['year' => 2026, 'month' => 11, 'account_ids' => [$selected->id]])->assertRedirect();

        $this->assertSame([$selected->id], Invoice::pluck('billing_account_id')->all());
    }

    public function test_zip_and_combined_pdf_contain_only_the_selected_batch_invoices(): void
    {
        $accounts = BillingAccount::orderBy('id')->limit(2)->pluck('id')->all();
        $this->actingAs($this->owner())->post(route('billing.generate'), ['year' => 2026, 'month' => 11, 'account_ids' => $accounts])->assertRedirect();
        $invoices = Invoice::with('billingAccount.payer')->orderBy('number')->get();
        $this->assertCount(2, $invoices);

        $exports = app(BatchExportService::class);
        $path = $exports->zip($invoices, $this->owner());
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $this->assertSame(2, $zip->numFiles);
            $this->assertStringStartsWith($invoices[0]->number, $zip->getNameIndex(0));
            $this->assertStringStartsWith('%PDF', $zip->getFromIndex(0));
            $zip->close();
        } finally {
            unlink($path);
        }

        $combined = $exports->combined($invoices);
        $this->assertStringStartsWith('%PDF', $combined);
        $this->assertGreaterThanOrEqual(2, preg_match_all('#/Type\s*/Page\b(?!s)#', $combined));
    }

    public function test_auto_email_requires_both_switches_and_respects_missing_email(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $owner = $this->owner();
        $property = Property::where('code', 'RIV')->firstOrFail();
        $property->update(['auto_send_email' => true]);
        Settings::set('auto_send_email', true, $owner);
        $accounts = BillingAccount::where('property_id', $property->id)->orderBy('id')->limit(3)->pluck('id')->all();

        $this->actingAs($owner)->post(route('billing.generate'), ['year' => 2026, 'month' => 11, 'account_ids' => $accounts])->assertRedirect();

        $this->assertSame(3, Invoice::count());
        $this->assertSame(3, EmailDelivery::count());
        Mail::assertSent(MonthlyStatementMail::class, 2);
        Mail::assertSent(MonthlyStatementMail::class, function (MonthlyStatementMail $mail): bool {
            $body = $mail->render();

            return str_contains($mail->envelope()->subject, 'Billing Statement')
                && str_contains($body, $mail->statement->dueDate->format('d M Y'))
                && str_contains($body, Money::format($mail->statement->totalDueCents))
                && str_contains($body, $mail->statement->accountNo);
        });
        $this->assertSame(2, EmailDelivery::where('status', 'sent')->count());
        $this->assertSame(1, EmailDelivery::where('status', 'skipped')->count());

        $batch = Invoice::firstOrFail()->billingBatch;
        $this->actingAs($owner)->post(route('billing.batches.email', $batch))->assertRedirect();
        Mail::assertSent(MonthlyStatementMail::class, 2);
    }

    public function test_bulk_export_restricts_staff_to_assigned_property(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post(route('billing.generate'), ['year' => 2026, 'month' => 11])->assertRedirect();
        $batch = Invoice::firstOrFail()->billingBatch;
        $staff = User::where('role', 'staff')->orderBy('id')->firstOrFail();

        $response = $this->actingAs($staff)->get(route('billing.batches.zip', $batch));
        $response->assertOk();
        $zip = new ZipArchive;
        $path = tempnam(sys_get_temp_dir(), 'jt-test-');
        try {
            file_put_contents($path, $response->streamedContent());
            $this->assertTrue($zip->open($path) === true);
            $this->assertSame(5, $zip->numFiles);
            $zip->close();
        } finally {
            unlink($path);
        }

        $this->actingAs($staff)->get(route('invoices.statement.pdf', Invoice::whereHas('property', fn ($query) => $query->where('code', 'HIL'))->firstOrFail()))->assertForbidden();
    }

    public function test_month_statements_pdf_combines_every_bill_of_the_month_in_scope(): void
    {
        $owner = $this->owner();
        $accounts = BillingAccount::orderBy('id')->limit(2)->pluck('id')->all();
        $this->actingAs($owner)->post(route('billing.generate'), ['year' => 2026, 'month' => 11, 'account_ids' => $accounts])->assertRedirect();

        $response = $this->get(route('billing.month-statements', ['year' => 2026, 'month' => 11]));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $pdf = $response->streamedContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThanOrEqual(2, preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf));

        // A month with no bills, and a property the staff member is not assigned to: nothing to print.
        $this->get(route('billing.month-statements', ['year' => 2026, 'month' => 10]))->assertNotFound();
        $staff = User::where('role', 'staff')->orderBy('id')->firstOrFail();
        $other = Property::whereNotIn('id', $staff->properties()->select('properties.id'))->orderBy('id')->firstOrFail();
        $this->actingAs($staff)->get(route('billing.month-statements', ['year' => 2026, 'month' => 11, 'property_id' => $other->id]))->assertNotFound();
    }

    public function test_replayed_batch_can_export_previously_created_invoices(): void
    {
        $owner = $this->owner();
        $account = BillingAccount::orderBy('id')->firstOrFail();
        $data = ['year' => 2026, 'month' => 11, 'account_ids' => [$account->id]];
        $this->actingAs($owner)->post(route('billing.generate'), $data)->assertRedirect();
        $this->post(route('billing.generate'), $data)->assertRedirect();
        $batch = Invoice::firstOrFail()->billingBatch;
        $replayed = BillingBatch::where('id', '!=', $batch->id)->firstOrFail();

        $response = $this->get(route('billing.batches.zip', $replayed));
        $response->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'jt-test-');
        try {
            file_put_contents($path, $response->streamedContent());
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $this->assertSame(1, $zip->numFiles);
            $zip->close();
        } finally {
            unlink($path);
        }
    }

    public function test_smtp_test_requires_owner_and_a_real_transport(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $this->actingAs(User::where('role', 'staff')->orderBy('id')->firstOrFail())->post(route('billing.test-email'))->assertForbidden();
        $this->actingAs($this->owner())->post(route('billing.test-email'))
            ->assertRedirect()->assertSessionHas('success', 'Configure a real mail transport before sending a test.');
        Mail::assertNothingSent();
    }

    public function test_queued_email_is_skipped_if_tenant_opts_out_before_delivery(): void
    {
        Mail::fake();
        Queue::fake();
        config(['mail.default' => 'smtp']);
        $this->actingAs($this->owner())->post(route('billing.generate'), ['year' => 2026, 'month' => 11, 'account_ids' => [BillingAccount::orderBy('id')->value('id')]])->assertRedirect();
        $invoice = Invoice::firstOrFail();
        $delivery = app(BillingEmailService::class)->send($invoice);
        Queue::assertPushed(SendStatementEmailJob::class, 1);

        $invoice->billingAccount->payer->update(['email_opt_in' => false]);
        (new SendStatementEmailJob($delivery->id))->handle(app(StatementPdfService::class));

        $this->assertSame('skipped', $delivery->fresh()->status);
        Mail::assertNothingSent();
    }
}
