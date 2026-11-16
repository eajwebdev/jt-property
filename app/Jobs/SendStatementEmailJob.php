<?php

namespace App\Jobs;

use App\Enums\DeliveryMethod;
use App\Enums\InvoiceStatus;
use App\Mail\MonthlyStatementMail;
use App\Models\EmailDelivery;
use App\Services\Billing\StatementPdfService;
use App\Support\StatementSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendStatementEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $deliveryId) {}

    /**
     * Execute the job.
     */
    public function handle(StatementPdfService $statements): void
    {
        $delivery = EmailDelivery::with('invoice.billingAccount.payer')->findOrFail($this->deliveryId);
        if ($delivery->status !== 'queued') {
            return;
        }

        $invoice = $delivery->invoice;
        $tenant = $invoice->billingAccount->payer;
        if ($invoice->status !== InvoiceStatus::Finalized || ! $tenant->email_opt_in
            || $tenant->preferred_delivery !== DeliveryMethod::Email
            || ! filter_var($tenant->email, FILTER_VALIDATE_EMAIL)
            || strcasecmp((string) $delivery->to_email, (string) $tenant->email) !== 0
            || in_array(config('mail.default'), ['log', 'array'], true)) {
            $delivery->update(['status' => 'skipped', 'reason' => 'Bill or recipient changed before email delivery.']);

            return;
        }

        $path = $statements->render($invoice);
        Mail::to($delivery->to_email)->send(new MonthlyStatementMail($statements->statement($invoice), $path, StatementSettings::get()['payment_instructions']));
        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'reason' => null]);
    }

    public function failed(Throwable $exception): void
    {
        EmailDelivery::whereKey($this->deliveryId)->update(['status' => 'failed', 'reason' => mb_substr($exception->getMessage(), 0, 2000)]);
    }
}
