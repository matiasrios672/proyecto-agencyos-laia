<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Transferencia = 'transferencia';
    case Efectivo = 'efectivo';
    case MercadoPago = 'mercadopago';
    case Otro = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Transferencia => 'Transferencia bancaria',
            self::Efectivo => 'Efectivo',
            self::MercadoPago => 'Mercado Pago',
            self::Otro => 'Otro',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
