<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\LedgerEntryType;
use App\Models\Invoice;
use App\Models\LedgerEntry;

/** Payment status of a bill, derived from the ledger and allocations (never stored). */
class InvoiceStatusService
{
    public function __construct(private PaymentService $payments) {}

    /** @return 'paid'|'partial'|'unpaid'|'overdue'|'void' */
    public function statusFor(Invoice $invoice): string
    {
        if ($invoice->status === InvoiceStatus::Void) {
            return 'void';
        }

        $charge = LedgerEntry::where('invoice_id', $invoice->id)->where('type', LedgerEntryType::Charge)->orderBy('id')->first();
        if ($charge === null) {
            return 'unpaid';
        }
        // A credit-only bill owes nothing.
        if ($charge->amount_cents <= 0) {
            return 'paid';
        }

        $remaining = $this->payments->remaining($charge);
        if ($remaining === 0) {
            return 'paid';
        }
        if ($invoice->due_date->toDateString() < today()->toDateString()) {
            return 'overdue';
        }

        return $remaining < $charge->amount_cents ? 'partial' : 'unpaid';
    }
}
