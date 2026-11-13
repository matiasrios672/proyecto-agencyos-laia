<?php

namespace App\Enums;

enum TaxCondition: string
{
    case ResponsableInscripto = 'responsable_inscripto';
    case Monotributista = 'monotributista';
    case Exento = 'exento';
    case ConsumidorFinal = 'consumidor_final';

    public function label(): string
    {
        return match ($this) {
            self::ResponsableInscripto => 'Responsable Inscripto',
            self::Monotributista => 'Monotributista',
            self::Exento => 'Exento',
            self::ConsumidorFinal => 'Consumidor Final',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
