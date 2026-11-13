<?php

namespace App\Actions\Kanban;

use App\Models\KanbanCard;
use App\Models\KanbanColumn;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mueve una tarjeta a una columna / posición del MISMO tablero y deja las posiciones de las columnas
 * afectadas como 0..n-1, sin huecos ni duplicados.
 *
 * Concurrencia: se bloquean (en orden de id, para evitar deadlocks) todas las tarjetas del tablero antes de
 * calcular nada, así dos movimientos simultáneos se serializan y cada uno parte del estado ya confirmado del otro.
 * Idempotente: repetir el mismo movimiento deja el mismo estado y no vuelve a auditarse.
 */
class MoveCard
{
    /** Clave de la columna que cuenta como "completada". */
    public const DONE_COLUMN_KEY = 'publicado';

    /**
     * @return Collection<int, KanbanCard> tarjetas de las columnas afectadas, ya con su estado final
     */
    public function handle(KanbanCard $card, int $toColumnId, int $position, User $actor): Collection
    {
        return DB::transaction(function () use ($card, $toColumnId, $position, $actor) {
            $cards = KanbanCard::where('board_id', $card->board_id)->orderBy('id')->lockForUpdate()->get();

            /** @var KanbanCard|null $card */
            $card = $cards->firstWhere('id', $card->id);
            if ($card === null) {
                throw ValidationException::withMessages(['card' => 'La tarjeta ya no existe.']);
            }

            $columns = KanbanColumn::where('board_id', $card->board_id)->get()->keyBy('id');
            $target = $columns->get($toColumnId);
            if ($target === null) {
                throw ValidationException::withMessages(['column_id' => 'La columna no pertenece a este tablero.']);
            }

            $fromColumnId = $card->column_id;
            $others = fn (int $columnId) => $cards->where('column_id', $columnId)->where('id', '!=', $card->id)
                ->sortBy([['position', 'asc'], ['id', 'asc']])->values();

            // Lista destino sin la tarjeta, con la tarjeta insertada en la posición pedida (acotada al rango válido).
            $destination = $others($toColumnId);
            $index = max(0, min($position, $destination->count()));
            $destination->splice($index, 0, [$card]);

            $changedColumn = $fromColumnId !== $toColumnId;
            $wasDone = $card->completed_at !== null;
            $isDone = $columns->get($toColumnId)->key === self::DONE_COLUMN_KEY;

            $this->reindex($destination, $toColumnId);
            if ($changedColumn) {
                $this->reindex($others($fromColumnId), $fromColumnId);
            }

            // Completada solo mientras esté en "Publicado": entrar la marca, salir la limpia.
            $card->completed_at = $isDone ? ($wasDone ? $card->completed_at : now()) : null;
            if ($card->isDirty()) {
                $card->save();
            }

            if ($changedColumn) {
                activity('kanban')->performedOn($card)->causedBy($actor)
                    ->withProperties([
                        'client_id' => $card->board->client_id,
                        'from' => $columns->get($fromColumnId)?->name,
                        'to' => $target->name,
                    ])
                    ->log("Tarjeta movida de «{$columns->get($fromColumnId)?->name}» a «{$target->name}»");
            }

            return KanbanCard::where('board_id', $card->board_id)
                ->whereIn('column_id', array_unique([$fromColumnId, $toColumnId]))
                ->orderBy('column_id')->orderBy('position')->get();
        });
    }

    /** Deja las posiciones de una columna como 0..n-1 (y la columna correcta) escribiendo solo lo que cambió. */
    private function reindex(Collection $ordered, int $columnId): void
    {
        foreach ($ordered->values() as $i => $item) {
            if ($item->position !== $i || $item->column_id !== $columnId) {
                KanbanCard::whereKey($item->id)->update(['position' => $i, 'column_id' => $columnId]);
                $item->position = $i;
                $item->column_id = $columnId;
                $item->syncOriginal();
            }
        }
    }
}
