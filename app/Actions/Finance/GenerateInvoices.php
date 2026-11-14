<?php

namespace App\Actions\Finance;

use App\Enums\BillingTiming;
use App\Enums\Frequency;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Invoice;
use App\Support\Sequence;
use App\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Genera los cobros de los servicios contratados. Es idempotente: la restricción única (servicio, período)
 * garantiza que correrlo todos los días, o dos veces seguidas, nunca duplica un cobro.
 *
 *  - Mensual adelantado: un cobro por mes, que se emite `finance.invoice_lead_days` días antes del vencimiento.
 *  - Mensual a mes vencido: el cobro del mes M se emite el día 1 del mes siguiente y vence el día elegido de ese mes.
 *  - Pago único: un solo cobro, con vencimiento en la fecha de inicio del servicio.
 */
class GenerateInvoices
{
    public function __construct(private readonly SyncAccountStatus $sync) {}

    /** @return int cobros creados */
    public function forAll(?Carbon $today = null): int
    {
        $created = 0;

        ClientService::query()->where('status', 'activo')->whereHas('client', fn ($q) => $q->where('is_active', true))
            ->each(function (ClientService $service) use (&$created, $today) {
                $created += $this->forService($service, $today);
            });

        $this->sync->forAll();

        return $created;
    }

    public function forClient(Client $client, ?Carbon $today = null): int
    {
        $created = $client->services()->where('status', 'activo')->get()
            ->sum(fn (ClientService $s) => $this->forService($s, $today));

        $this->sync->forClient($client->refresh());

        return (int) $created;
    }

    public function forService(ClientService $service, ?Carbon $today = null): int
    {
        $today ??= today();

        if ($service->status !== 'activo' || ! $service->client?->is_active) {
            return 0;
        }

        if ($service->frequency === Frequency::Unico) {
            $due = ($service->started_at ?? $today)->copy();

            return $this->create($service, 'unico', $due, $service->name, $today) ? 1 : 0;
        }

        $startMonth = ($service->started_at ?? $today)->copy()->startOfMonth();
        $created = 0;

        if ($service->billing_timing === BillingTiming::Vencida) {
            // Solo se cobra un mes ya terminado: el período es el mes anterior y vence en el mes en curso.
            $month = $today->copy()->subMonthNoOverflow()->startOfMonth();
            if ($month->lt($startMonth)) {
                return 0;
            }

            $due = $today->copy()->startOfMonth()->day(min($service->due_day, $today->daysInMonth));
            $label = ucfirst($month->locale('es')->translatedFormat('F Y'));

            return $this->create($service, $month->format('Y-m'), $due, "{$service->name} — {$label}", $today) ? 1 : 0;
        }

        $lead = max(0, (int) Settings::get('finance.invoice_lead_days', '10'));

        // Mes actual y el siguiente: así el cobro de principios de mes se emite a fin del mes anterior.
        foreach ([$today->copy()->startOfMonth(), $today->copy()->addMonthNoOverflow()->startOfMonth()] as $month) {
            if ($month->lt($startMonth)) {
                continue;
            }

            $due = $month->copy()->day(min($service->due_day, $month->daysInMonth));
            if ($today->lt($due->copy()->subDays($lead))) {
                continue;
            }
            // Un vencimiento anterior al inicio del servicio no se cobra (el servicio todavía no existía).
            if ($service->started_at && $due->lt($service->started_at)) {
                continue;
            }

            $label = ucfirst($month->locale('es')->translatedFormat('F Y'));
            $created += $this->create($service, $month->format('Y-m'), $due, "{$service->name} — {$label}", $today) ? 1 : 0;
        }

        return $created;
    }

    private function create(ClientService $service, string $period, Carbon $due, string $description, Carbon $today): bool
    {
        if (Invoice::where('client_service_id', $service->id)->where('period', $period)->exists()) {
            return false;
        }

        try {
            DB::transaction(fn () => Invoice::create([
                'number' => Sequence::next('C', 'invoices'),
                'client_id' => $service->client_id,
                'client_service_id' => $service->id,
                'description' => $description,
                'amount' => $service->amount,
                'currency' => $service->currency,
                'period' => $period,
                'issue_date' => $today,
                'due_date' => $due,
                'status' => 'pendiente',
            ]));
        } catch (UniqueConstraintViolationException) {
            return false; // otro proceso lo creó en el mismo instante
        }

        return true;
    }
}
