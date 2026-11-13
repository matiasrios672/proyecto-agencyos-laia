<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class EmployeeInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public const VALID_HOURS = 72;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        // La URL se firma al enviar (no al encolar) para que el plazo corra desde el envío real.
        $url = URL::temporarySignedRoute('invitation.show', now()->addHours(self::VALID_HOURS), ['user' => $notifiable->id]);

        return (new MailMessage)
            ->subject('Te invitamos a Agency OS · Laia Estudio Creativo Digital')
            ->greeting("¡Hola, {$notifiable->name}!")
            ->line('Te dieron de alta en Agency OS, el backoffice de Laia Estudio Creativo Digital.')
            ->line('Para empezar, configurá tu contraseña con el siguiente botón:')
            ->action('Configurar mi contraseña', $url)
            ->line('El enlace es personal y vence en '.self::VALID_HOURS.' horas. Si vence, pedile a Dirección que te reenvíe la invitación.')
            ->salutation('Equipo Laia');
    }
}
