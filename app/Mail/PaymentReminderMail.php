<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\BankAccount;
use App\Support\Money;

class PaymentReminderMail extends TemplateMail
{
    public function __construct(public Invoice $invoice, public string $type) {}

    protected function templateKey(): string
    {
        return $this->type === 'mora' ? 'invoice.reminder_overdue' : 'invoice.reminder_before';
    }

    public function templateVars(): array
    {
        return [
            'nombre' => $this->invoice->client->displayName(),
            'concepto' => $this->invoice->description,
            'numero' => $this->invoice->number,
            'importe' => Money::format($this->invoice->amount, $this->invoice->currency),
            'vencimiento' => $this->invoice->due_date->locale('es')->translatedFormat('j \d\e F \d\e Y'),
            'vencimiento_corto' => $this->invoice->due_date->format('d/m'),
            'plazo' => $this->deadline(),
            'datos_bancarios' => $this->bankBlock(),
        ];
    }

    /** "vence hoy", "vence mañana", "vence en 3 días" / "venció ayer", "venció hace 3 días". */
    private function deadline(): string
    {
        $days = (int) today()->diffInDays($this->invoice->due_date->startOfDay(), false);

        return match (true) {
            $days === 0 => 'vence hoy',
            $days === 1 => 'vence mañana',
            $days > 1 => "vence en {$days} días",
            $days === -1 => 'venció ayer',
            default => 'venció hace '.abs($days).' días',
        };
    }

    /** Datos de transferencia como lista Markdown (vacío si no hay ninguno cargado). */
    private function bankBlock(): string
    {
        $fields = BankAccount::forInvoice($this->invoice)['fields'];
        $lines = collect(BankAccount::FIELDS)
            ->filter(fn ($f, $key) => isset($fields[$key]))
            ->map(fn ($f, $key) => '- **'.e($f[0]).':** '.e($fields[$key]))
            ->implode("\n");

        return $lines === '' ? '' : "**Datos para transferir**\n\n".$lines;
    }
}
