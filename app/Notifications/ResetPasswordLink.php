<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Correo con el enlace para restablecer la contraseña (token de un solo uso, vence en 60 minutos). */
class ResetPasswordLink extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Restablecé tu contraseña · Agency OS')
            ->greeting("¡Hola, {$notifiable->name}!")
            ->line('Recibimos un pedido para restablecer la contraseña de tu cuenta en Agency OS.')
            ->action('Elegir una contraseña nueva', $url)
            ->line("El enlace es personal, se puede usar una sola vez y vence en {$minutes} minutos.")
            ->line('Si no lo pediste vos, ignorá este correo: tu contraseña actual sigue siendo la misma.')
            ->salutation('Equipo Laia');
    }
}
