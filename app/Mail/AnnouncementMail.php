<?php

namespace App\Mail;

use App\Models\Announcement;
use App\Support\MailTemplates;

class AnnouncementMail extends TemplateMail
{
    public function __construct(public Announcement $announcement, public string $clientName) {}

    protected function templateKey(): string
    {
        return self::keyFor($this->announcement);
    }

    /** Plantilla elegida para el comunicado (si se borró, vale la estándar). */
    public static function keyFor(Announcement $announcement): string
    {
        $key = $announcement->template_key;

        return $key && MailTemplates::exists($key) ? $key : 'announcement.sent';
    }

    protected function templateVars(): array
    {
        return [
            'nombre' => $this->clientName,
            'asunto' => $this->announcement->subject,
            'mensaje' => $this->announcement->body,
        ];
    }
}
