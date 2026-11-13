<?php

namespace App\Support\Messaging;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Bandeja del usuario: qué conversaciones ve y cuántos mensajes no leídos tiene en cada una. */
class Inbox
{
    /**
     * Conversaciones a las que el usuario tiene acceso:
     * sus chats directos + los hilos (con al menos un mensaje) de los clientes que puede ver.
     *
     * @return Builder<Conversation>
     */
    public static function query(User $user): Builder
    {
        if (! $user->is_active || ! $user->can('messages.use')) {
            return Conversation::query()->whereRaw('1 = 0');
        }

        $threads = $user->can('clients.view') || $user->can('clients.view_all');

        return Conversation::query()->where(function (Builder $q) use ($user, $threads) {
            $q->where(fn (Builder $d) => $d->where('type', Conversation::DIRECT)
                ->whereHas('participants', fn (Builder $p) => $p->whereKey($user->id)));

            if ($threads) {
                $q->orWhere(fn (Builder $t) => $t->where('type', Conversation::THREAD)->where('subject_type', 'client')
                    ->whereNotNull('last_message_at')
                    ->whereIn('subject_id', Client::query()->visibleTo($user)->select('clients.id')));
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public static function summaries(User $user, int $limit = 50): array
    {
        $conversations = self::query($user)->with(['latestMessage.user', 'participants'])
            ->orderByRaw('last_message_at desc nulls last')->orderByDesc('id')->limit($limit)->get();

        $unread = self::unreadFor($user, $conversations->pluck('id')->all());

        return $conversations->map(fn (Conversation $c) => Presenter::conversation($c, $user, $unread[$c->id] ?? 0))->values()->all();
    }

    public static function unreadTotal(User $user): int
    {
        return (int) array_sum(self::unreadFor($user, self::query($user)->pluck('conversations.id')->all()));
    }

    /**
     * No leídos por conversación: mensajes de OTRAS personas, no eliminados, posteriores al último que leyó.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, int>
     */
    public static function unreadFor(User $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        return Message::query()
            ->selectRaw('messages.conversation_id, count(*) as total')
            ->leftJoin('conversation_user as cu', fn ($j) => $j->on('cu.conversation_id', '=', 'messages.conversation_id')->where('cu.user_id', $user->id))
            ->whereIn('messages.conversation_id', $conversationIds)
            ->where('messages.user_id', '!=', $user->id)
            ->where(fn ($w) => $w->whereNull('cu.last_read_message_id')->orWhereColumn('messages.id', '>', 'cu.last_read_message_id'))
            ->groupBy('messages.conversation_id')
            ->pluck('total', 'messages.conversation_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}
