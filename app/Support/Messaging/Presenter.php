<?php

namespace App\Support\Messaging;

use App\Models\Client;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Forma de los datos que viajan al navegador (respuestas JSON y eventos de broadcast).
 * Solo lo necesario: nunca se arrastran modelos completos.
 */
class Presenter
{
    /** @param  Collection<int, Message>|null  $batch  si se pasa, los nombres de las menciones se resuelven en una sola consulta */
    public static function message(Message $message, ?Collection $batch = null, ?array $names = null): array
    {
        $deleted = $message->trashed();
        $ids = $deleted ? [] : ($message->mentions ?? []);

        if ($names === null) {
            $names = $ids === [] ? [] : User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();
        }

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'user' => ['id' => $message->user_id, 'name' => $message->user?->name ?? 'Usuario'],
            // Un mensaje eliminado no entrega su contenido ni sus menciones.
            'body' => $deleted ? null : $message->body,
            'deleted' => $deleted,
            'mentions' => collect($ids)->filter(fn ($id) => isset($names[$id]))->map(fn ($id) => ['id' => $id, 'name' => $names[$id]])->values()->all(),
            'edited' => ! $deleted && $message->edited_at !== null,
            'created_at' => $message->created_at->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public static function messages(Collection $messages): array
    {
        $ids = $messages->reject->trashed()->flatMap(fn (Message $m) => $m->mentions ?? [])->unique()->values()->all();
        $names = $ids === [] ? [] : User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $messages->map(fn (Message $m) => self::message($m, null, $names))->values()->all();
    }

    /** Resumen de una conversación para la bandeja (título, contexto, último mensaje y no leídos del usuario). */
    public static function conversation(Conversation $conversation, User $viewer, ?int $unread = null): array
    {
        $other = null;
        $subject = null;

        if ($conversation->isDirect()) {
            $partner = $conversation->participants->firstWhere('id', '!=', $viewer->id) ?? $conversation->participants()->where('users.id', '!=', $viewer->id)->first();
            $other = $partner ? ['id' => $partner->id, 'name' => $partner->name, 'job_title' => $partner->job_title, 'active' => (bool) $partner->is_active] : null;
        } elseif ($client = $conversation->subject()) {
            /** @var Client $client */
            $subject = ['type' => $conversation->subject_type, 'id' => $client->id, 'name' => $client->displayName(), 'url' => "/clientes/{$client->id}"];
        }

        $last = $conversation->relationLoaded('latestMessage') ? $conversation->latestMessage : $conversation->latestMessage()->with('user')->first();

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $other['name'] ?? ($subject ? "Hilo · {$subject['name']}" : 'Conversación'),
            'subject' => $subject,
            'other' => $other,
            'last' => $last ? [
                'preview' => Str::of($last->body)->replaceMatches('/\s+/u', ' ')->limit(90)->toString(),
                'author' => $last->user?->name,
                'mine' => $last->user_id === $viewer->id,
                'at' => $last->created_at->toIso8601String(),
            ] : null,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'unread' => $unread ?? Inbox::unreadFor($viewer, [$conversation->id])[$conversation->id] ?? 0,
        ];
    }
}
