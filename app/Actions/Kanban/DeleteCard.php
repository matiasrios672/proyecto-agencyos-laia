<?php

namespace App\Actions\Kanban;

use App\Models\KanbanCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteCard
{
    /** Elimina la tarjeta y deja la columna con posiciones 0..n-1. */
    public function handle(KanbanCard $card, User $actor): void
    {
        DB::transaction(function () use ($card, $actor) {
            $cards = KanbanCard::where('board_id', $card->board_id)->orderBy('id')->lockForUpdate()->get();

            $card = $cards->firstWhere('id', $card->id);
            if ($card === null) {
                return; // ya la borró otro usuario: idempotente
            }

            $clientId = $card->board->client_id;
            $title = $card->title;
            $card->delete();

            $cards->where('column_id', $card->column_id)->where('id', '!=', $card->id)
                ->sortBy([['position', 'asc'], ['id', 'asc']])->values()
                ->each(function (KanbanCard $c, int $i) {
                    if ($c->position !== $i) {
                        KanbanCard::whereKey($c->id)->update(['position' => $i]);
                    }
                });

            activity('kanban')->causedBy($actor)
                ->withProperties(['client_id' => $clientId, 'title' => $title])->log('Tarjeta eliminada');
        });
    }
}
