<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('clients.view') || $user->can('clients.view_all');
    }

    public function view(User $user, Client $client): bool
    {
        if (! $this->viewAny($user)) {
            return false;
        }

        return $user->can('clients.view_all') || $client->users()->whereKey($user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('clients.manage');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can('clients.manage') && $this->view($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    /** Ver la papelera de clientes eliminados. */
    public function viewTrashed(User $user): bool
    {
        return $user->can('clients.manage');
    }

    /** Restaurar un cliente eliminado (no pasa por `view`: un eliminado no tiene equipo visible). */
    public function restore(User $user, Client $client): bool
    {
        return $user->can('clients.manage');
    }

    /** Asignar empleados al cliente. */
    public function assign(User $user, Client $client): bool
    {
        return $this->update($user, $client);
    }

    /** Ver montos y reparto de los servicios contratados (el empleado solo ve nombre y estado). */
    public function viewFinance(User $user, Client $client): bool
    {
        return $user->can('finance.view') && $this->view($user, $client);
    }

    /** Alta, edición y baja de servicios contratados (monto, vencimiento, empleado, reparto). */
    public function manageServices(User $user, Client $client): bool
    {
        return $user->can('finance.manage') && $this->view($user, $client);
    }

    /** Leer / copiar credenciales de la bóveda. */
    public function revealVault(User $user, Client $client): bool
    {
        return $user->can('vault.view') && $this->view($user, $client);
    }

    /** Crear / editar / borrar credenciales. */
    public function manageVault(User $user, Client $client): bool
    {
        return $user->can('vault.manage') && $this->view($user, $client);
    }
}
