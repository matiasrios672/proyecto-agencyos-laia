<?php

namespace App\Actions\Finance;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidInvoice
{
    public function __construct(private readonly SyncAccountStatus $sync) {}

    public function handle(Invoice $invoice, User $actor, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            // Un cobro pagado ya generó liquidaciones: no se anula (evita dejar al empleado sin su parte).
            if ($invoice->status !== InvoiceStatus::Pendiente) {
                throw ValidationException::withMessages(['invoice' => 'Solo se pueden anular cobros pendientes.']);
            }

            $invoice->forceFill(['status' => InvoiceStatus::Anulada, 'void_reason' => $reason])->save();
            $this->sync->forClient($invoice->client);

            activity('finance')->performedOn($invoice)->causedBy($actor)
                ->withProperties(['reason' => $reason])->log('Cobro anulado');

            return $invoice;
        });
    }
}
