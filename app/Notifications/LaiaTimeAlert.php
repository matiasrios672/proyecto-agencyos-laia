<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Aviso interno a Dirección y sysadmin sobre la integración con LaiaTime (suspensión, reactivación o falla). */
class LaiaTimeAlert extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(private readonly string $title, private readonly string $body) {}

    public function via(object $notifiable): array
    {
        return ['database', ...$this->mailChannels($notifiable)];
    }

    public function toArray(object $notifiable): array
    {
        return ['icon' => 'bell', 'title' => $this->title, 'body' => $this->body, 'url' => '/admin/laiatime'];
    }

    protected function templateKey(): string
    {
        return 'system.laiatime_alert';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'titulo' => $this->title,
            'detalle' => $this->body,
            'url' => url('/admin/laiatime'),
        ];
    }
}
