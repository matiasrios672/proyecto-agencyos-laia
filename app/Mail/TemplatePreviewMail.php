<?php

namespace App\Mail;

use App\Support\MailTemplates;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Correo de prueba de una plantilla con datos de ejemplo (se envía al Superadministrador, nunca a un cliente). */
class TemplatePreviewMail extends Mailable
{
    public function __construct(
        public string $templateKey,
        public ?string $subjectOverride = null,
        public ?string $bodyOverride = null,
    ) {}

    public function envelope(): Envelope
    {
        $rendered = MailTemplates::render($this->templateKey, MailTemplates::sampleVars($this->templateKey), $this->subjectOverride, $this->bodyOverride);

        return new Envelope(subject: '[PRUEBA] '.$rendered['subject']);
    }

    public function content(): Content
    {
        $rendered = MailTemplates::render($this->templateKey, MailTemplates::sampleVars($this->templateKey), $this->subjectOverride, $this->bodyOverride);

        return new Content(markdown: 'mail.template', with: [
            'bodyMarkdown' => $rendered['body'],
            'motive' => $rendered['motive'],
        ]);
    }
}
