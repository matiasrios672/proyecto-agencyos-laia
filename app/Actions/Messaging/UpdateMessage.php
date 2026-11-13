<?php

namespace App\Actions\Messaging;

use App\Events\InboxUpdated;
use App\Events\MessageUpdated;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MentionedInMessage;
use App\Support\Messaging\Presenter;

class UpdateMessage
{
    /** @param  list<int>  $mentionIds */
    public function handle(Message $message, User $author, string $body, array $mentionIds = []): Message
    {
        $conversation = $message->conversation;
        $body = SendMessage::clean($body);
        $eligible = $conversation->eligibleUsers();
        $mentions = SendMessage::validMentions($eligible->pluck('id')->all(), $author, $mentionIds);
        $new = array_values(array_diff($mentions, $message->mentions ?? []));

        $message->forceFill(['body' => $body, 'mentions' => $mentions, 'edited_at' => now()])->save();
        $message->setRelation('user', $author);

        event(new MessageUpdated($conversation->id, Presenter::message($message)));
        foreach ($eligible->reject(fn (User $u) => $u->is($author)) as $recipient) {
            event(new InboxUpdated($recipient->id, $conversation->id));
        }

        // Solo se avisa a quienes se mencionan por primera vez (editar no vuelve a notificar a los de antes).
        if ($new !== []) {
            User::whereIn('id', $new)->get()->each(fn (User $u) => $u->notify(new MentionedInMessage($message, $author->name, $conversation->id)));
        }

        return $message;
    }
}
