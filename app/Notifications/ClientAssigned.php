<?php

namespace App\Notifications;

use App\Models\Client;
use Illuminate\Notifications\Notification;

/** Aviso interno: te asignaron un cliente. */
class ClientAssigned extends Notification
{
    public function __construct(private readonly Client $client, private readonly string $assignedBy) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'building',
            'title' => 'Te asignaron un cliente',
            'body' => "{$this->assignedBy} te asignó a {$this->client->displayName()}.",
            'url' => "/clientes/{$this->client->id}",
        ];
    }
}
