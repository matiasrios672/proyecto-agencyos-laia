<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Correo con el enlace para restablecer la contraseña (token de un solo uso, vence en 60 minutos). */
class ResetPasswordLink extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(public readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    protected function templateKey(): string
    {
        return 'auth.password_reset';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'url' => route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]),
            'minutos' => (string) (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
        ];
    }
}
