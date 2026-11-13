<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Mensaje eliminado: solo viajan los ids, nunca el contenido. */
class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable;

    public int $tries = 2;

    public int $timeout = 10;

    public function __construct(public int $conversationId, public int $messageId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversations.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'id' => $this->messageId];
    }
}
