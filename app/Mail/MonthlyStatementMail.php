<?php

namespace App\Mail;

use App\Services\Billing\Statement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MonthlyStatementMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param list<array<string,mixed>> $paymentInstructions */
    public function __construct(public Statement $statement, public string $statementPath, public array $paymentInstructions) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mubarak Boardinghouse — '.$this->statement->periodStart->format('F Y').' Billing Statement — '.$this->statement->unitLabel,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.monthly-statement',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('local', $this->statementPath)->as($this->statement->number.'.pdf')->withMime('application/pdf')];
    }
}
