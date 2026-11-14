<?php

namespace App\Notifications\Concerns;

use App\Support\MailTemplates;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Permite que una notificación también salga por correo usando una plantilla editable
 * (Administración → Plantillas de correo), respetando si el correo está activado o no.
 */
trait SendsTemplateMail
{
    abstract protected function templateKey(): string;

    /** @return array<string, string|null> */
    abstract protected function templateVars(object $notifiable): array;

    /** @return array<int, string> ['mail'] si la plantilla está activa y la persona tiene email. */
    protected function mailChannels(object $notifiable): array
    {
        return MailTemplates::enabled($this->templateKey()) && filled($notifiable->email ?? null) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $rendered = MailTemplates::render($this->templateKey(), $this->templateVars($notifiable));

        return (new MailMessage)
            ->subject($rendered['subject'])
            ->markdown('mail.template', ['bodyMarkdown' => $rendered['body'], 'motive' => $rendered['motive']]);
    }
}
