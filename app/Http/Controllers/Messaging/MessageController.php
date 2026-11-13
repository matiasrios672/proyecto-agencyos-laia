<?php

namespace App\Http\Controllers\Messaging;

use App\Actions\Messaging\DeleteMessage;
use App\Actions\Messaging\SendMessage;
use App\Actions\Messaging\UpdateMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\MessageRequest;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Messaging\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function store(MessageRequest $request, Conversation $conversation, SendMessage $send): JsonResponse
    {
        abort_unless($conversation->isAccessibleBy($request->user()), 403);

        $message = $send->handle($conversation, $request->user(), $request->validated('body'), $request->validated('mentions') ?? []);

        return response()->json(['message' => Presenter::message($message)], 201);
    }

    public function update(MessageRequest $request, Message $message, UpdateMessage $update): JsonResponse
    {
        $this->authorizeOwn($request, $message);

        $message = $update->handle($message, $request->user(), $request->validated('body'), $request->validated('mentions') ?? []);

        return response()->json(['message' => Presenter::message($message)]);
    }

    public function destroy(Request $request, Message $message, DeleteMessage $delete): JsonResponse
    {
        $this->authorizeOwn($request, $message);

        $delete->handle($message, $request->user());

        return response()->json(['ok' => true]);
    }

    /** Solo se editan / borran los mensajes propios (ni el sysadmin toca mensajes de otras personas) y mientras siga el acceso. */
    private function authorizeOwn(Request $request, Message $message): void
    {
        abort_unless($message->user_id === $request->user()->id && $message->conversation->isAccessibleBy($request->user()), 403);
    }
}
