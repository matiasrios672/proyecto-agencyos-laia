<?php

namespace App\Http\Controllers\Kanban;

use App\Actions\Kanban\DeleteCard;
use App\Actions\Kanban\MoveCard;
use App\Actions\Kanban\SaveCard;
use App\Enums\KanbanPriority;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\KanbanBoard;
use App\Models\KanbanCard;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Tablero de tareas de un cliente (Pendientes / Listo / Publicado). */
class KanbanController extends Controller
{
    public function show(Request $request, Client $client): Response
    {
        $user = $request->user();
        // Primero se resuelve el acceso; recién después se crea el tablero si todavía no existe.
        Gate::authorize('view', $client);
        abort_unless($user->can('content.view'), 403);

        $board = $client->ensureBoard();
        Gate::authorize('view', $board);

        $columns = $board->columns()->with(['cards' => fn ($q) => $q->with('assignee:id,name')->orderBy('position')])->get();

        return Inertia::render('Kanban/Board', [
            'client' => ['id' => $client->id, 'name' => $client->displayName()],
            'columns' => $columns->map(fn ($c) => [
                'id' => $c->id,
                'key' => $c->key,
                'name' => $c->name,
                'cards' => $c->cards->map(fn (KanbanCard $card) => $this->present($card))->values(),
            ])->values(),
            'team' => $client->users()->where('users.is_active', true)->orderBy('name')->get(['users.id', 'users.name', 'users.job_title'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title]),
            'priorities' => KanbanPriority::options(),
            'me' => $user->id,
            'can' => ['manage' => $user->can('manage', $board)],
        ]);
    }

    public function store(Request $request, Client $client, SaveCard $save): RedirectResponse
    {
        Gate::authorize('view', $client);
        abort_unless($request->user()->can('content.manage'), 403);

        $board = $client->ensureBoard();
        Gate::authorize('manage', $board);

        $save->create($board, $request->user(), $this->validated($request, withColumn: true));

        return back()->with('success', 'Tarjeta creada.');
    }

    public function update(Request $request, Client $client, KanbanCard $card, SaveCard $save): RedirectResponse
    {
        $this->authorizeCard($client, $card);

        $save->update($card, $request->user(), $this->validated($request));

        return back()->with('success', 'Tarjeta actualizada.');
    }

    /** Devuelve el estado final de las columnas afectadas para que el tablero (optimista) se reconcilie con el servidor. */
    public function move(Request $request, Client $client, KanbanCard $card, MoveCard $move): JsonResponse
    {
        $this->authorizeCard($client, $card);

        $data = $request->validate([
            'column_id' => ['required', 'integer'],
            'position' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $cards = $move->handle($card, (int) $data['column_id'], (int) $data['position'], $request->user());

        return response()->json([
            'cards' => $cards->map(fn (KanbanCard $c) => [
                'id' => $c->id,
                'column_id' => $c->column_id,
                'position' => $c->position,
                'completed_at' => $c->completed_at?->toIso8601String(),
                'overdue' => $c->isOverdue(),
            ])->values(),
        ]);
    }

    public function destroy(Request $request, Client $client, KanbanCard $card, DeleteCard $delete): RedirectResponse
    {
        $this->authorizeCard($client, $card);

        $delete->handle($card, $request->user());

        return back()->with('success', 'Tarjeta eliminada.');
    }

    /**
     * La tarjeta de la URL debe pertenecer al tablero del cliente de la URL (si no, 404: no se pueden tocar
     * tarjetas de otro cliente cambiando ids) y el usuario debe poder gestionar ese tablero.
     */
    private function authorizeCard(Client $client, KanbanCard $card): void
    {
        $card->loadMissing('board.client');
        abort_unless($card->board->client_id === $client->id, 404);

        Gate::authorize('view', $client);
        Gate::authorize('manage', $card->board);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withColumn = false): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'assigned_user_id' => ['nullable', 'integer'],
            'due_date' => ['nullable', 'date'],
            'priority' => ['required', Rule::enum(KanbanPriority::class)],
            ...($withColumn ? ['column_id' => ['nullable', 'integer']] : []),
        ], [], ['title' => 'título', 'due_date' => 'fecha límite', 'priority' => 'prioridad']);
    }

    /** @return array<string, mixed> */
    private function present(KanbanCard $card): array
    {
        return [
            'id' => $card->id,
            'column_id' => $card->column_id,
            'title' => $card->title,
            'description' => $card->description,
            'assignee' => $card->assignee ? ['id' => $card->assignee->id, 'name' => $card->assignee->name] : null,
            'due_date' => $card->due_date?->toDateString(),
            'priority' => $card->priority->value,
            'position' => $card->position,
            'completed_at' => $card->completed_at?->toIso8601String(),
            'overdue' => $card->isOverdue(),
        ];
    }
}
