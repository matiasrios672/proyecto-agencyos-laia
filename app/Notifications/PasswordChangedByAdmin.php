<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Aviso al afectado: Dirección o el sysadmin le cambiaron la contraseña (nunca incluye la clave). */
class PasswordChangedByAdmin extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(private readonly string $changedBy) {}

    public function via(object $notifiable): array
    {
        return ['database', ...$this->mailChannels($notifiable)];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'bell',
            'title' => 'Te cambiaron la contraseña',
            'body' => "{$this->changedBy} cambió tu contraseña. Podés cambiarla desde tu perfil.",
            'url' => '/perfil',
        ];
    }

    protected function templateKey(): string
    {
        return 'auth.password_changed_by_admin';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'quien' => $this->changedBy,
            'url' => url('/perfil'),
        ];
    }
}
