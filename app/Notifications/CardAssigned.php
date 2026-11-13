<?php

namespace App\Notifications;

use App\Models\Client;
use App\Models\KanbanCard;
use Illuminate\Notifications\Notification;

/** Aviso interno: te asignaron una tarjeta del tablero de un cliente. */
class CardAssigned extends Notification
{
    public function __construct(
        private readonly KanbanCard $card,
        private readonly Client $client,
        private readonly string $assignedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'bell',
            'title' => 'Te asignaron una tarjeta',
            'body' => "{$this->assignedBy} te asignó «{$this->card->title}» en {$this->client->displayName()}.",
            'url' => "/clientes/{$this->client->id}/tablero",
        ];
    }
}
