<?php

namespace App\Enums;

enum QuoteStatus: string
{
    case Borrador = 'borrador';
    case Enviado = 'enviado';
    case Aprobado = 'aprobado';
    case Rechazado = 'rechazado';
    case Vencido = 'vencido';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Enviado => 'Enviado',
            self::Aprobado => 'Aprobado',
            self::Rechazado => 'Rechazado',
            self::Vencido => 'Vencido',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
