<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Aviso de seguridad: se usó un código de recuperación para ingresar. */
class RecoveryCodeUsed extends Notification
{
    public function __construct(private readonly int $remaining) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $left = $this->remaining === 0
            ? 'No te quedan códigos: generá nuevos desde tu perfil.'
            : "Te quedan {$this->remaining}.";

        return [
            'icon' => 'bell',
            'title' => 'Ingresaste con un código de recuperación',
            'body' => "Cada código se usa una sola vez. {$left} Si no fuiste vos, cambiá tu contraseña ya.",
            'url' => '/perfil',
        ];
    }
}
