<?php

namespace App\Mail;

use App\Support\MailTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Base de los correos que salen de una plantilla editable (ver config/mail_templates.php).
 * El texto se arma al enviar, así que un cambio de plantilla vale para los correos que todavía estén en cola.
 */
abstract class TemplateMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    abstract protected function templateKey(): string;

    /** @return array<string, string|null> */
    abstract protected function templateVars(): array;

    public function envelope(): Envelope
    {
        return new Envelope(subject: MailTemplates::render($this->templateKey(), $this->templateVars())['subject']);
    }

    public function content(): Content
    {
        $rendered = MailTemplates::render($this->templateKey(), $this->templateVars());

        return new Content(markdown: 'mail.template', with: [
            'bodyMarkdown' => $rendered['body'],
            'motive' => $rendered['motive'],
        ]);
    }
}
