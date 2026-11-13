<?php

namespace App\Enums;

enum Currency: string
{
    case ARS = 'ARS';
    case USD = 'USD';

    public function symbol(): string
    {
        return match ($this) {
            self::ARS => '$',
            self::USD => 'US$',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ARS => 'Pesos argentinos (ARS)',
            self::USD => 'Dólares (USD)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
