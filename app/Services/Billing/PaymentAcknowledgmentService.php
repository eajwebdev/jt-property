<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Support\StatementSettings;
use Illuminate\Support\Facades\View;
use Spatie\LaravelPdf\Facades\Pdf;

class PaymentAcknowledgmentService
{
    public function html(Payment $payment): string
    {
        $payment->loadMissing(['billingAccount.payer', 'property', 'collector', 'approver']);

        return View::make('pdf.payment-acknowledgment', ['payment' => $payment, 'company' => StatementSettings::get()])->render();
    }

    public function pdf(Payment $payment): string
    {
        return Pdf::html($this->html($payment))->format('a4')->margins(12, 12, 12, 12)
            ->withBrowsershot(fn ($browser) => $browser->showBackground())->generatePdfContent();
    }
}
