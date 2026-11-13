<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Numeración correlativa por año (C-2026-0001). Debe llamarse dentro de una transacción. */
class Sequence
{
    public static function next(string $prefix, string $table, string $column = 'number'): string
    {
        $year = now()->year;
        // El lock de asesoramiento (liberado al cerrar la transacción) evita números repetidos en altas simultáneas.
        DB::select('select pg_advisory_xact_lock(?)', [crc32($table.$year)]);

        $last = DB::table($table)->where($column, 'like', "{$prefix}-{$year}-%")->max($column);
        $next = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('%s-%d-%04d', $prefix, $year, $next);
    }
}
