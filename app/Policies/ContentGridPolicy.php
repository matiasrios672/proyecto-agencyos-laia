<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\ContentGrid;
use App\Models\User;

/**
 * Ver = content.view · gestionar (subir, estado, compartir, borrar) = content.manage.
 * En ambos casos el cliente tiene que ser visible para el usuario (un empleado solo ve los que tiene asignados).
 */
class ContentGridPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('content.view');
    }

    public function view(User $user, ContentGrid $grid): bool
    {
        return $grid->client !== null && $this->viewForClient($user, $grid->client);
    }

    public function manage(User $user, ContentGrid $grid): bool
    {
        return $grid->client !== null && $this->manageForClient($user, $grid->client);
    }

    public function viewForClient(User $user, Client $client): bool
    {
        return $user->can('content.view') && $user->can('view', $client);
    }

    public function manageForClient(User $user, Client $client): bool
    {
        return $user->can('content.manage') && $user->can('view', $client);
    }
}
