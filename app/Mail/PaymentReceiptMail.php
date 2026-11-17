<?php

namespace App\Mail;

use App\Models\Payment;
use App\Services\Billing\PaymentAcknowledgmentService;
use App\Support\Money;
use App\Support\StatementSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A payment receipt by email, the receipt PDF attached. Queued: the PDF is made when the email goes out. */
class PaymentReceiptMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payment $payment) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: StatementSettings::get()['company_name'].' — Payment receipt '.$this->payment->ack_number.' — '.Money::format($this->payment->amount_cents),
        );
    }

    public function content(): Content
    {
        $this->payment->loadMissing(['billingAccount.payer', 'property']);

        return new Content(view: 'mail.payment-receipt', with: [
            'payment' => $this->payment,
            'company' => StatementSettings::get(),
        ]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $payment = $this->payment;

        return [
            Attachment::fromData(fn () => app(PaymentAcknowledgmentService::class)->pdf($payment), $payment->ack_number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
