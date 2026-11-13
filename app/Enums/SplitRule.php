<?php

namespace App\Enums;

/** Cómo se reparte lo cobrado de un servicio entre la agencia y el empleado asignado. */
enum SplitRule: string
{
    case Porcentaje = 'porcentaje';
    case MontoFijo = 'monto_fijo';
    case Agencia100 = '100_agencia';

    public function label(): string
    {
        return match ($this) {
            self::Porcentaje => 'Porcentaje',
            self::MontoFijo => 'Monto fijo',
            self::Agencia100 => '100 % agencia',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
