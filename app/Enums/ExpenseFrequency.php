<?php

namespace App\Enums;

enum ExpenseFrequency: string
{
    case Mensual = 'mensual';
    case Anual = 'anual';
    case Unico = 'unico';

    public function label(): string
    {
        return match ($this) {
            self::Mensual => 'Mensual',
            self::Anual => 'Anual',
            self::Unico => 'Pago único',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
