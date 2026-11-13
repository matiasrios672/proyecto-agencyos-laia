<?php

namespace App\Console\Commands;

use App\Actions\Finance\SendPaymentReminders;
use Illuminate\Console\Command;

class SendRemindersCommand extends Command
{
    protected $signature = 'finance:send-reminders';

    protected $description = 'Envía los recordatorios de pago (antes del vencimiento y en mora)';

    public function handle(SendPaymentReminders $reminders): int
    {
        $r = $reminders->handle();
        $this->info("Recordatorios — antes: {$r['antes']}, mora: {$r['mora']}, clientes sin email: {$r['sin_email']}.");

        return self::SUCCESS;
    }
}
