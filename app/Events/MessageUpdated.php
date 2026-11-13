<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class MessageUpdated implements ShouldBroadcast
{
    use Dispatchable;

    public int $tries = 2;

    public int $timeout = 10;

    /** @param  array<string, mixed>  $message */
    public function __construct(public int $conversationId, public array $message) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("conversations.{$this->conversationId}")];
    }

    public function broadcastAs(): string
    {
        return 'message.updated';
    }

    public function broadcastWith(): array
    {
        return ['message' => $this->message];
    }
}
