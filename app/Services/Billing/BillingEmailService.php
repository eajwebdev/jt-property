<?php

namespace App\Services\Billing;

use App\Enums\DeliveryMethod;
use App\Enums\InvoiceStatus;
use App\Jobs\SendStatementEmailJob;
use App\Models\EmailDelivery;
use App\Models\Invoice;
use Illuminate\Support\Str;

class BillingEmailService
{
    public function send(Invoice $invoice, bool $resend = false): EmailDelivery
    {
        $invoice->loadMissing('billingAccount.payer');
        $tenant = $invoice->billingAccount->payer;
        $email = trim((string) $tenant->email);
        $reason = match (true) {
            $invoice->status !== InvoiceStatus::Finalized => 'The bill is void.',
            ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'No usable tenant email address.',
            ! $tenant->email_opt_in || $tenant->preferred_delivery !== DeliveryMethod::Email => 'Tenant chose print delivery or opted out of email.',
            in_array(config('mail.default'), ['log', 'array'], true) => 'SMTP mail transport is not configured.',
            default => null,
        };

        $key = $resend ? 'invoice:'.$invoice->id.':statement:'.Str::uuid() : 'invoice:'.$invoice->id.':statement';
        $delivery = EmailDelivery::firstOrCreate(['idempotency_key' => $key], [
            'invoice_id' => $invoice->id,
            'to_email' => $reason === null ? $email : null,
            'status' => $reason === null ? 'queued' : 'skipped',
            'reason' => $reason,
        ]);

        if (! $delivery->wasRecentlyCreated && in_array($delivery->status, ['skipped', 'failed'], true) && $reason === null) {
            $delivery->update(['status' => 'queued', 'to_email' => $email, 'reason' => null]);
        }

        if ($delivery->status === 'queued' && ($delivery->wasRecentlyCreated || $delivery->wasChanged('status'))) {
            SendStatementEmailJob::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }
}
