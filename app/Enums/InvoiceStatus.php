<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Pendiente = 'pendiente';
    case Pagada = 'pagada';
    case Anulada = 'anulada';
    /** Estado derivado (no se guarda): pendiente con fecha de vencimiento pasada. */
    case Vencida = 'vencida';

    public function label(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Pagada => 'Pagada',
            self::Anulada => 'Anulada',
            self::Vencida => 'Vencida',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
