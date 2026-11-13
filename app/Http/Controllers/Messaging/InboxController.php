<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Messaging\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InboxController extends Controller
{
    /** Página de mensajes. Las conversaciones y los mensajes se cargan por la API JSON (que autoriza cada pedido). */
    public function page(Request $request): Response
    {
        $user = $request->user();
        $selected = $request->integer('c') ?: null;

        // Solo se preselecciona una conversación si la persona tiene acceso (si no, se ignora en silencio).
        if ($selected && ! Conversation::find($selected)?->isAccessibleBy($user)) {
            $selected = null;
        }

        return Inertia::render('Messages/Index', [
            'selected' => $selected,
            'me' => ['id' => $user->id, 'name' => $user->name],
            'teammates' => User::where('is_active', true)->whereKeyNot($user->id)->orderBy('name')->get(['id', 'name', 'job_title'])
                ->filter(fn (User $u) => $u->can('messages.use'))->values()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title]),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->noStore(['conversations' => Inbox::summaries($request->user())]);
    }

    public function unread(Request $request): JsonResponse
    {
        return $this->noStore(['unread' => Inbox::unreadTotal($request->user())]);
    }

    private function noStore(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
