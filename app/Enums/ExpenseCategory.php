<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case Software = 'software';
    case Hosting = 'hosting';
    case Apis = 'apis';
    case Marketing = 'marketing';
    case Oficina = 'oficina';
    case Impuestos = 'impuestos';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Software => 'Software y suscripciones',
            self::Hosting => 'Hosting y servidores',
            self::Apis => 'APIs y servicios',
            self::Marketing => 'Marketing',
            self::Oficina => 'Oficina y servicios',
            self::Impuestos => 'Impuestos y tasas',
            self::Otro => 'Otro',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
