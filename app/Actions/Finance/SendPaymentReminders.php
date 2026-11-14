<?php

namespace App\Actions\Finance;

use App\Mail\PaymentReminderMail;
use App\Support\MailTemplates;
use App\Models\Invoice;
use App\Models\ReminderLog;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;

/**
 * Recordatorios de pago por email, uno de cada tipo por cobro:
 *   antes → desde `dunning.reminder_before_days` días antes del vencimiento (por defecto 3)
 *   mora  → desde `dunning.reminder_after_days` días después del vencimiento (por defecto 1)
 * Si el cliente no tiene email no se registra el envío: se reintenta cuando lo cargue.
 */
class SendPaymentReminders
{
    /** @return array{antes: int, mora: int, sin_email: int} */
    public function handle(): array
    {
        $before = max(0, (int) Settings::get('dunning.reminder_before_days', '3'));
        $after = max(1, (int) Settings::get('dunning.reminder_after_days', '1'));
        $result = ['antes' => 0, 'mora' => 0, 'sin_email' => 0];

        $due = Invoice::pending()->with('client')->whereHas('client', fn ($q) => $q->where('is_active', true));

        // Antes del vencimiento: ya entró en la ventana de aviso y todavía no venció.
        (clone $due)->whereDate('due_date', '>=', today())->whereDate('due_date', '<=', today()->addDays($before))
            ->whereDoesntHave('reminders', fn ($q) => $q->where('type', 'antes'))
            ->each(function (Invoice $i) use (&$result) {
                $this->send($i, 'antes', $result);
            });

        // En mora: venció hace al menos N días.
        (clone $due)->whereDate('due_date', '<=', today()->subDays($after))
            ->whereDoesntHave('reminders', fn ($q) => $q->where('type', 'mora'))
            ->each(function (Invoice $i) use (&$result) {
                $this->send($i, 'mora', $result);
            });

        return $result;
    }

    private function send(Invoice $invoice, string $type, array &$result): void
    {
        $email = $invoice->client->email;

        if (! MailTemplates::enabled($type === 'mora' ? 'invoice.reminder_overdue' : 'invoice.reminder_before')) {
            return;
        }

        if (blank($email)) {
            $result['sin_email']++;

            return;
        }

        // El registro va primero (clave única): si dos procesos corren a la vez, solo uno envía.
        $log = ReminderLog::firstOrCreate(['invoice_id' => $invoice->id, 'type' => $type], ['sent_at' => now()]);
        if (! $log->wasRecentlyCreated) {
            return;
        }

        Mail::to($email)->send(new PaymentReminderMail($invoice, $type));
        $result[$type]++;
    }
}
