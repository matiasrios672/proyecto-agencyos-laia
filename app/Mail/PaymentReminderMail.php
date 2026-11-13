<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentReminderMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice, public string $type) {}

    public function envelope(): Envelope
    {
        $subject = $this->type === 'mora'
            ? "Tu pago está vencido · {$this->invoice->number}"
            : "Recordatorio de pago · vence el {$this->invoice->due_date->format('d/m')}";

        return new Envelope(subject: $subject.' · Laia Estudio Creativo Digital');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-reminder', with: [
            'name' => $this->invoice->client->displayName(),
            'overdue' => $this->type === 'mora',
            'description' => $this->invoice->description,
            'number' => $this->invoice->number,
            'amount' => Money::format($this->invoice->amount, $this->invoice->currency),
            'dueDate' => $this->invoice->due_date->locale('es')->translatedFormat('j \d\e F \d\e Y'),
            'bank' => collect(['holder' => 'Titular', 'cuit' => 'CUIT', 'name' => 'Banco', 'cbu' => 'CBU / CVU', 'alias' => 'Alias'])
                ->mapWithKeys(fn ($label, $key) => [$label => Settings::get("bank.{$key}")])->filter()->all(),
        ]);
    }
}
