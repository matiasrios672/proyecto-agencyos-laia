<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/** Mensaje nuevo: viaja solo por el canal privado de su conversación (lo escuchan quienes la tienen abierta). */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable;

    public int $tries = 2;

    public int $timeout = 10;

    /** @param  array<string, mixed>  $message  ver App\Support\Messaging\Presenter::message */
    public function __construct(public int $conversationId, public array $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversations.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
