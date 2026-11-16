<?php

namespace App\Services\Billing;

use App\Enums\DeliveryMethod;
use App\Enums\InvoiceStatus;
use App\Jobs\SendDueReminderJob;
use App\Models\EmailDelivery;
use App\Models\Invoice;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class DueReminderService
{
    public function __construct(private PaymentService $payments) {}

    public function queueForDate(CarbonInterface $date): int
    {
        if (! Settings::get('auto_send_email')) {
            return 0;
        }

        $stages = [];
        $before = (int) Settings::get('reminder_days_before');
        $after = (int) Settings::get('reminder_days_after');
        if ($before > 0) {
            $stages['before'] = Carbon::parse($date)->addDays($before)->toDateString();
        }
        if (Settings::get('reminder_on_due')) {
            $stages['due'] = $date->toDateString();
        }
        if ($after > 0) {
            $stages['after'] = Carbon::parse($date)->subDays($after)->toDateString();
        }

        $queued = 0;
        foreach ($stages as $stage => $dueDate) {
            $invoices = Invoice::query()->where('status', InvoiceStatus::Finalized)->whereDate('due_date', $dueDate)
                ->whereHas('property', fn ($query) => $query->where('auto_send_email', true))
                ->with(['billingAccount.payer', 'property'])->get();
            foreach ($invoices as $invoice) {
                $tenant = $invoice->billingAccount->payer;
                $remaining = $this->remaining($invoice);
                if ($remaining <= 0) {
                    continue;
                }

                $reason = match (true) {
                    ! filter_var($tenant->email, FILTER_VALIDATE_EMAIL) => 'No usable tenant email address.',
                    ! $tenant->email_opt_in || $tenant->preferred_delivery !== DeliveryMethod::Email => 'Tenant chose print delivery or opted out of email.',
                    in_array(config('mail.default'), ['log', 'array'], true) => 'SMTP mail transport is not configured.',
                    default => null,
                };
                $delivery = EmailDelivery::firstOrCreate(['idempotency_key' => 'invoice:'.$invoice->id.':reminder:'.$stage], [
                    'invoice_id' => $invoice->id,
                    'to_email' => $reason === null ? $tenant->email : null,
                    'status' => $reason === null ? 'queued' : 'skipped',
                    'reason' => $reason,
                ]);
                if (! $delivery->wasRecentlyCreated && in_array($delivery->status, ['skipped', 'failed'], true) && $reason === null) {
                    $delivery->update(['status' => 'queued', 'to_email' => $tenant->email, 'reason' => null]);
                }
                if ($delivery->status === 'queued' && ($delivery->wasRecentlyCreated || $delivery->wasChanged('status'))) {
                    SendDueReminderJob::dispatch($delivery->id, $stage)->afterCommit();
                    $queued++;
                }
            }
        }

        return $queued;
    }

    public function remaining(Invoice $invoice): int
    {
        return (int) $this->payments->openDebits([$invoice->billing_account_id])
            ->where('invoice_id', $invoice->id)->sum('remaining_cents');
    }
}
