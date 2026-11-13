<?php

namespace App\Actions\Kanban;

use App\Models\Client;
use App\Models\KanbanBoard;
use App\Models\KanbanCard;
use App\Models\KanbanColumn;
use App\Models\User;
use App\Notifications\CardAssigned;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Alta y edición de tarjetas. El responsable debe ser alguien activo del equipo asignado al cliente. */
class SaveCard
{
    /** @param  array{title: string, description?: ?string, assigned_user_id?: ?int, due_date?: ?string, priority: string, column_id?: ?int}  $data */
    public function create(KanbanBoard $board, User $actor, array $data): KanbanCard
    {
        $assigneeId = $data['assigned_user_id'] ?? null;

        $card = DB::transaction(function () use ($board, $actor, $data, $assigneeId) {
            // Mismo bloqueo que MoveCard: el alta al final de la columna no choca con un movimiento simultáneo.
            $cards = KanbanCard::where('board_id', $board->id)->orderBy('id')->lockForUpdate()->get();

            $column = $this->column($board, $data['column_id'] ?? null);
            $this->assertAssignable($board->client, $assigneeId);

            $card = KanbanCard::create([
                'board_id' => $board->id,
                'column_id' => $column->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'assigned_user_id' => $assigneeId,
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'],
                'position' => $cards->where('column_id', $column->id)->count(),
                'created_by' => $actor->id,
                'completed_at' => $column->key === MoveCard::DONE_COLUMN_KEY ? now() : null,
            ]);

            activity('kanban')->performedOn($card)->causedBy($actor)
                ->withProperties(['client_id' => $board->client_id, 'column' => $column->name])
                ->log('Tarjeta creada');

            return $card;
        });

        $this->notify($card, $actor, null);

        return $card;
    }

    /** @param  array{title: string, description?: ?string, assigned_user_id?: ?int, due_date?: ?string, priority: string}  $data */
    public function update(KanbanCard $card, User $actor, array $data): KanbanCard
    {
        $previous = $card->assigned_user_id;
        $assigneeId = $data['assigned_user_id'] ?? null;

        DB::transaction(function () use ($card, $actor, $data, $assigneeId, $previous) {
            // Solo se revalida al responsable si cambió (alguien dado de baja no bloquea editar el resto de la tarjeta).
            if ($assigneeId !== $previous) {
                $this->assertAssignable($card->board->client, $assigneeId);
            }

            $card->update([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'assigned_user_id' => $assigneeId,
                'due_date' => $data['due_date'] ?? null,
                'priority' => $data['priority'],
            ]);

            activity('kanban')->performedOn($card)->causedBy($actor)
                ->withProperties(['client_id' => $card->board->client_id])->log('Tarjeta editada');
        });

        $this->notify($card, $actor, $previous);

        return $card;
    }

    private function column(KanbanBoard $board, ?int $columnId): KanbanColumn
    {
        $column = $columnId === null
            ? $board->columns()->first()
            : $board->columns()->whereKey($columnId)->first();

        if ($column === null) {
            throw ValidationException::withMessages(['column_id' => 'La columna no pertenece a este tablero.']);
        }

        return $column;
    }

    private function assertAssignable(Client $client, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $ok = $client->users()->where('users.is_active', true)->whereKey($userId)->exists();

        if (! $ok) {
            throw ValidationException::withMessages(['assigned_user_id' => 'Elegí a alguien activo del equipo asignado a este cliente.']);
        }
    }

    /** Aviso al nuevo responsable (nunca a uno mismo ni si no cambió). */
    private function notify(KanbanCard $card, User $actor, ?int $previousId): void
    {
        $id = $card->assigned_user_id;
        if ($id === null || $id === $previousId || $id === $actor->id) {
            return;
        }

        User::find($id)?->notify(new CardAssigned($card, $card->board->client, $actor->name));
    }
}
