<?php

namespace App\Services\Billing;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\PdfDocument;
use App\Support\StatementSettings;
use Illuminate\Support\Facades\View;

/**
 * The payment receipt: one for every approved payment (its number is the payment's ack_number). Shows what the
 * money paid for and the balance left right after it. Not a BIR official receipt (the property is not set up for that).
 */
class PaymentAcknowledgmentService
{
    public function html(Payment $payment): string
    {
        $payment->loadMissing(['billingAccount.payer', 'property', 'collector', 'approver']);

        // The ledger line this payment posted; everything posted up to it is the balance "after this payment".
        $entry = LedgerEntry::where('source_type', $payment->getMorphClass())->where('source_id', $payment->id)
            ->where('type', 'payment')->orderBy('id')->first();
        $balanceAfter = $entry
            ? (int) LedgerEntry::where('billing_account_id', $payment->billing_account_id)->where('id', '<=', $entry->id)->sum('amount_cents')
            : null;

        $appliedTo = PaymentAllocation::where('payment_id', $payment->id)->whereNull('reversed_at')->orderBy('id')->get()
            ->map(function (PaymentAllocation $a) {
                $debit = LedgerEntry::with('invoice:id,number,period_start')->find($a->ledger_entry_id);
                $what = match (true) {
                    $debit?->invoice !== null => 'Bill '.$debit->invoice->number.' ('.$debit->invoice->period_start->format('F Y').')',
                    $debit?->type?->value === 'opening' => 'Balance brought forward',
                    default => $debit?->memo ?: 'Earlier charge',
                };

                return ['label' => $what, 'amount_cents' => $a->amount_cents];
            })->all();

        return View::make('pdf.payment-acknowledgment', [
            'payment' => $payment,
            'company' => StatementSettings::get(),
            'balanceAfter' => $balanceAfter,
            'appliedTo' => $appliedTo,
        ])->render();
    }

    public function pdf(Payment $payment): string
    {
        return PdfDocument::builder($this->html($payment))->format('a4')->margins(12, 12, 12, 12)
            ->withBrowsershot(fn ($browser) => $browser->showBackground())->generatePdfContent();
    }
}
