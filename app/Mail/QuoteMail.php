<?php

namespace App\Mail;

use App\Models\Quote;
use App\Services\QuotePdf;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class QuoteMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Quote $quote) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Presupuesto {$this->quote->number} · Laia Estudio Creativo Digital");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.quote',
            with: [
                'name' => $this->quote->recipientName(),
                'number' => $this->quote->number,
                'total' => Money::format($this->quote->total, $this->quote->currency),
                'validUntil' => $this->quote->valid_until?->locale('es')->translatedFormat('j \d\e F \d\e Y'),
                'url' => URL::temporarySignedRoute('quotes.public_pdf', now()->addDays(30), ['quote' => $this->quote->id]),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => QuotePdf::render($this->quote), "Presupuesto-{$this->quote->number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
