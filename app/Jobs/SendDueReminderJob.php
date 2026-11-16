<?php

namespace App\Jobs;

use App\Enums\DeliveryMethod;
use App\Enums\InvoiceStatus;
use App\Mail\DueReminderMail;
use App\Models\EmailDelivery;
use App\Services\Billing\DueReminderService;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendDueReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $deliveryId, public string $stage) {}

    /**
     * Execute the job.
     */
    public function handle(DueReminderService $reminders): void
    {
        $delivery = EmailDelivery::with('invoice.billingAccount.payer', 'invoice.property')->findOrFail($this->deliveryId);
        if ($delivery->status !== 'queued') {
            return;
        }

        $invoice = $delivery->invoice;
        $tenant = $invoice->billingAccount->payer;
        $remaining = $reminders->remaining($invoice);
        if ($invoice->status !== InvoiceStatus::Finalized || ! Settings::get('auto_send_email')
            || ! $invoice->property->auto_send_email || $remaining <= 0
            || ! $tenant->email_opt_in || $tenant->preferred_delivery !== DeliveryMethod::Email
            || ! filter_var($tenant->email, FILTER_VALIDATE_EMAIL)
            || strcasecmp((string) $delivery->to_email, (string) $tenant->email) !== 0
            || in_array(config('mail.default'), ['log', 'array'], true)) {
            $delivery->update(['status' => 'skipped', 'reason' => 'Bill, balance, recipient or delivery settings changed before sending.']);

            return;
        }

        Mail::to($delivery->to_email)->send(new DueReminderMail($tenant->full_name, $invoice->number, $invoice->due_date->toDateString(), $remaining, $this->stage));
        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'reason' => null]);
    }

    public function failed(Throwable $exception): void
    {
        EmailDelivery::whereKey($this->deliveryId)->update(['status' => 'failed', 'reason' => mb_substr($exception->getMessage(), 0, 2000)]);
    }
}
