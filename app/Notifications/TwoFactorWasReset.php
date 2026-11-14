<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Aviso al afectado: Dirección o el sysadmin le restablecieron la verificación en dos pasos. */
class TwoFactorWasReset extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(private readonly string $resetBy) {}

    public function via(object $notifiable): array
    {
        return ['database', ...$this->mailChannels($notifiable)];
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

    protected function templateKey(): string
    {
        return 'auth.two_factor_reset';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'quien' => $this->resetBy,
            'url' => url('/perfil'),
        ];
    }
}
