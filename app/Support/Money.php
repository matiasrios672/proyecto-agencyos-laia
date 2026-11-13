<?php

namespace App\Support;

use App\Enums\Currency;

/** Dinero: se guarda en centavos (enteros) y se muestra con formato argentino (1.234,56). */
class Money
{
    public static function format(int $cents, Currency|string $currency = Currency::ARS): string
    {
        $currency = $currency instanceof Currency ? $currency : Currency::from($currency);

        return $currency->symbol().' '.number_format($cents / 100, 2, ',', '.');
    }

    /** Convierte "1500.50" / 1500.5 a centavos sin errores de punto flotante. */
    public static function toCents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** Centavos → decimal con 2 cifras ("1500.50"), útil para formularios. */
    public static function toDecimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
