<?php

namespace App\Services\LaiaTime;

use App\Models\Client;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Reglas de mora para suspender LaiaTime (todo sale de Configuración → "Mora y suspensión"):
 *   - se suspende cuando el cobro pendiente más antiguo venció hace MÁS de `dunning.grace_days` días;
 *   - hasta ese plazo el cliente está "en gracia".
 */
class DebtPolicy
{
    public static function autoSuspend(): bool
    {
        return Settings::get('dunning.auto_suspend') === '1';
    }

    public static function graceDays(): int
    {
        return max(0, (int) Settings::get('dunning.grace_days', '10'));
    }

    /** Días de mora del cobro pendiente más antiguo (0 si el cliente no tiene cobros vencidos). */
    public static function overdueDays(Client $client): int
    {
        $oldest = $client->invoices()->overdue()->min('due_date');

        if ($oldest === null) {
            return 0;
        }

        return (int) abs(Carbon::parse($oldest)->startOfDay()->diffInDays(today()));
    }

    public static function isOutOfGrace(int $overdueDays): bool
    {
        return $overdueDays > self::graceDays();
    }

    public static function isInGrace(int $overdueDays): bool
    {
        return $overdueDays > 0 && ! self::isOutOfGrace($overdueDays);
    }

    /** Nuevo plazo de gracia que se concede al reactivar a mano. */
    public static function overrideDeadline(): Carbon
    {
        return today()->addDays(max(1, self::graceDays()));
    }
}
