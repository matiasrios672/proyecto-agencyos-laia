<?php

namespace App\Http\Controllers;

use App\Http\Requests\VaultItemRequest;
use App\Models\Client;
use App\Models\VaultItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class VaultItemController extends Controller
{
    public function store(VaultItemRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('manageVault', $client);

        $item = $client->vaultItems()->create($request->validated() + ['created_by' => $request->user()->id]);

        $this->audit($request, $item, 'create', 'Credencial creada en la bóveda');

        return back()->with('success', 'Credencial guardada.');
    }

    public function update(VaultItemRequest $request, Client $client, VaultItem $item): RedirectResponse
    {
        Gate::authorize('manageVault', $client);
        abort_unless($item->client_id === $client->id, 404);

        $data = $request->validated();
        // Secreto / notas vacíos en edición = no modificar.
        foreach (['secret', 'notes'] as $field) {
            if (($data[$field] ?? '') === '') {
                unset($data[$field]);
            }
        }
        $item->update($data);

        $this->audit($request, $item, 'update', 'Credencial editada en la bóveda');

        return back()->with('success', 'Credencial actualizada.');
    }

    public function destroy(Request $request, Client $client, VaultItem $item): RedirectResponse
    {
        Gate::authorize('manageVault', $client);
        abort_unless($item->client_id === $client->id, 404);

        $item->delete();

        $this->audit($request, $item, 'delete', 'Credencial eliminada de la bóveda');

        return back()->with('success', 'Credencial eliminada.');
    }

    /**
     * Devuelve el secreto descifrado. Cada lectura o copia deja un registro obligatorio en activity_log;
     * si el registro falla, no se entrega el secreto.
     */
    public function reveal(Request $request, Client $client, VaultItem $item): JsonResponse
    {
        Gate::authorize('revealVault', $client);
        abort_unless($item->client_id === $client->id, 404);

        $data = $request->validate(['action' => ['required', 'in:read,copy'], 'field' => ['sometimes', 'in:secret,notes']]);
        $field = $data['field'] ?? 'secret';

        $this->audit(
            $request,
            $item,
            $data['action'],
            $data['action'] === 'copy' ? 'Credencial copiada al portapapeles' : 'Credencial revelada',
            ['field' => $field],
        );

        return response()->json(['value' => $item->{$field}])
            ->header('Cache-Control', 'no-store, private');
    }

    private function audit(Request $request, VaultItem $item, string $action, string $description, array $extra = []): void
    {
        // Nunca se registran valores secretos, solo quién hizo qué sobre qué ítem.
        activity('vault')
            ->performedOn($item)
            ->causedBy($request->user())
            ->event($action)
            ->withProperties([
                'client_id' => $item->client_id,
                'label' => $item->label,
                'ip' => $request->ip(),
            ] + $extra)
            ->log($description);
    }
}
