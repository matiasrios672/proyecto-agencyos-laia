<?php

namespace App\Actions\Messaging;

use App\Events\InboxUpdated;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use App\Support\Messaging\Presenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendMessage
{
    /**
     * Enviar NO marca la conversación como leída: pudieron llegar mensajes de otras personas mientras se escribía.
     * La interfaz marca como leído (POST …/leer) cuando la conversación está a la vista. Los mensajes propios
     * nunca cuentan como no leídos.
     *
     * @param  list<int>  $mentionIds  ids que propone el cliente: el servidor se queda solo con los que tienen acceso
     */
    public function handle(Conversation $conversation, User $author, string $body, array $mentionIds = []): Message
    {
        $body = self::clean($body);
        $eligible = $conversation->eligibleUsers();
        $mentions = self::validMentions($eligible->pluck('id')->all(), $author, $mentionIds);

        $message = DB::transaction(function () use ($conversation, $author, $body, $mentions) {
            $message = $conversation->messages()->create(['user_id' => $author->id, 'body' => $body, 'mentions' => $mentions]);
            $conversation->forceFill(['last_message_at' => $message->created_at])->save();

            return $message;
        });

        $message->setRelation('user', $author);

        event(new MessageSent($conversation->id, Presenter::message($message)));
        foreach ($eligible->reject(fn (User $u) => $u->is($author)) as $recipient) {
            event(new InboxUpdated($recipient->id, $conversation->id));
        }

        if ($mentions !== []) {
            User::whereIn('id', $mentions)->get()->each(fn (User $u) => $u->notify(new MentionedInMessage($message, $author->name, $conversation->id)));
        }

        return $message;
    }

    /** Texto plano: sin caracteres de control (salvo saltos de línea y tabs), recortado y no vacío. */
    public static function clean(string $body): string
    {
        $body = trim((string) preg_replace('/[^\P{C}\n\t]/u', '', str_replace("\r\n", "\n", $body)));

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Escribí un mensaje.']);
        }
        if (mb_strlen($body) > Message::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'El mensaje es demasiado largo (máximo '.Message::MAX_LENGTH.' caracteres).']);
        }

        return $body;
    }

    /**
     * Solo cuentan las menciones de personas con acceso a la conversación; nunca a uno mismo y sin repetidos.
     *
     * @param  list<int>  $eligibleIds
     * @param  array<int, mixed>  $proposed
     * @return list<int>
     */
    public static function validMentions(array $eligibleIds, User $author, array $proposed): array
    {
        return collect($proposed)->map(fn ($id) => (int) $id)->unique()
            ->filter(fn (int $id) => $id !== $author->id && in_array($id, $eligibleIds, true))
            ->sort()->values()->all();
    }
}
