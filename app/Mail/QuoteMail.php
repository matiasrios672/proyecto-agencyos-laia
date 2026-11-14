<?php

namespace App\Mail;

use App\Models\Quote;
use App\Services\QuotePdf;
use App\Support\Money;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\URL;

class QuoteMail extends TemplateMail
{
    public function __construct(public Quote $quote) {}

    protected function templateKey(): string
    {
        return 'quote.sent';
    }

    protected function templateVars(): array
    {
        $validUntil = $this->quote->valid_until?->locale('es')->translatedFormat('j \d\e F \d\e Y');

        return [
            'nombre' => $this->quote->recipientName(),
            'numero' => $this->quote->number,
            'total' => Money::format($this->quote->total, $this->quote->currency),
            'vigencia_texto' => $validUntil ? "La propuesta es válida hasta el {$validUntil}." : '',
            'url' => URL::temporarySignedRoute('quotes.public_pdf', now()->addDays(30), ['quote' => $this->quote->id]),
        ];
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
