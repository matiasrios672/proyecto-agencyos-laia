<?php

namespace App\Console\Commands;

use App\Actions\Finance\SendPaymentReminders;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'finance:send-reminders';

    protected $description = 'Envía los avisos de pago si el modo automático está activo; si no, deja los avisos listos para revisar en Cobranzas';

    public function handle(SendPaymentReminders $reminders): int
    {
        $r = $reminders->handle();

        if (! $r['auto']) {
            $this->info("Modo manual: {$reminders->notices()->count()} avisos esperan revisión en Cobranzas.");

            return self::SUCCESS;
        }

        $this->info("Recordatorios — antes: {$r['antes']}, mora: {$r['mora']}, clientes sin email: {$r['sin_email']}, pausados: {$r['pausados']}.");

        return self::SUCCESS;
    }
}
