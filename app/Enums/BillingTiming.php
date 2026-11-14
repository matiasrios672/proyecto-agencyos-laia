<?php

namespace App\Enums;

/**
 * Cuándo se cobra un servicio mensual:
 *  - adelantada: el cobro del mes vence dentro de ese mismo mes (se emite unos días antes).
 *  - vencida: el servicio del mes se cobra al terminar; el cobro de noviembre se emite el 1 de diciembre
 *    y vence el día elegido de diciembre.
 */
enum BillingTiming: string
{
    case Adelantada = 'adelantada';
    case Vencida = 'vencida';

    public function label(): string
    {
        return match ($this) {
            self::Adelantada => 'Adelantada (dentro del mes)',
            self::Vencida => 'Mes vencido (al terminar el mes)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
