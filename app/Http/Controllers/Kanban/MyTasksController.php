<?php

namespace App\Http\Controllers\Kanban;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\KanbanCard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "Mis tareas": tarjetas pendientes asignadas a mí en todos los clientes que puedo ver, agrupadas por vencimiento. */
class MyTasksController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $cards = KanbanCard::query()
            ->where('assigned_user_id', $user->id)
            ->whereNull('completed_at')
            // Solo clientes que el usuario puede ver hoy (si lo desasignaron del cliente, esas tarjetas no se muestran).
            ->whereHas('board.client', fn ($q) => $q->visibleTo($user)->where('is_active', true))
            ->with(['board.client:id,business_name,trade_name', 'column:id,name'])
            ->orderByRaw('due_date asc nulls last')->orderBy('id')
            ->get();

        $today = today();
        $endOfWeek = $today->copy()->endOfWeek();

        $bucket = fn (KanbanCard $c): string => match (true) {
            $c->due_date === null => 'sin_fecha',
            $c->due_date->lt($today) => 'vencidas',
            $c->due_date->isSameDay($today) => 'hoy',
            $c->due_date->lte($endOfWeek) => 'semana',
            default => 'mas_adelante',
        };

        $labels = [
            'vencidas' => 'Vencidas',
            'hoy' => 'Para hoy',
            'semana' => 'Esta semana',
            'mas_adelante' => 'Más adelante',
            'sin_fecha' => 'Sin fecha',
        ];
        $grouped = $cards->groupBy($bucket);

        return Inertia::render('Kanban/MyTasks', [
            'groups' => collect($labels)->map(fn (string $label, string $key) => [
                'key' => $key,
                'label' => $label,
                'cards' => ($grouped[$key] ?? collect())->map(fn (KanbanCard $c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'priority' => $c->priority->value,
                    'due_date' => $c->due_date?->toDateString(),
                    'overdue' => $c->isOverdue(),
                    'column' => $c->column->name,
                    'client' => ['id' => $c->board->client->id, 'name' => $c->board->client->displayName()],
                ])->values(),
            ])->values(),
            'total' => $cards->count(),
        ]);
    }
}
