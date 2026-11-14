<?php

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Notifications\Messages\BroadcastMessage;
use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/** Aviso a quien fue mencionado con @ en un chat o en el hilo de un cliente. */
class MentionedInMessage extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(private readonly Message $message, private readonly string $authorName, private readonly int $conversationId) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', ...$this->mailChannels($notifiable)];
    }

    /** @return array<string, string> */
    public function toArray(object $notifiable): array
    {
        $preview = Str::of($this->message->body)->replaceMatches('/\s+/u', ' ')->limit(90)->toString();

        return [
            'icon' => 'message',
            'title' => 'Te mencionaron',
            'body' => "{$this->authorName}: {$preview}",
            'url' => "/mensajes?c={$this->conversationId}",
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    protected function templateKey(): string
    {
        return 'message.mention';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'autor' => $this->authorName,
            'vista_previa' => Str::of($this->message->body)->replaceMatches('/\s+/u', ' ')->limit(140)->toString(),
            'url' => url("/mensajes?c={$this->conversationId}"),
        ];
    }
}
