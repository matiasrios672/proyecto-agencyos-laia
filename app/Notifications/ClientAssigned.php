<?php

namespace App\Notifications;

use App\Models\Client;
use App\Notifications\Concerns\SendsTemplateMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Aviso interno: te asignaron un cliente. */
class ClientAssigned extends Notification implements ShouldQueue
{
    use Queueable, SendsTemplateMail;

    public function __construct(private readonly Client $client, private readonly string $assignedBy) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast', ...$this->mailChannels($notifiable)];
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

    protected function templateKey(): string
    {
        return 'assignment.client';
    }

    protected function templateVars(object $notifiable): array
    {
        return [
            'nombre' => $notifiable->name,
            'cliente' => $this->client->displayName(),
            'asignado_por' => $this->assignedBy,
            'url' => url("/clientes/{$this->client->id}"),
        ];
    }
}
