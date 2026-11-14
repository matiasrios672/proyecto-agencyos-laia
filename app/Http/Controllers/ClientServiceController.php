<?php

namespace App\Http\Controllers;

use App\Enums\SplitRule;
use App\Http\Requests\ClientServiceRequest;
use App\Models\CatalogService;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\User;
use App\Notifications\ClientAssigned;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Servicios contratados por un cliente: monto, vencimiento, empleado asignado y regla de reparto. */
class ClientServiceController extends Controller
{
    public function store(ClientServiceRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('manageServices', $client);

        $service = $client->services()->create($this->attributes($request, $client) + ['started_at' => today()]);
        $this->afterAssign($request, $client, $service);

        activity('clients')->performedOn($client)->causedBy($request->user())
            ->withProperties(['service' => $service->name])->log('Servicio contratado agregado');

        $savedToCatalog = $request->boolean('save_to_catalog') && $this->addToCatalog($request, $service);

        return back()->with('success', $savedToCatalog ? 'Servicio agregado y guardado en el catálogo.' : 'Servicio agregado.');
    }

    /** Un servicio nuevo escrito a mano puede quedar en el catálogo para los próximos clientes. Solo si no existe ya uno igual. */
    private function addToCatalog(Request $request, ClientService $service): bool
    {
        if (! Gate::allows('create', CatalogService::class)) {
            return false;
        }
        if (CatalogService::whereRaw('lower(name) = ?', [mb_strtolower($service->name)])->exists()) {
            return false;
        }

        $entry = CatalogService::create([
            'name' => $service->name,
            'price' => $service->amount,
            'currency' => $service->currency,
            'frequency' => $service->frequency,
            'is_active' => true,
        ]);
        activity('catalog')->performedOn($entry)->causedBy($request->user())->log('Servicio agregado al catálogo desde un cliente');

        return true;
    }

    public function update(ClientServiceRequest $request, Client $client, ClientService $service): RedirectResponse
    {
        Gate::authorize('manageServices', $client);
        abort_unless($service->client_id === $client->id, 404);

        $service->update($this->attributes($request, $client));
        $this->afterAssign($request, $client, $service);

        activity('clients')->performedOn($client)->causedBy($request->user())
            ->withProperties(['service' => $service->name])->log('Servicio contratado actualizado');

        return back()->with('success', 'Servicio actualizado.');
    }

    public function destroy(Request $request, Client $client, ClientService $service): RedirectResponse
    {
        Gate::authorize('manageServices', $client);
        abort_unless($service->client_id === $client->id, 404);

        $service->delete();
        activity('clients')->performedOn($client)->causedBy($request->user())
            ->withProperties(['service' => $service->name])->log('Servicio contratado eliminado');

        return back()->with('success', 'Servicio eliminado.');
    }

    /** @return array<string, mixed> */
    private function attributes(ClientServiceRequest $request, Client $client): array
    {
        $data = $request->validated();
        $amount = Money::toCents($data['amount']);
        $userId = $data['assigned_user_id'] ?? null;

        if ($userId !== null && ! User::role(['empleado', 'dueno'])->where('is_active', true)->whereKey($userId)->exists()) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Elegí un empleado activo.']);
        }

        // Sin empleado asignado no hay reparto posible.
        $rule = $userId === null ? SplitRule::Agencia100 : SplitRule::from($data['split_rule']);
        $fixed = $rule === SplitRule::MontoFijo ? Money::toCents($data['split_amount']) : null;

        if ($fixed !== null && $fixed > $amount) {
            throw ValidationException::withMessages(['split_amount' => 'El monto fijo no puede superar el monto del servicio.']);
        }

        return [
            'name' => $data['name'],
            'amount' => $amount,
            'currency' => $data['currency'],
            'frequency' => $data['frequency'],
            'due_day' => $data['due_day'],
            'billing_timing' => $data['billing_timing'] ?? 'adelantada',
            'assigned_user_id' => $userId,
            'split_rule' => $rule,
            'split_percent' => $rule === SplitRule::Porcentaje ? $data['split_percent'] : null,
            'split_amount' => $fixed,
            'status' => $data['status'],
        ];
    }

    /** Quien queda asignado a un servicio también ve el cliente (y recibe el aviso si es nuevo). */
    private function afterAssign(Request $request, Client $client, ClientService $service): void
    {
        if ($service->assigned_user_id === null) {
            return;
        }

        $attached = $client->users()->syncWithoutDetaching([$service->assigned_user_id])['attached'];

        if ($attached !== [] && $service->assigned_user_id !== $request->user()->id) {
            $service->assignee->notify(new ClientAssigned($client, $request->user()->name));
        }
    }
}
