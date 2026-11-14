<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Aviso de seguridad: se usó un código de recuperación para ingresar. */
class RecoveryCodeUsed extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(private readonly int $remaining) {}

    public function via(object $notifiable): array
    {
        return ['database', ...$this->mailChannels($notifiable)];
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

    protected function templateKey(): string
    {
        return 'auth.recovery_code_used';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'restantes_texto' => $this->remaining === 0
                ? 'No te quedan códigos: generá nuevos desde tu perfil.'
                : "Te quedan {$this->remaining}.",
            'url' => url('/perfil'),
        ];
    }
}
