<?php

namespace Tests\Feature;

use App\Jobs\SendDueReminderJob;
use App\Models\BillingAccount;
use App\Models\EmailDelivery;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\User;
use App\Services\Billing\DueReminderService;
use App\Services\Billing\InvoiceGenerationService;
use App\Support\Settings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BillingRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_reminders_only_queue_for_opted_in_unpaid_bills_once(): void
    {
        $this->travelTo(Carbon::parse('2026-11-16 07:00', 'Asia/Manila'));
        $this->seed(DatabaseSeeder::class);
        $owner = User::where('role', 'owner')->orderBy('id')->firstOrFail();
        $account = BillingAccount::whereHas('payer', fn ($query) => $query->where('full_name', 'Ana Demo'))->firstOrFail();
        $this->actingAs($owner);
        app(InvoiceGenerationService::class)->generate(2026, 11, $owner, null, null, [$account->id]);
        $invoice = Invoice::firstOrFail();

        Queue::fake();
        config(['mail.default' => 'smtp']);
        Settings::set('auto_send_email', true, $owner);
        Settings::set('reminder_days_before', 0, $owner);
        Settings::set('reminder_on_due', false, $owner);
        Settings::set('reminder_days_after', 11, $owner);
        Property::whereKey($account->property_id)->update(['auto_send_email' => true]);

        $this->assertSame(0, Artisan::call('billing:send-reminders'));
        $this->assertSame(0, Artisan::call('billing:send-reminders'));
        $this->assertSame(1, EmailDelivery::where('invoice_id', $invoice->id)->where('status', 'queued')->count());
        Queue::assertPushed(SendDueReminderJob::class, 1);

        $account->payer->update(['email_opt_in' => false]);
        (new SendDueReminderJob(EmailDelivery::firstOrFail()->id, 'after'))
            ->handle(app(DueReminderService::class));
        $this->assertSame('skipped', EmailDelivery::firstOrFail()->fresh()->status);
    }
}
