<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use App\Notifications\ClientAssigned;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Asignación de empleados a un cliente. */
class ClientTeamController extends Controller
{
    public function update(Request $request, Client $client): RedirectResponse
    {
        Gate::authorize('assign', $client);

        $data = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        // Solo se pueden asignar usuarios activos con rol dueño/empleado.
        $valid = User::role(['empleado', 'dueno'])->where('is_active', true)
            ->whereIn('id', $data['user_ids'])->pluck('id')->all();

        $changes = $client->users()->sync($valid);

        activity('clients')->performedOn($client)->causedBy($request->user())
            ->withProperties(['attached' => $changes['attached'], 'detached' => $changes['detached']])
            ->log('Equipo del cliente actualizado');

        // Aviso interno solo a quienes se agregaron ahora (y no a quien hizo el cambio).
        $notify = collect($changes['attached'])->reject(fn ($id) => $id === $request->user()->id);
        User::whereIn('id', $notify)->get()
            ->each(fn (User $u) => $u->notify(new ClientAssigned($client, $request->user()->name)));

        return back()->with('success', 'Equipo actualizado.');
    }
}
