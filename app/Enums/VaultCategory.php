<?php

namespace App\Enums;

enum VaultCategory: string
{
    case RedSocial = 'red_social';
    case Hosting = 'hosting';
    case Cpanel = 'cpanel';
    case Dominio = 'dominio';
    case Email = 'email';
    case Respaldo2fa = 'respaldo_2fa';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::RedSocial => 'Red social',
            self::Hosting => 'Hosting',
            self::Cpanel => 'cPanel',
            self::Dominio => 'Dominio',
            self::Email => 'Email',
            self::Respaldo2fa => 'Códigos de respaldo 2FA',
            self::Otro => 'Otro',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
