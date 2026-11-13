<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Aviso al afectado: Dirección o el sysadmin le restablecieron la verificación en dos pasos. */
class TwoFactorWasReset extends Notification
{
    public function __construct(private readonly string $resetBy) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'bell',
            'title' => 'Restablecieron tu verificación en dos pasos',
            'body' => "{$this->resetBy} la desactivó (por ejemplo, por un dispositivo perdido). Volvé a activarla desde tu perfil.",
            'url' => '/perfil',
        ];
    }
}
