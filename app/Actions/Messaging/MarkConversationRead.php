<?php

namespace App\Actions\Messaging;

use App\Events\InboxUpdated;
use App\Models\Conversation;
use App\Models\User;

class MarkConversationRead
{
    public function handle(Conversation $conversation, User $user, bool $notify = true): void
    {
        $lastId = (int) $conversation->messages()->withTrashed()->max('id');

        // En los hilos los participantes son implícitos: la fila de lectura se crea la primera vez que alguien lo abre.
        $conversation->participants()->syncWithoutDetaching([
            $user->id => ['last_read_at' => now(), 'last_read_message_id' => $lastId ?: null],
        ]);

        // Avisa a las demás pestañas de la misma persona para que actualicen sus contadores.
        if ($notify) {
            event(new InboxUpdated($user->id, $conversation->id));
        }
    }
}
