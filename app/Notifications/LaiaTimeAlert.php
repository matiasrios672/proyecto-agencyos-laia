<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Aviso interno a Dirección y sysadmin sobre la integración con LaiaTime (suspensión, reactivación o falla). */
class LaiaTimeAlert extends Notification
{
    public function __construct(private readonly string $title, private readonly string $body) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['icon' => 'bell', 'title' => $this->title, 'body' => $this->body, 'url' => '/admin/laiatime'];
    }
}
