<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/** Invitación para que una persona nueva del equipo configure su contraseña (enlace firmado). */
class EmployeeInvitation extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public const VALID_HOURS = 72;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    protected function templateKey(): string
    {
        return 'auth.invitation';
    }

    protected function templateVars(object $notifiable): array
    {
        // La URL se firma al enviar (no al encolar) para que el plazo corra desde el envío real.
        return [
            'nombre' => $notifiable->name,
            'url' => URL::temporarySignedRoute('invitation.show', now()->addHours(self::VALID_HOURS), ['user' => $notifiable->id]),
            'horas' => (string) self::VALID_HOURS,
        ];
    }
}
