<?php

namespace App\Actions\Quotes;

use App\Actions\Finance\GenerateInvoices;
use App\Enums\QuoteStatus;
use App\Enums\SplitRule;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use App\Notifications\ClientAssigned;
use App\Rules\Cuit;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Aprobar y Convertir": en una sola transacción deja el presupuesto aprobado y crea
 * (o reutiliza) el cliente, sus servicios contratados con empleado y regla de reparto, y el tablero inicial.
 */
class ConvertQuoteToClient
{
    /**
     * @param  array{start_date?: ?string, items?: array<int, array<string, mixed>>}  $data
     */
    public function handle(Quote $quote, User $actor, array $data = []): Client
    {
        $newlyAssigned = [];

        $client = DB::transaction(function () use ($quote, $actor, $data, &$newlyAssigned) {
            // Bloqueo de fila: dos clics simultáneos no pueden convertir dos veces el mismo presupuesto.
            $quote = Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $quote->load('items', 'client');

            $this->ensureConvertible($quote);

            $start = isset($data['start_date']) ? Carbon::parse($data['start_date']) : now();
            $assignments = collect($data['items'] ?? [])->keyBy('quote_item_id');

            $client = $quote->client ?? $this->createClientFromProspect($quote);
            $assignedIds = [];

            foreach ($quote->items as $index => $item) {
                $assignment = $assignments->get($item->id, []);
                $userId = $assignment['user_id'] ?? null;
                [$rule, $percent, $fixed] = $this->resolveSplit($item, $assignment, $userId, $index);

                if ($userId !== null) {
                    $this->ensureAssignable((int) $userId, $index);
                    $assignedIds[] = (int) $userId;
                }

                ClientService::create([
                    'client_id' => $client->id,
                    'quote_item_id' => $item->id,
                    'name' => $item->description,
                    'amount' => $item->lineTotal(),
                    'currency' => $quote->currency,
                    'frequency' => $item->frequency,
                    'due_day' => min(28, $start->day),
                    'assigned_user_id' => $userId,
                    'split_rule' => $rule,
                    'split_percent' => $percent,
                    'split_amount' => $fixed,
                    'status' => 'activo',
                    'started_at' => $start->toDateString(),
                ]);
            }

            // Quienes quedan asignados a un servicio también ven el cliente.
            if ($assignedIds !== []) {
                $newlyAssigned = $client->users()->syncWithoutDetaching(array_unique($assignedIds))['attached'];
            }

            $client->ensureBoard();

            $quote->forceFill([
                'status' => QuoteStatus::Aprobado,
                'approved_at' => now(),
                'converted_client_id' => $client->id,
            ])->save();

            activity('quotes')->performedOn($quote)->causedBy($actor)
                ->withProperties(['client_id' => $client->id, 'services' => $quote->items->count()])
                ->log('Presupuesto aprobado y convertido en cliente');

            return $client;
        });

        // Los avisos van fuera de la transacción: si fallan, la conversión ya quedó confirmada.
        User::whereIn('id', collect($newlyAssigned)->reject(fn ($id) => $id === $actor->id))->get()
            ->each(fn (User $u) => $u->notify(new ClientAssigned($client, $actor->name)));

        // El primer cobro queda listo desde el momento de la conversión (es idempotente: no duplica nada).
        app(GenerateInvoices::class)->forClient($client);

        return $client;
    }

    private function ensureConvertible(Quote $quote): void
    {
        if ($quote->status === QuoteStatus::Aprobado) {
            throw ValidationException::withMessages(['quote' => 'Este presupuesto ya fue aprobado y convertido.']);
        }
        if (! $quote->isEditable()) {
            throw ValidationException::withMessages(['quote' => 'Solo se pueden aprobar presupuestos en borrador o enviados.']);
        }
        if ($quote->isExpired()) {
            throw ValidationException::withMessages(['quote' => 'El presupuesto venció. Extendé la fecha de validez para poder aprobarlo.']);
        }
        if ($quote->items->isEmpty()) {
            throw ValidationException::withMessages(['quote' => 'El presupuesto no tiene ítems.']);
        }
    }

    private function createClientFromProspect(Quote $quote): Client
    {
        if (blank($quote->prospect_name)) {
            throw ValidationException::withMessages(['quote' => 'El presupuesto no tiene cliente ni datos del prospecto.']);
        }

        $taxId = Cuit::normalize($quote->prospect_tax_id);
        if ($taxId !== null && Client::where('tax_id', $taxId)->exists()) {
            throw ValidationException::withMessages([
                'quote' => "Ya existe un cliente con el CUIT {$taxId}. Elegilo como cliente existente en el presupuesto.",
            ]);
        }

        return Client::create([
            'business_name' => $quote->prospect_name,
            'tax_id' => $taxId,
            'email' => $quote->prospect_email,
            'phone' => $quote->prospect_phone,
            'whatsapp' => preg_replace('/\D/', '', (string) $quote->prospect_phone) ?: null,
            'account_status' => 'al_dia',
            'is_active' => true,
        ]);
    }

    /** @return array{0: string, 1: ?string, 2: ?int} regla, porcentaje, monto fijo en centavos */
    private function resolveSplit(QuoteItem $item, array $assignment, mixed $userId, int $index): array
    {
        // Sin empleado asignado no hay reparto: todo es para la agencia.
        if ($userId === null) {
            return [SplitRule::Agencia100->value, null, null];
        }

        $rule = SplitRule::from($assignment['split_rule'] ?? SplitRule::Agencia100->value);

        if ($rule === SplitRule::Porcentaje) {
            $percent = (float) ($assignment['split_percent'] ?? -1);
            if ($percent < 0 || $percent > 100) {
                throw ValidationException::withMessages(["items.{$index}.split_percent" => 'El porcentaje debe estar entre 0 y 100.']);
            }

            return [$rule->value, number_format($percent, 2, '.', ''), null];
        }

        if ($rule === SplitRule::MontoFijo) {
            $cents = Money::toCents($assignment['split_amount'] ?? -1);
            if ($cents < 0 || $cents > $item->lineTotal()) {
                throw ValidationException::withMessages(["items.{$index}.split_amount" => 'El monto fijo no puede ser negativo ni superar el importe del servicio.']);
            }

            return [$rule->value, null, $cents];
        }

        return [SplitRule::Agencia100->value, null, null];
    }

    private function ensureAssignable(int $userId, int $index): void
    {
        $ok = User::role(['empleado', 'dueno'])->where('is_active', true)->whereKey($userId)->exists();

        if (! $ok) {
            throw ValidationException::withMessages(["items.{$index}.user_id" => 'Elegí un empleado activo.']);
        }
    }
}
