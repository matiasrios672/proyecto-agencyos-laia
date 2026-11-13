<?php

namespace App\Enums;

enum ContentStatus: string
{
    case Borrador = 'borrador';
    case RevisionInterna = 'revision_interna';
    case EnviadoCliente = 'enviado_cliente';
    case Aprobado = 'aprobado';
    case EnCurso = 'en_curso';

    public function label(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::RevisionInterna => 'Revisión interna',
            self::EnviadoCliente => 'Enviado al cliente',
            self::Aprobado => 'Aprobado',
            self::EnCurso => 'En curso',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
