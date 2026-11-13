<?php

namespace App\Support;

use Illuminate\Support\Facades\Mail;

/**
 * Aplica en runtime el SMTP configurado desde el panel del sysadmin.
 * Solo se activa si el sysadmin guardó un servidor explícito; si no, rige el mailer del .env.
 */
class MailSettings
{
    public static function apply(): void
    {
        try {
            $stored = Settings::all();
        } catch (\Throwable) {
            return; // Redis/DB aún no disponibles (p. ej. durante migraciones): se usa el .env.
        }

        $host = $stored['smtp.host'] ?? null;
        if ($host === null || $host === '') {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => (int) ($stored['smtp.port'] ?? 587),
            'mail.mailers.smtp.username' => $stored['smtp.username'] ?? null,
            'mail.mailers.smtp.password' => $stored['smtp.password'] ?? null,
            'mail.mailers.smtp.scheme' => ($stored['smtp.encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'mail.from.address' => Settings::get('smtp.from_address'),
            'mail.from.name' => Settings::get('smtp.from_name'),
        ]);

        Mail::purge('smtp'); // fuerza a recrear el transporte con la nueva configuración
    }
}
