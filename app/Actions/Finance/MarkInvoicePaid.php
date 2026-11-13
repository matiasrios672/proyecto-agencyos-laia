<?php

namespace App\Actions\Finance;

use App\Enums\InvoiceStatus;
use App\Enums\SplitRule;
use App\Models\Invoice;
use App\Models\TeamPayout;
use App\Models\User;
use App\Notifications\PayoutGenerated;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra el pago de un cobro y, en la misma transacción, genera la liquidación pendiente
 * del empleado asignado según la regla de reparto del servicio.
 */
class MarkInvoicePaid
{
    public function __construct(private readonly SyncAccountStatus $sync) {}

    /** @param  array{payment_method?: ?string, payment_reference?: ?string, paid_at?: ?string}  $data */
    public function handle(Invoice $invoice, User $actor, array $data = []): Invoice
    {
        $payout = null;

        $invoice = DB::transaction(function () use ($invoice, $actor, $data, &$payout) {
            // Bloqueo de fila: dos clics (o dos usuarios) no pueden cobrar el mismo cobro dos veces.
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === InvoiceStatus::Pagada) {
                throw ValidationException::withMessages(['invoice' => 'Este cobro ya está registrado como pagado.']);
            }
            if ($invoice->status === InvoiceStatus::Anulada) {
                throw ValidationException::withMessages(['invoice' => 'No se puede cobrar un cobro anulado.']);
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Pagada,
                'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at'])->setTimeFrom(now()) : now(),
                'payment_method' => $data['payment_method'] ?? null,
                'payment_reference' => $data['payment_reference'] ?? null,
            ])->save();

            $payout = $this->createPayout($invoice);
            $this->sync->forClient($invoice->client);

            activity('finance')->performedOn($invoice)->causedBy($actor)
                ->withProperties(['amount' => $invoice->amount, 'payout' => $payout?->amount])
                ->log('Cobro registrado como pagado');

            return $invoice;
        });

        // El aviso al empleado va fuera de la transacción: si falla, el cobro ya quedó confirmado.
        $payout?->user->notify(new PayoutGenerated($payout, $invoice->client->displayName()));

        return $invoice->load('payouts');
    }

    private function createPayout(Invoice $invoice): ?TeamPayout
    {
        $service = $invoice->service;
        if ($service === null || $service->assigned_user_id === null) {
            return null;
        }

        $share = $service->employeeShare($invoice->amount);
        if ($share <= 0) {
            return null;
        }

        return TeamPayout::firstOrCreate(
            ['invoice_id' => $invoice->id, 'user_id' => $service->assigned_user_id],
            [
                'client_service_id' => $service->id,
                'amount' => $share,
                'currency' => $invoice->currency,
                'calculation' => $this->describe($service, $invoice, $share),
                'status' => 'pendiente',
            ],
        );
    }

    /** Constancia legible de cómo se calculó la liquidación. */
    private function describe($service, Invoice $invoice, int $share): string
    {
        $total = Money::format($invoice->amount, $invoice->currency);

        return match ($service->split_rule) {
            SplitRule::Porcentaje => rtrim(rtrim(number_format((float) $service->split_percent, 2, ',', '.'), '0'), ',')." % de {$total}",
            SplitRule::MontoFijo => 'Monto fijo de '.Money::format((int) $service->split_amount, $invoice->currency)
                .($share < (int) $service->split_amount ? " (tope: lo cobrado, {$total})" : ''),
            default => "Sobre {$total}",
        };
    }
}
