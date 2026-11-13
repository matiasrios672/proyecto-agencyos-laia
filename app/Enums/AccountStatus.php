<?php

namespace App\Enums;

enum AccountStatus: string
{
    case AlDia = 'al_dia';
    case PorVencer = 'por_vencer';
    case Deuda = 'deuda';

    public function label(): string
    {
        return match ($this) {
            self::AlDia => 'Al día',
            self::PorVencer => 'Por vencer',
            self::Deuda => 'Deuda',
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
