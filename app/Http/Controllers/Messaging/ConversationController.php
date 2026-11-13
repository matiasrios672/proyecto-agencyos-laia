<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Messaging\MarkConversationRead;
use App\Actions\Messaging\OpenThread;
use App\Actions\Messaging\StartDirectConversation;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Messaging\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConversationController extends Controller
{
    private const PAGE_SIZE = 30;

    /** Chat directo con un compañero (lo crea si no existe). */
    public function direct(Request $request, StartDirectConversation $start): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $me = $request->user();

        $partner = User::where('is_active', true)->find($data['user_id']);

        if (! $partner || $partner->is($me) || ! $partner->can('messages.use')) {
            throw ValidationException::withMessages(['user_id' => 'Elegí a un compañero activo del equipo.']);
        }

        return $this->json(['conversation' => Presenter::conversation($start->handle($me, $partner), $me)]);
    }

    /** Hilo contextual de un cliente (lo crea si no existe). Requiere poder ver ese cliente. */
    public function thread(Request $request, OpenThread $open): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(Conversation::SUBJECTS))],
            'subject_id' => ['required', 'integer'],
        ]);

        $client = Client::find($data['subject_id']);
        // Mismo 403 si el cliente no existe o no es visible: no se puede explorar qué ids existen.
        abort_unless($client && $request->user()->can('view', $client), 403);

        return $this->json(['conversation' => Presenter::conversation($open->handle($client), $request->user())]);
    }

    /** Detalle: los últimos mensajes (o los anteriores a `before`) y a quién se puede mencionar. */
    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $me = $request->user();
        abort_unless($conversation->isAccessibleBy($me), 403);

        $before = $request->integer('before') ?: null;

        $page = $conversation->messages()->withTrashed()->with('user')
            ->when($before, fn ($q, $id) => $q->where('id', '<', $id))
            ->orderByDesc('id')->limit(self::PAGE_SIZE + 1)->get();

        return $this->json([
            'conversation' => Presenter::conversation($conversation, $me),
            'messages' => Presenter::messages($page->take(self::PAGE_SIZE)->reverse()->values()),
            'has_more' => $page->count() > self::PAGE_SIZE,
            'mentionables' => $conversation->eligibleUsers()->reject(fn (User $u) => $u->is($me))->sortBy('name')
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'job_title' => $u->job_title])->values(),
        ]);
    }

    public function read(Request $request, Conversation $conversation, MarkConversationRead $markRead): JsonResponse
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $markRead->handle($conversation, $request->user());

        return $this->json(['ok' => true]);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
