<?php

namespace App\Actions\Finance;

use App\Mail\PaymentReminderMail;
use App\Models\Invoice;
use App\Models\ReminderLog;
use App\Models\User;
use App\Support\MailTemplates;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos de pago por email, uno de cada tipo por cobro:
 *   antes → desde `dunning.reminder_before_days` días antes del vencimiento (por defecto 3)
 *   mora  → desde `dunning.reminder_after_days` días después del vencimiento (por defecto 1)
 *
 * Dos formas de enviarlos:
 *   - automática (`handle`, la corre el cron): solo si `dunning.reminders_auto` está activo.
 *   - guiada (`sendNotice`): una persona los revisa y los manda desde Cobranzas.
 * Un cliente con los avisos pausados nunca recibe ninguno. Sin email no se registra el envío (se reintenta).
 */
class SendPaymentReminders
{
    public const READY = 'listo';

    public const NO_EMAIL = 'sin_email';

    public const PAUSED = 'pausado';

    public static function auto(): bool
    {
        return Settings::get('dunning.reminders_auto') === '1';
    }

    /**
     * Avisos que corresponden hoy y todavía no se mandaron.
     *
     * @return Collection<int, array{invoice: Invoice, type: string, state: string}>
     */
    public function notices(): Collection
    {
        $before = max(0, (int) Settings::get('dunning.reminder_before_days', '3'));
        $after = max(1, (int) Settings::get('dunning.reminder_after_days', '1'));

        $due = Invoice::pending()->with(['client', 'service.assignee'])->whereHas('client', fn ($q) => $q->where('is_active', true));

        $upcoming = (clone $due)->whereDate('due_date', '>=', today())->whereDate('due_date', '<=', today()->addDays($before))
            ->whereDoesntHave('reminders', fn ($q) => $q->where('type', 'antes'))->get()
            ->map(fn (Invoice $i) => ['invoice' => $i, 'type' => 'antes']);

        $late = (clone $due)->whereDate('due_date', '<=', today()->subDays($after))
            ->whereDoesntHave('reminders', fn ($q) => $q->where('type', 'mora'))->get()
            ->map(fn (Invoice $i) => ['invoice' => $i, 'type' => 'mora']);

        return $upcoming->concat($late)
            ->filter(fn (array $n) => MailTemplates::enabled(self::templateKey($n['type'])))
            ->map(fn (array $n) => $n + ['state' => $this->state($n['invoice'])])
            ->sortBy(fn (array $n) => $n['invoice']->due_date->timestamp)
            ->values();
    }

    /**
     * Envío automático (cron). Si el modo automático está apagado no manda nada.
     *
     * @return array{antes: int, mora: int, sin_email: int, pausados: int, auto: bool}
     */
    public function handle(): array
    {
        $result = ['antes' => 0, 'mora' => 0, 'sin_email' => 0, 'pausados' => 0, 'auto' => self::auto()];

        if (! $result['auto']) {
            return $result;
        }

        foreach ($this->notices() as $notice) {
            $outcome = $this->deliver($notice['invoice'], $notice['type'], null);
            match ($outcome) {
                'enviado' => $result[$notice['type']]++,
                self::NO_EMAIL => $result['sin_email']++,
                self::PAUSED => $result['pausados']++,
                default => null,
            };
        }

        return $result;
    }

    /**
     * Envío guiado: manda un aviso que hoy corresponde. Devuelve 'enviado' o el motivo por el que no salió.
     */
    public function sendNotice(Invoice $invoice, string $type, User $actor): string
    {
        $applies = $this->notices()->contains(fn (array $n) => $n['invoice']->is($invoice) && $n['type'] === $type);

        return $applies ? $this->deliver($invoice, $type, $actor) : 'no_corresponde';
    }

    public static function templateKey(string $type): string
    {
        return $type === 'mora' ? 'invoice.reminder_overdue' : 'invoice.reminder_before';
    }

    private function state(Invoice $invoice): string
    {
        return match (true) {
            $invoice->client->reminders_paused => self::PAUSED,
            blank($invoice->client->email) => self::NO_EMAIL,
            default => self::READY,
        };
    }

    private function deliver(Invoice $invoice, string $type, ?User $actor): string
    {
        $state = $this->state($invoice);
        if ($state !== self::READY) {
            return $state;
        }

        // El registro va primero (clave única): si dos procesos corren a la vez, solo uno envía.
        $log = ReminderLog::firstOrCreate(['invoice_id' => $invoice->id, 'type' => $type], ['sent_at' => now(), 'sent_by' => $actor?->id]);
        if (! $log->wasRecentlyCreated) {
            return 'ya_enviado';
        }

        Mail::to($invoice->client->email)->send(new PaymentReminderMail($invoice, $type));

        return 'enviado';
    }
}
