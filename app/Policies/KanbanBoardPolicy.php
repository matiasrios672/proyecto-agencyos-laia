<?php

namespace App\Policies;

use App\Models\KanbanBoard;
use App\Models\User;

/** Autorización del tablero de un cliente (y de sus tarjetas): siempre depende de que el cliente sea visible para el usuario. */
class KanbanBoardPolicy
{
    /** Ver el tablero: content.view + cliente visible (asignado, o clients.view_all). */
    public function view(User $user, KanbanBoard $board): bool
    {
        return $user->can('content.view') && $user->can('view', $board->client);
    }

    /** Crear, editar, mover y eliminar tarjetas: content.manage + cliente visible. */
    public function manage(User $user, KanbanBoard $board): bool
    {
        return $user->can('content.manage') && $user->can('view', $board->client);
    }
}
