<?php

namespace App\Actions\Messaging;

use App\Events\InboxUpdated;
use App\Events\MessageDeleted;
use App\Models\Message;
use App\Models\User;

class DeleteMessage
{
    public function handle(Message $message, User $author): void
    {
        $conversation = $message->conversation;
        $message->delete();

        // El evento solo lleva ids: el contenido eliminado no vuelve a viajar.
        event(new MessageDeleted($conversation->id, $message->id));
        foreach ($conversation->eligibleUsers()->reject(fn (User $u) => $u->is($author)) as $recipient) {
            event(new InboxUpdated($recipient->id, $conversation->id));
        }
    }
}
