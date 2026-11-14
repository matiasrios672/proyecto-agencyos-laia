<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Support\Money;
use App\Support\Settings;

class PaymentReminderMail extends TemplateMail
{
    public function __construct(public Invoice $invoice, public string $type) {}

    protected function templateKey(): string
    {
        return $this->type === 'mora' ? 'invoice.reminder_overdue' : 'invoice.reminder_before';
    }

    protected function templateVars(): array
    {
        return [
            'nombre' => $this->invoice->client->displayName(),
            'concepto' => $this->invoice->description,
            'numero' => $this->invoice->number,
            'importe' => Money::format($this->invoice->amount, $this->invoice->currency),
            'vencimiento' => $this->invoice->due_date->locale('es')->translatedFormat('j \d\e F \d\e Y'),
            'vencimiento_corto' => $this->invoice->due_date->format('d/m'),
            'datos_bancarios' => $this->bankBlock(),
        ];
    }

    /** Datos de transferencia como lista Markdown (vacío si no hay ninguno cargado). */
    private function bankBlock(): string
    {
        $lines = collect(['holder' => 'Titular', 'cuit' => 'CUIT', 'name' => 'Banco', 'cbu' => 'CBU / CVU', 'alias' => 'Alias'])
            ->mapWithKeys(fn ($label, $key) => [$label => Settings::get("bank.{$key}")])
            ->filter()
            ->map(fn ($value, $label) => '- **'.e($label).':** '.e($value))
            ->implode("\n");

        return $lines === '' ? '' : "Podés abonar por transferencia a:\n\n".$lines;
    }
}
