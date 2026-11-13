<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pista liviana para la bandeja y el contador de una persona: "algo cambió en esta conversación".
 * Solo lleva el id (sin contenido): el navegador vuelve a pedir la bandeja por la API, que sí autoriza.
 */
class InboxUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public int $tries = 2;

    public int $timeout = 10;

    public function __construct(public int $userId, public int $conversationId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}
